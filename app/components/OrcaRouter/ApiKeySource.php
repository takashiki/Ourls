<?php

namespace app\components\OrcaRouter;

/**
 * Adapter 1 of 2: a key the user already holds.
 *
 * This is the path for a user who has an `sk-orca-…` key in their console and
 * does not want a browser involved. It never contacts the auth origin.
 */
class ApiKeySource implements CredentialSource
{
    const PROVIDER_ID = 'orcarouter';
    const PREFIX = 'sk-orca-';

    private $store;

    public function __construct(SecretStore $store)
    {
        $this->store = $store;
    }

    public function id()
    {
        return self::PROVIDER_ID;
    }

    public function label()
    {
        return 'OrcaRouter - API';
    }

    public function kind()
    {
        return 'api_key';
    }

    public function save(array $input)
    {
        $key = isset($input['api_key']) ? trim((string) $input['api_key']) : '';
        if ($key === '') {
            throw new OrcaRouterException('Enter an OrcaRouter API key.', 400);
        }

        // Lightweight format check only. The `sk-orca-` prefix is not proof the
        // key is valid, and there is no stable non-billing validation request,
        // so validity stays unknown until the first real inference call.
        if (strpos($key, self::PREFIX) !== 0) {
            throw new OrcaRouterException('An OrcaRouter API key starts with "'.self::PREFIX.'".', 400);
        }
        if (strlen($key) < strlen(self::PREFIX) + 8) {
            throw new OrcaRouterException('That OrcaRouter API key looks truncated.', 400);
        }
        if (preg_match('/\s/', $key)) {
            throw new OrcaRouterException('An OrcaRouter API key contains no whitespace.', 400);
        }

        $existing = $this->store->getAccount($this->id());
        $generation = $existing ? ((int) $existing['generation'] + 1) : 1;

        $account = $this->store->putAccount($this->id(), array(
            'provider' => $this->id(),
            'auth_type' => 'api_key',
            'key' => $key,
            'generation' => $generation,
            'status' => Credential::STATUS_ACTIVE,
            'scope' => null,
            'user_id' => null,
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
        if ($this->store->getAccount($this->id()) === null) {
            return false;
        }
        $this->store->removeAccount($this->id());

        return true;
    }
}
