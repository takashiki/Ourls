<?php

namespace app\components\OrcaRouter;

/**
 * OAuth 2.0 Authorization Code grant with PKCE (RFC 7636), out-of-band mode.
 *
 * Flow B is used because Ourls is self-hosted software whose public address
 * differs on every deployment, so there is no predictable redirect target to
 * name in `callback_url`. Flow A's loopback listener is also structurally
 * unavailable here: the application is a PHP request handler with no process
 * that survives the authorize/exchange boundary.
 *
 * Security properties enforced in this class:
 *   - the verifier is 32 bytes of CSPRNG output, fresh for every attempt;
 *   - only the unpadded base64url SHA-256 challenge is ever put on the wire;
 *   - the verifier is persisted server-side and never leaves the process until
 *     the exchange, so it cannot reach a URL, a log, or the browser;
 *   - `state` is compared in constant time before a code is redeemed;
 *   - a pending attempt is consumed before the exchange, so a code cannot be
 *     replayed, and an expired attempt cannot be resurrected.
 */
class PkceFlow
{
    const APP_NAME = 'Ourls';
    const SCOPE = 'api';
    const CALLBACK_URL = 'oob';
    const CHALLENGE_METHOD = 'S256';

    /** Authorization codes are single-use with a 10 minute TTL. */
    const ATTEMPT_TTL = 600;
    const PENDING_STATE_KEY = 'pkce_pending';
    /**
     * Monotonic attempt counter.
     *
     * It must never reset: the pending record is deleted on every terminal path
     * (cancel, denial, expiry, reuse), so deriving the number from it would make
     * attempt 1 recur. LoginLock::release() is attempt-scoped to stop a late
     * cancel from an abandoned page clearing a newer sign-in, and that guard is
     * only sound while attempt numbers are unique for the life of the store.
     */
    const SEQUENCE_KEY = 'pkce_attempt_seq';

    private $origins;
    private $store;
    private $clock;

    public function __construct(Origins $origins, SecretStore $store, $clock = null)
    {
        $this->origins = $origins;
        $this->store = $store;
        $this->clock = $clock ?: function () {
            return time();
        };
    }

    /**
     * Start an authorization attempt.
     *
     * @return array {authorize_url:string, attempt:int, state:string, expires_at:int}
     */
    public function begin($appName = self::APP_NAME, $scope = self::SCOPE)
    {
        $attempt = ((int) $this->store->getState(self::SEQUENCE_KEY, 0)) + 1;
        $this->store->putState(self::SEQUENCE_KEY, $attempt);

        $verifier = Random::token(32);
        $state = Random::token(16);
        $challenge = self::challenge($verifier);

        $pending = array(
            'attempt' => $attempt,
            'verifier' => $verifier,
            'state' => $state,
            'challenge' => $challenge,
            'app_name' => $appName,
            'scope' => $scope,
            'started_at' => $this->now(),
            'expires_at' => $this->now() + self::ATTEMPT_TTL,
        );
        $this->store->putState(self::PENDING_STATE_KEY, $pending);

        // The URL carries the challenge and state only. The verifier is not in
        // it and never will be.
        $url = $this->origins->authorizeUrl().'?'.http_build_query(array(
            'callback_url' => self::CALLBACK_URL,
            'code_challenge' => $challenge,
            'code_challenge_method' => self::CHALLENGE_METHOD,
            'state' => $state,
            'app_name' => $appName,
            'scope' => $scope,
        ), '', '&', PHP_QUERY_RFC3986);

        return array(
            'authorize_url' => $url,
            'attempt' => $attempt,
            'state' => $state,
            'expires_at' => $pending['expires_at'],
            'scope' => $scope,
        );
    }

    /** The pending attempt, or null. The verifier is included for the exchange path only. */
    public function pending()
    {
        return $this->store->getState(self::PENDING_STATE_KEY);
    }

    /**
     * Redeem the code the consent screen displayed.
     *
     * @param string      $code          code from the consent screen
     * @param string|null $expectedState when a transport echoed the state back
     *                                   (callback-style integration), it is
     *                                   compared in constant time first
     *
     * @return array {key:string, scope:?string, user_id:?string, requested_scope:string}
     *
     * @throws OrcaRouterException
     */
    public function exchange($code, $expectedState = null)
    {
        $pending = $this->store->getState(self::PENDING_STATE_KEY);
        if (!$pending) {
            throw new OrcaRouterException(
                'No OrcaRouter authorization is in progress. Start the sign-in again.',
                409
            );
        }

        // Every terminal path below releases the attempt, so a failure never
        // leaves the panel stuck.
        if ($this->now() > (int) $pending['expires_at']) {
            $this->store->forgetState(self::PENDING_STATE_KEY);
            throw new OrcaRouterException(
                'That OrcaRouter authorization expired. Start the sign-in again.',
                410
            );
        }

        if ($expectedState !== null && !hash_equals((string) $pending['state'], (string) $expectedState)) {
            $this->store->forgetState(self::PENDING_STATE_KEY);
            throw new OrcaRouterException(
                'The OrcaRouter authorization response did not match this session. Start the sign-in again.',
                400
            );
        }

        // Single use: consume the attempt before the network call, so a code
        // that is retried, or a concurrent redemption, cannot succeed twice.
        $this->store->forgetState(self::PENDING_STATE_KEY);

        $code = trim((string) $code);
        if ($code === '') {
            throw new OrcaRouterException('Enter the authorization code shown on the OrcaRouter consent screen.', 400);
        }

        $response = Http::postJson($this->origins->exchangeUrl(), array(
            'code' => $code,
            'code_verifier' => $pending['verifier'],
            'code_challenge_method' => self::CHALLENGE_METHOD,
        ));

        if ($response['error'] !== null) {
            throw new OrcaRouterException(
                'Could not reach OrcaRouter to finish signing in. Check your network and try again.',
                0
            );
        }

        return $this->interpret($response, $pending);
    }

    /** Abandon the current attempt. Safe to call when none exists. */
    public function cancel()
    {
        $this->store->forgetState(self::PENDING_STATE_KEY);

        return true;
    }

    public function authorizeUrl()
    {
        return $this->origins->authorizeUrl();
    }

    private function interpret(array $response, array $pending)
    {
        $status = $response['status'];
        $body = Http::decode($response['body']);

        if ($status === 200 && is_array($body) && isset($body['key']) && $body['key'] !== '') {
            $requested = isset($pending['scope']) ? $pending['scope'] : self::SCOPE;
            // The response says what was *granted*, not what was asked for.
            $granted = isset($body['scope']) ? $body['scope'] : null;

            return array(
                'key' => $body['key'],
                'scope' => $granted,
                'user_id' => isset($body['user_id']) ? $body['user_id'] : null,
                'requested_scope' => $requested,
                'downgraded' => $granted !== null && $granted !== $requested,
            );
        }

        if ($status === 400) {
            throw new OrcaRouterException(
                'OrcaRouter rejected the authorization code challenge. Start the sign-in again.',
                400
            );
        }
        if ($status === 403) {
            throw new OrcaRouterException(
                'That OrcaRouter code is unknown, already used, or expired. Start the sign-in again.',
                403
            );
        }
        if ($status === 429) {
            throw new OrcaRouterException(
                'OrcaRouter is rate limiting new authorizations for this account (10 per 24 hours). '
                .'Use an existing API key, or try again later.',
                429
            );
        }

        throw new OrcaRouterException(
            'OrcaRouter could not complete the sign-in (HTTP '.(int) $status.'). Try again in a moment.',
            $status
        );
    }

    /** base64url(sha256(verifier)), no padding. */
    public static function challenge($verifier)
    {
        return Random::b64url(hash('sha256', $verifier, true));
    }

    private function now()
    {
        $clock = $this->clock;

        return (int) $clock();
    }
}
