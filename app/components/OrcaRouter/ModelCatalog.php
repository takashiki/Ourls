<?php

namespace app\components\OrcaRouter;

/**
 * Capability-filtered model catalog.
 *
 * The single source of truth is `GET {api}/models` under the configured API
 * origin. That response is authoritative when it succeeds; the verified seed
 * below is only a bounded cold-start / outage fallback and is never merged
 * into a successful live result.
 *
 * Filtering is done on catalog metadata only. Model ids are never inspected to
 * guess a capability, and a model that does not *declare* a non-text input
 * modality is excluded from that modality's list (fail closed).
 */
class ModelCatalog
{
    const CAP_CHAT = 'chat';
    const CAP_EMBEDDING = 'embedding';
    const CAP_IMAGE = 'image';
    const CAP_VIDEO = 'video';
    const CAP_RERANK = 'rerank';

    const SOURCE_LIVE = 'live';
    const SOURCE_FALLBACK = 'fallback';

    /** Bounds so a hostile or broken catalog cannot exhaust the process. */
    const TIMEOUT = 10;
    const MAX_BYTES = 4194304;
    const MAX_ITEMS = 400;
    const MAX_ID_LENGTH = 200;

    /** Endpoint types this client can actually speak for plain text chat. */
    private static $textEndpoints = array('openai', 'anthropic', 'gemini', 'openai-response');

    /** Endpoint types that must never appear in a text-chat list. */
    private static $nonTextEndpoints = array('image-generation', 'openai-video', 'jina-rerank', 'embeddings');

    /**
     * Server-side discovery gate per capability.
     *
     * Text chat, embedding and image generation must be narrowed by the
     * catalog's own `?capability=` filter *before* any local rule runs. This is
     * not an optimisation: the catalog records for speech-synthesis models
     * (`openai/tts-1`, `google/gemini-2.5-flash-preview-tts`, …) are
     * indistinguishable from text chat models — they advertise the `openai`
     * endpoint type and a `["text"]` input modality and declare no output
     * modality at all. Only the server-side filter separates them, so the local
     * rules below are a second layer, never the only one.
     *
     * Video and rerank have no server-side capability name (both answer with an
     * empty set), so those are discovered unfiltered and narrowed locally by
     * strict endpoint-type match.
     */
    private static $serverCapability = array(
        self::CAP_CHAT => 'chat',
        self::CAP_EMBEDDING => 'embedding',
        self::CAP_IMAGE => 'image',
        self::CAP_VIDEO => null,
        self::CAP_RERANK => null,
    );

    private $origins;
    private $cache = array();

    public function __construct(Origins $origins, $cache = null)
    {
        $this->origins = $origins;
        if (is_array($cache)) {
            $this->cache = $cache;
        }
    }

    /**
     * Raw catalog records for a capability, before the local filter runs.
     *
     * Filtering is deliberately NOT done here: the records are normalised by
     * filter(), so filtering twice would strip the architecture metadata the
     * second pass needs and silently empty every modality-scoped list.
     *
     * @return array {records:array, source:string, error:?string, url:string}
     */
    public function fetch($capability, Credential $credential = null, array $inputModalities = array())
    {
        $url = $this->origins->modelsUrl($this->serverCapabilityFor($capability));

        // One network call per distinct catalog URL, capability and credential
        // generation per request. The modality set is part of the key: the
        // filter below is modality-scoped, so serving a modality-constrained
        // lookup from an unfiltered cache entry would return models the caller
        // cannot use.
        $cacheKey = $url.'|'.$capability.'|'.($credential ? $credential->generation() : 'anon')
            .'|'.implode(',', $this->normaliseModalities($inputModalities));
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $response = Http::getJson($url, $credential ? array($credential->bearerHeader()) : array(), self::TIMEOUT, self::MAX_BYTES);

        if ($response['error'] === null && $response['status'] === 200) {
            $decoded = Http::decode($response['body']);
            if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                return $this->cache[$cacheKey] = array(
                    'records' => $decoded['data'],
                    'source' => self::SOURCE_LIVE,
                    'error' => null,
                    'truncated' => !empty($response['truncated']),
                    'url' => $url,
                );
            }
        }

        return $this->cache[$cacheKey] = array(
            'records' => null,
            'source' => self::SOURCE_FALLBACK,
            'error' => $this->describeFailure($response),
            'truncated' => false,
            'url' => $url,
        );
    }

    /** Sorted, de-duplicated modality list used for cache keys and filtering. */
    private function normaliseModalities(array $inputModalities)
    {
        $modalities = array();
        foreach ($inputModalities as $modality) {
            if (is_string($modality) && $modality !== '') {
                $modalities[] = $modality;
            }
        }
        $modalities = array_values(array_unique($modalities));
        sort($modalities);

        return $modalities;
    }

    /** The `?capability=` value to send, or null to discover unfiltered. */
    public function serverCapabilityFor($capability)
    {
        return isset(self::$serverCapability[$capability]) ? self::$serverCapability[$capability] : null;
    }

    /**
     * The authoritative model list for a capability.
     *
     * Live discovery wins outright when it succeeds — the verified seed is only
     * used when the catalog is unavailable, and it is passed through the same
     * filter so it can never advertise a capability the client cannot use.
     *
     * @return array {models:array, source:string, error:?string, count:int, url:string}
     */
    public function listFor($capability, Credential $credential = null, array $inputModalities = array())
    {
        $result = $this->fetch($capability, $credential, $inputModalities);

        // The relaxation only applies when this capability was actually
        // narrowed by the catalog's own ?capability= gate.
        $serverGated = $this->serverCapabilityFor($capability) !== null;

        $models = $result['source'] === self::SOURCE_LIVE
            ? $this->filter($result['records'], $capability, $inputModalities, $serverGated)
            : $this->seed($capability, $inputModalities);

        return array(
            'models' => $models,
            'source' => $result['source'],
            'error' => $result['error'],
            'count' => count($models),
            'truncated' => $result['truncated'],
            'url' => $result['url'],
        );
    }

    /**
     * Apply the capability filter to raw catalog records.
     *
     * @param array  $raw             catalog `data` array
     * @param string $capability      one of the CAP_* constants
     * @param array  $inputModalities non-text modalities the caller actually uploads
     * @param bool   $serverGated     true when $raw came from a `?capability=` response
     */
    public function filter(array $raw, $capability, array $inputModalities = array(), $serverGated = false)
    {
        $models = array();
        $seen = array();
        $count = 0;

        foreach ($raw as $item) {
            if (++$count > self::MAX_ITEMS) {
                break;
            }
            if (!is_array($item) || !isset($item['id']) || !is_string($item['id'])) {
                continue;
            }
            $id = trim($item['id']);
            if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }

            $endpoints = $this->endpoints($item);
            if (!$this->matches($capability, $endpoints, $this->inputModalities($item), $inputModalities, $serverGated)) {
                continue;
            }

            $seen[$id] = true;
            $models[] = $this->normalise($item, $id, $endpoints);
        }

        return $models;
    }

    /** True when a catalog record declares every capability the caller needs. */
    public function matches($capability, array $endpoints, array $declaredModalities, array $requiredModalities = array(), $serverGated = false)
    {
        /*
         * Endpoint types are absent on part of the catalog: six records the
         * server's own ?capability=chat gate returns — including
         * openai/gpt-oss-120b, which served a live inference request during this
         * integration — carry no `supported_endpoint_types` field at all.
         *
         * An absent field is missing information, not a false claim, so when the
         * server gate has already asserted the capability the record is accepted
         * and the absence is reported as such. When the list was NOT server
         * gated (video, rerank) or the record does declare routes, the text
         * endpoint requirement is enforced exactly as written.
         */
        $undeclared = count($endpoints) === 0;

        switch ($capability) {
            case self::CAP_CHAT:
                // A record that declares a non-text-only route is not a text
                // chat model, whatever else it advertises.
                if (array_intersect($endpoints, self::$nonTextEndpoints)) {
                    return false;
                }
                if (!$undeclared && !array_intersect($endpoints, self::$textEndpoints)) {
                    return false;
                }
                if ($undeclared && !$serverGated) {
                    return false;
                }
                break;

            case self::CAP_EMBEDDING:
                if (!in_array('embeddings', $endpoints, true) && !($undeclared && $serverGated)) {
                    return false;
                }
                break;

            case self::CAP_IMAGE:
                if (!in_array('image-generation', $endpoints, true) && !($undeclared && $serverGated)) {
                    return false;
                }
                break;

            case self::CAP_VIDEO:
                if (!in_array('openai-video', $endpoints, true) && !($undeclared && $serverGated)) {
                    return false;
                }
                break;

            case self::CAP_RERANK:
                if (!in_array('jina-rerank', $endpoints, true) && !($undeclared && $serverGated)) {
                    return false;
                }
                break;

            default:
                return false;
        }

        // Multimodal understanding: chat first, then the model must *declare*
        // each non-text modality the caller uploads. An undeclared capability
        // is a rejection, not a maybe.
        foreach ($requiredModalities as $modality) {
            if ($modality === 'text') {
                continue;
            }
            if (!in_array($modality, $declaredModalities, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalise(array $item, $id, array $endpoints)
    {
        $architecture = isset($item['architecture']) && is_array($item['architecture']) ? $item['architecture'] : array();
        $record = array(
            'id' => $id,
            // The vendor/model namespace is preserved verbatim.
            'name' => isset($item['name']) && is_string($item['name']) ? $item['name'] : $id,
            'supported_endpoint_types' => array_values($endpoints),
            'endpoint_types_declared' => count($endpoints) > 0,
            'input_modalities' => $this->modalitiesFrom($architecture),
            'output_modalities' => isset($architecture['output_modalities']) && is_array($architecture['output_modalities'])
                ? array_values(array_map('strval', $architecture['output_modalities']))
                : array(),
        );

        if (isset($item['context_length']) && is_numeric($item['context_length'])) {
            $record['context_length'] = (int) $item['context_length'];
        }
        if (isset($item['max_completion_tokens']) && is_numeric($item['max_completion_tokens'])) {
            $record['max_completion_tokens'] = (int) $item['max_completion_tokens'];
        }
        if (isset($item['architecture']['reasoning']) || isset($item['reasoning'])) {
            $record['reasoning'] = true;
        }
        if (isset($item['reasoning_efforts']) && is_array($item['reasoning_efforts'])) {
            $record['reasoning_efforts'] = array_values(array_map('strval', $item['reasoning_efforts']));
        }

        return $record;
    }

    private function endpoints(array $item)
    {
        if (!isset($item['supported_endpoint_types']) || !is_array($item['supported_endpoint_types'])) {
            return array();
        }
        $endpoints = array();
        foreach ($item['supported_endpoint_types'] as $type) {
            if (is_string($type) && $type !== '') {
                $endpoints[] = $type;
            }
        }

        return $endpoints;
    }

    private function inputModalities(array $item)
    {
        if (!isset($item['architecture']) || !is_array($item['architecture'])) {
            return array();
        }

        return $this->modalitiesFrom($item['architecture']);
    }

    private function modalitiesFrom(array $architecture)
    {
        if (!isset($architecture['input_modalities']) || !is_array($architecture['input_modalities'])) {
            return array();
        }

        return array_values(array_filter(array_map('strval', $architecture['input_modalities'])));
    }

    private function describeFailure(array $response)
    {
        if ($response['error'] !== null) {
            return 'OrcaRouter model catalog is unreachable; showing the verified fallback list.';
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            return 'OrcaRouter rejected the saved key while listing models; showing the verified fallback list.';
        }

        return 'OrcaRouter model catalog returned HTTP '.(int) $response['status'].'; showing the verified fallback list.';
    }

    /**
     * Verified fallback catalog.
     *
     * Every entry below was confirmed present in the live catalog on
     * 2026-09-11, and each carries the endpoint types and input modalities the
     * live catalog advertised for it. `openai/gpt-5.5` additionally carries its
     * verified reasoning-effort ladder. This list is only reachable when live
     * discovery fails, and it is filtered by the same rules so it can never
     * advertise a capability the client cannot use.
     */
    public static function verifiedSeed()
    {
        return array(
            array(
                'id' => 'openai/gpt-5.5',
                'name' => 'OpenAI: GPT-5.5',
                'supported_endpoint_types' => array('openai', 'openai-response'),
                'architecture' => array(
                    'input_modalities' => array('text', 'image', 'file'),
                    'output_modalities' => array('text'),
                    'reasoning' => true,
                ),
                'reasoning_efforts' => array('low', 'medium', 'high', 'xhigh'),
            ),
            array(
                'id' => 'anthropic/claude-opus-4.8',
                'name' => 'Anthropic: Claude Opus 4.8',
                'supported_endpoint_types' => array('openai', 'anthropic', 'openai-response'),
                'architecture' => array(
                    'input_modalities' => array('text', 'image', 'file'),
                    'output_modalities' => array('text'),
                ),
                'context_length' => 1000000,
            ),
            array(
                'id' => 'google/gemini-3.5-flash',
                'name' => 'Google: Gemini 3.5 Flash',
                'supported_endpoint_types' => array('openai', 'gemini'),
                'architecture' => array(
                    'input_modalities' => array('text', 'image', 'video', 'file', 'audio'),
                    'output_modalities' => array('text'),
                ),
                'context_length' => 1048576,
            ),
            array(
                'id' => 'deepseek/deepseek-v4-pro',
                'name' => 'DeepSeek: DeepSeek V4 Pro',
                'supported_endpoint_types' => array('openai', 'openai-response'),
                'architecture' => array(
                    'input_modalities' => array('text'),
                    'output_modalities' => array('text'),
                ),
                'context_length' => 1048576,
            ),
            array(
                'id' => 'orcarouter/auto',
                'name' => 'OrcaRouter: Auto',
                'supported_endpoint_types' => array('openai', 'openai-response', 'anthropic', 'gemini'),
                'architecture' => array(),
            ),
        );
    }

    /**
     * The verified seed, put through the same filter as live data.
     *
     * The seed is a hand-checked list, so every entry declares its routes and
     * the undeclared-route relaxation never applies to it.
     */
    public function seed($capability, array $inputModalities = array())
    {
        return $this->filter(self::verifiedSeed(), $capability, $inputModalities, false);
    }

    /** True when $modelId is still offered for this capability. */
    public function isSelectable($modelId, $capability, array $inputModalities = array())
    {
        foreach ($this->seed($capability, $inputModalities) as $model) {
            if ($model['id'] === $modelId) {
                return true;
            }
        }

        return false;
    }
}
