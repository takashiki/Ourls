<?php

namespace app\components\OrcaRouter;

/**
 * Adapter 2 of 2: sign in with an OrcaRouter account.
 *
 * Produces a different acquisition path but the *same* Credential shape as
 * ApiKeySource, so nothing downstream can tell which one was used.
 */
class PkceSource implements CredentialSource
{
    const PROVIDER_ID = 'orcarouter-oauth';

    private $store;
    private $flow;

    public function __construct(SecretStore $store, PkceFlow $flow)
    {
        $this->store = $store;
        $this->flow = $flow;
    }

    public function id()
    {
        return self::PROVIDER_ID;
    }

    public function label()
    {
        return 'OrcaRouter - Auth';
    }

    public function kind()
    {
        return 'oauth_pkce';
    }

    /** @param array $input {code: string} — the code shown on the consent screen */
    public function save(array $input)
    {
        $code = isset($input['code']) ? trim((string) $input['code']) : '';
        if ($code === '') {
            throw new OrcaRouterException('Enter the authorization code shown on the OrcaRouter consent screen.', 400);
        }

        $result = $this->flow->exchange($code);
        $existing = $this->store->getAccount($this->id());
        $generation = $existing ? ((int) $existing['generation'] + 1) : 1;

        $account = $this->store->putAccount($this->id(), array(
            'provider' => $this->id(),
            'auth_type' => 'oauth_pkce',
            'key' => $result['key'],
            'generation' => $generation,
            'status' => Credential::STATUS_ACTIVE,
            'scope' => $result['scope'],
            'user_id' => $result['user_id'],
            'granted_scope' => $result['scope'],
            'requested_scope' => $result['requested_scope'],
            'scope_downgraded' => $result['requested_scope'] !== null && $result['requested_scope'] !== $result['scope'],
            'created_at' => time(),
        ));

        return new Credential($account);
    }

    public function credential()
    {
        $account = $this->store->getAccount($this->id());

        return $account ? new Credential($account) : null;
    }

    public function clear()
    {
        $this->flow->cancel();
        if ($this->store->getAccount($this->id()) === null) {
            return false;
        }
        $this->store->removeAccount($this->id());

        return true;
    }

    /** @return PkceFlow */
    public function flow()
    {
        return $this->flow;
    }
}
