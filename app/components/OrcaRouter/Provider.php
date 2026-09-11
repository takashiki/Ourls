<?php

namespace app\components\OrcaRouter;

/**
 * Inference adapter.
 *
 * One code path for both credential sources — it receives a Credential and
 * never learns where the key came from. Every call carries the generation that
 * produced it so a `401` can be attributed to the exact credential that made
 * the rejected request.
 */
class Provider
{
    const PROVIDER_ID = 'orcarouter';

    private $origins;
    private $store;

    public function __construct(Origins $origins, SecretStore $store)
    {
        $this->origins = $origins;
        $this->store = $store;
    }

    /**
     * Send a chat completion.
     *
     * @param array      $messages   OpenAI wire messages; content may be a
     *                               string or an array of typed parts
     * @param array      $modalities non-text input modalities this request carries
     *
     * @return array {content:string, model:string, usage:array}
     *
     * @throws OrcaRouterException
     */
    public function chat(Credential $credential, $model, array $messages, array $modalities = array(), $maxTokens = null)
    {
        if (!$credential->isUsable()) {
            throw new OrcaRouterException(
                'The saved OrcaRouter credential needs to be reconnected before it can be used.',
                401
            );
        }
        $model = trim((string) $model);
        if ($model === '') {
            throw new OrcaRouterException('Choose an OrcaRouter model first.', 400);
        }
        if (!$messages) {
            throw new OrcaRouterException('Enter a prompt first.', 400);
        }

        $payload = array('model' => $model, 'messages' => array_values($messages));
        if ($maxTokens !== null) {
            $payload['max_tokens'] = (int) $maxTokens;
        }

        $response = Http::postJson(
            $this->origins->chatUrl(),
            $payload,
            array($credential->bearerHeader())
        );

        if ($response['error'] !== null) {
            throw new OrcaRouterException('Could not reach OrcaRouter. Check your network and try again.', 0);
        }

        if ($response['status'] === 401 || $response['status'] === 403) {
            // Terminal reauthentication for the exact credential generation
            // that made this request. No refresh attempt is made: an OrcaRouter
            // key is durable, not a refreshable token.
            $this->store->markNeedsReauth($credential->accountId(), $credential->generation());

            throw new OrcaRouterException(
                'OrcaRouter rejected this credential. Reconnect the account or paste a new API key.',
                401
            );
        }
        if ($response['status'] === 429) {
            throw new OrcaRouterException('OrcaRouter is rate limiting this account. Try again shortly.', 429);
        }
        if ($response['status'] !== 200) {
            throw new OrcaRouterException(
                'OrcaRouter returned HTTP '.(int) $response['status'].' for this request.',
                $response['status']
            );
        }

        $body = Http::decode($response['body']);
        if (!is_array($body) || !isset($body['choices']) || !is_array($body['choices']) || !$body['choices']) {
            throw new OrcaRouterException('OrcaRouter returned an unexpected response shape.', 502);
        }

        $choice = $body['choices'][0];
        $content = isset($choice['message']['content']) ? $choice['message']['content'] : '';
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
            $content = $text;
        }

        return array(
            'content' => (string) $content,
            'model' => isset($body['model']) ? $body['model'] : $model,
            'usage' => isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : array(),
        );
    }

    /** Minimal non-billing probe used to show whether the saved key is accepted. */
    public function probe(Credential $credential)
    {
        $response = Http::getJson(
            $this->origins->modelsUrl(ModelCatalog::CAP_CHAT),
            array($credential->bearerHeader()),
            ModelCatalog::TIMEOUT,
            ModelCatalog::MAX_BYTES
        );

        if ($response['error'] !== null) {
            return array('state' => 'unreachable', 'detail' => 'network error');
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            $this->store->markNeedsReauth($credential->accountId(), $credential->generation());

            return array('state' => 'rejected', 'detail' => 'HTTP '.(int) $response['status']);
        }
        if ($response['status'] === 200) {
            return array('state' => 'accepted', 'detail' => null);
        }

        return array('state' => 'unknown', 'detail' => 'HTTP '.(int) $response['status']);
    }
}
