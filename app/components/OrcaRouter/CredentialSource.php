<?php

namespace app\components\OrcaRouter;

/**
 * The credential seam.
 *
 * Everything downstream — the inference provider, the model catalog, the
 * routes — talks to a credential only through this interface plus Credential.
 * Acquiring a key is the *only* thing the two adapters do differently; there
 * is exactly one code path from a stored key to a Bearer header.
 */
interface CredentialSource
{
    /** Stable provider id, e.g. `orcarouter` or `orcarouter-oauth`. */
    public function id();

    /** Human label, e.g. `OrcaRouter - API`. */
    public function label();

    /** How the user obtains this credential, for the settings UI. */
    public function kind();

    /**
     * Persist a credential from user-supplied input.
     *
     * @param array $input adapter-specific
     *
     * @return Credential
     *
     * @throws OrcaRouterException on invalid input or a rejected exchange
     */
    public function save(array $input);

    /** @return Credential|null the stored credential, or null when absent */
    public function credential();

    /** Remove the stored credential. Returns true when something was removed. */
    public function clear();
}
