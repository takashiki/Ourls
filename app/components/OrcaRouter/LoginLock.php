<?php

namespace app\components\OrcaRouter;

/**
 * Server-side "an authorization is already running" lock.
 *
 * A PHP request handler cannot hold a mutex in memory between two requests, so
 * the lock is a short-lived record in the credential store keyed by an attempt
 * number. Every terminal path releases it: success, denial, exchange error,
 * timeout, explicit cancel from the UI, and the `pagehide`/unload cancel.
 *
 * `release()` is deliberately tolerant: releasing an attempt that has already
 * been superseded is a no-op, so a late cancel from an abandoned page cannot
 * unlock a newer sign-in.
 */
class LoginLock
{
    const STATE_KEY = 'pkce_lock';
    const TTL = 600;

    private $store;
    private $clock;

    public function __construct(SecretStore $store, $clock = null)
    {
        $this->store = $store;
        $this->clock = $clock ?: function () {
            return time();
        };
    }

    public function acquire($attempt, $authType)
    {
        $this->store->putState(self::STATE_KEY, array(
            'attempt' => (int) $attempt,
            'auth_type' => $authType,
            'acquired_at' => $this->now(),
            'expires_at' => $this->now() + self::TTL,
        ));

        return true;
    }

    /** @return array|null the live lock, or null when none is held or it has expired */
    public function current()
    {
        $lock = $this->store->getState(self::STATE_KEY);
        if (!$lock) {
            return null;
        }
        if ($this->now() > (int) $lock['expires_at']) {
            $this->store->forgetState(self::STATE_KEY);

            return null;
        }

        return $lock;
    }

    /**
     * Release the lock. When $attempt is supplied, only that attempt is
     * released; a stale release never clears a newer sign-in.
     */
    public function release($attempt = null)
    {
        $lock = $this->store->getState(self::STATE_KEY);
        if (!$lock) {
            return false;
        }
        if ($attempt !== null && (int) $lock['attempt'] !== (int) $attempt) {
            return false;
        }
        $this->store->forgetState(self::STATE_KEY);

        return true;
    }

    private function now()
    {
        $clock = $this->clock;

        return (int) $clock();
    }
}
