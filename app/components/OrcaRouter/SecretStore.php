<?php

namespace app\components\OrcaRouter;

/**
 * Encrypted credential store.
 *
 * There is no credential store in this project to reuse — app/config.php holds
 * database credentials in plaintext and is git-ignored, and that is the only
 * precedent. Rather than invent a second plaintext side file, credentials go
 * into one git-ignored file encrypted with a key that lives in a sibling
 * 0600 file. The project's existing mechanism (a git-ignored file under app/)
 * is preserved; only the at-rest encoding is stronger.
 *
 * This is deliberately small: no key rotation, no multi-user separation.
 */
class SecretStore
{
    const VERSION = 1;
    const CIPHER = 'aes-256-gcm';

    private $path;
    private $keyPath;
    private $key;

    public function __construct($path, $keyPath = null)
    {
        $this->path = $path;
        $this->keyPath = $keyPath ?: dirname($path).'/orcarouter.key';
    }

    public function path()
    {
        return $this->path;
    }

    /**
     * @return array the decrypted store document, empty when nothing is stored
     */
    public function load()
    {
        if (!is_file($this->path)) {
            return self::emptyDocument();
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return self::emptyDocument();
        }

        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !isset($envelope['iv'], $envelope['tag'], $envelope['data'])) {
            // A corrupt or truncated store must not take the whole app down.
            return self::emptyDocument();
        }

        try {
            $plain = $this->decrypt(
                self::b64d($envelope['data']),
                self::b64d($envelope['iv']),
                self::b64d($envelope['tag'])
            );
        } catch (\Exception $e) {
            return self::emptyDocument();
        }

        $document = json_decode($plain, true);
        if (!is_array($document)) {
            return self::emptyDocument();
        }

        $document += self::emptyDocument();

        return $document;
    }

    public function save(array $document)
    {
        $document['version'] = self::VERSION;
        $plain = json_encode($document);
        if ($plain === false) {
            throw new \RuntimeException('credential store is not encodable');
        }

        $iv = Random::bytes(12);
        $tag = '';
        $ciphertext = $this->encrypt($plain, $iv, $tag);

        $envelope = json_encode(array(
            'version' => self::VERSION,
            'cipher' => self::CIPHER,
            'iv' => self::b64($iv),
            'tag' => self::b64($tag),
            'data' => self::b64($ciphertext),
        ));

        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $temporary = $this->path.'.tmp';
        if (@file_put_contents($temporary, $envelope) === false) {
            throw new \RuntimeException('cannot write credential store');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException('cannot replace credential store');
        }
        @chmod($this->path, 0600);
    }

    public function getAccount($accountId)
    {
        $document = $this->load();

        return isset($document['accounts'][$accountId]) ? $document['accounts'][$accountId] : null;
    }

    public function putAccount($accountId, array $account)
    {
        $document = $this->load();
        $account['account_id'] = $accountId;
        $document['accounts'][$accountId] = $account;
        $this->save($document);

        return $account;
    }

    public function removeAccount($accountId)
    {
        $document = $this->load();
        unset($document['accounts'][$accountId]);
        $this->save($document);
    }

    public function getState($key, $default = null)
    {
        $document = $this->load();

        return isset($document['state'][$key]) ? $document['state'][$key] : $default;
    }

    public function putState($key, $value)
    {
        $document = $this->load();
        $document['state'][$key] = $value;
        $this->save($document);
    }

    public function forgetState($key)
    {
        $document = $this->load();
        unset($document['state'][$key]);
        $this->save($document);
    }

    /**
     * Generation-safe terminal reauthentication.
     *
     * Only the exact account *and* the exact credential generation that made
     * the rejected request is marked. A late 401 belonging to a superseded
     * generation is ignored, so it cannot break a credential that was just
     * re-authorized.
     */
    public function markNeedsReauth($accountId, $generation)
    {
        $document = $this->load();
        if (!isset($document['accounts'][$accountId])) {
            return false;
        }
        $account = $document['accounts'][$accountId];
        if ((int) $account['generation'] !== (int) $generation) {
            return false;
        }
        if ($account['status'] === Credential::STATUS_NEEDS_REAUTH) {
            return false;
        }
        $account['status'] = Credential::STATUS_NEEDS_REAUTH;
        $document['accounts'][$accountId] = $account;
        $this->save($document);

        return true;
    }

    /** Remove every secret-looking substring from a value before it is surfaced. */
    public function redact($text)
    {
        $text = (string) $text;
        $document = $this->load();
        $secrets = array();
        foreach ($document['accounts'] as $account) {
            if (!empty($account['key'])) {
                $secrets[] = $account['key'];
            }
        }
        if (isset($document['state']['pkce_pending']['verifier'])) {
            $secrets[] = $document['state']['pkce_pending']['verifier'];
        }

        foreach ($secrets as $secret) {
            if (strlen($secret) >= 8) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        // Generic safety net for keys that never reached the store.
        $text = preg_replace('/sk-orca-[A-Za-z0-9_\-]+/', 'sk-orca-[redacted]', $text);

        return $text;
    }

    private static function emptyDocument()
    {
        return array('version' => self::VERSION, 'accounts' => array(), 'state' => array());
    }

    private function masterKey()
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if (is_file($this->keyPath)) {
            $key = @file_get_contents($this->keyPath);
            if ($key !== false && strlen($key) >= 32) {
                $this->key = substr($key, 0, 32);

                return $this->key;
            }
        }

        $directory = dirname($this->keyPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $key = Random::bytes(32);
        if (@file_put_contents($this->keyPath, $key) === false) {
            throw new \RuntimeException('cannot create credential store key');
        }
        @chmod($this->keyPath, 0600);
        $this->key = $key;

        return $this->key;
    }

    private function encrypt($plain, $iv, &$tag)
    {
        if (function_exists('sodium_crypto_aead_aes256gcm_encrypt') && sodium_crypto_aead_aes256gcm_is_available()) {
            $tag = '';

            return sodium_crypto_aead_aes256gcm_encrypt($plain, '', $iv, $this->masterKey());
        }
        if (function_exists('openssl_encrypt')) {
            $tag = '';
            $ciphertext = openssl_encrypt($plain, self::CIPHER, $this->masterKey(), OPENSSL_RAW_DATA, $iv, $tag);
            if ($ciphertext === false) {
                throw new \RuntimeException('cannot encrypt credential store');
            }

            return $ciphertext;
        }

        throw new \RuntimeException('no AEAD cipher available for the credential store');
    }

    private function decrypt($ciphertext, $iv, $tag)
    {
        if (function_exists('sodium_crypto_aead_aes256gcm_decrypt') && sodium_crypto_aead_aes256gcm_is_available()) {
            $plain = sodium_crypto_aead_aes256gcm_decrypt($ciphertext, '', $iv, $this->masterKey());
            if ($plain === false) {
                throw new \RuntimeException('credential store authentication failed');
            }

            return $plain;
        }
        if (function_exists('openssl_decrypt')) {
            $plain = openssl_decrypt($ciphertext, self::CIPHER, $this->masterKey(), OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain === false) {
                throw new \RuntimeException('credential store authentication failed');
            }

            return $plain;
        }

        throw new \RuntimeException('no AEAD cipher available for the credential store');
    }

    private static function b64($raw)
    {
        return base64_encode($raw);
    }

    private static function b64d($encoded)
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new \RuntimeException('credential store is malformed');
        }

        return $decoded;
    }
}
