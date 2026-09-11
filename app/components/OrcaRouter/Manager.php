<?php

namespace app\components\OrcaRouter;

/**
 * Wires the two credential adapters to the single inference provider.
 *
 * The browser is told which credential is active and its masked form; it is
 * never given the key. Model listing and chat both run server-side against the
 * same Credential object, so no credential logic is duplicated per route.
 */
class Manager
{
    const PROVIDERS = array(
        ApiKeySource::PROVIDER_ID => 'OrcaRouter - API',
        PkceSource::PROVIDER_ID => 'OrcaRouter - Auth',
    );
    const ACTIVE_KEY = 'active_provider';
    const MODEL_KEY = 'selected_model';

    private $store;
    private $origins;
    private $sources = array();
    private $catalog;
    private $provider;
    private $lock;

    public function __construct(SecretStore $store, Origins $origins)
    {
        $this->store = $store;
        $this->origins = $origins;
        $this->catalog = new ModelCatalog($origins);
        $this->provider = new Provider($origins, $store);
        $this->lock = new LoginLock($store);
        $this->sources[ApiKeySource::PROVIDER_ID] = new ApiKeySource($store);
        $this->sources[PkceSource::PROVIDER_ID] = new PkceSource($store, new PkceFlow($origins, $store));
    }

    /** @return CredentialSource */
    public function source($providerId)
    {
        if (!isset($this->sources[$providerId])) {
            throw new OrcaRouterException('Unknown OrcaRouter authentication method.', 404);
        }

        return $this->sources[$providerId];
    }

    /** @return array<string,CredentialSource> */
    public function sources()
    {
        return $this->sources;
    }

    public function catalog()
    {
        return $this->catalog;
    }

    public function provider()
    {
        return $this->provider;
    }

    public function lock()
    {
        return $this->lock;
    }

    public function origins()
    {
        return $this->origins;
    }

    public function store()
    {
        return $this->store;
    }

    /** The provider the user last used, defaulting to the API-key entry. */
    public function activeProviderId()
    {
        $active = $this->store->getState(self::ACTIVE_KEY);

        return isset($this->sources[$active]) ? $active : ApiKeySource::PROVIDER_ID;
    }

    public function setActiveProvider($providerId)
    {
        if (!isset($this->sources[$providerId])) {
            throw new OrcaRouterException('Unknown OrcaRouter authentication method.', 404);
        }
        $this->store->putState(self::ACTIVE_KEY, $providerId);

        return $providerId;
    }

    /** The credential of the active provider, if it has one. */
    public function activeCredential()
    {
        $credential = $this->source($this->activeProviderId())->credential();
        if ($credential !== null) {
            return $credential;
        }

        // Fall back to whichever entry does hold a usable credential, so a user
        // who signed in through one entry is never told they have no key.
        foreach ($this->sources as $id => $source) {
            $candidate = $source->credential();
            if ($candidate !== null && $candidate->isUsable()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * State for the OrcaRouter panel. Contains no secret material.
     */
    public function status()
    {
        $providers = array();
        foreach ($this->sources as $id => $source) {
            $credential = $source->credential();
            $providers[$id] = array(
                'id' => $id,
                'label' => $source->label(),
                'kind' => $source->kind(),
                'connected' => $credential !== null,
                'usable' => $credential !== null && $credential->isUsable(),
                'credential' => $credential !== null ? $credential->toPublicArray() : null,
            );
        }

        $lock = $this->lock->current();
        $pending = $this->sources[PkceSource::PROVIDER_ID]->flow()->pending();

        return array(
            'providers' => $providers,
            'active' => $this->activeProviderId(),
            'inference_base' => $this->origins->apiBase(),
            'auth_base' => $this->origins->authBase(),
            'key_console_url' => $this->origins->authBase().'/console/authorized-apps',
            'login' => array(
                'in_progress' => $lock !== null,
                'attempt' => $lock !== null ? (int) $lock['attempt'] : null,
                'auth_type' => $lock !== null ? $lock['auth_type'] : null,
                'pending_attempt' => $pending ? (int) $pending['attempt'] : null,
                'pending_expires_at' => $pending ? (int) $pending['expires_at'] : null,
            ),
            'selected_model' => $this->store->getState(self::MODEL_KEY),
        );
    }

    /** Remember a model choice, rejecting one the current catalog no longer offers. */
    public function setSelectedModel($modelId, $capability, array $modalities = array())
    {
        $modelId = trim((string) $modelId);
        if ($modelId === '') {
            $this->store->forgetState(self::MODEL_KEY);

            return null;
        }
        $result = $this->catalog->listFor($capability, $this->activeCredential(), $modalities);
        foreach ($result['models'] as $model) {
            if ($model['id'] === $modelId) {
                $this->store->putState(self::MODEL_KEY, $modelId);

                return $modelId;
            }
        }

        $this->store->forgetState(self::MODEL_KEY);

        return null;
    }

    /**
     * Restore the persisted model only when it is still selectable for the
     * current capability; otherwise clear it rather than silently keep a value
     * the catalog no longer offers.
     */
    public function restoreSelectedModel($capability, array $modalities = array())
    {
        $stored = $this->store->getState(self::MODEL_KEY);
        if (!$stored) {
            return null;
        }
        $result = $this->catalog->listFor($capability, $this->activeCredential(), $modalities);
        foreach ($result['models'] as $model) {
            if ($model['id'] === $stored) {
                return $stored;
            }
        }
        $this->store->forgetState(self::MODEL_KEY);

        return null;
    }
}
