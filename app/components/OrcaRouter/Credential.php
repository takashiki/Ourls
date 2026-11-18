<?php

namespace app\components\OrcaRouter;

/**
 * A resolved OrcaRouter credential.
 *
 * The raw key is reachable only through apiKey(); every other representation
 * (string cast, JSON, public array) is the masked form, so a Credential can be
 * logged or embedded in an error without leaking the secret.
 */
class Credential
{
    const STATUS_ACTIVE = 'active';
    const STATUS_NEEDS_REAUTH = 'needs_reauth';

    private $providerId;
    private $authType;
    private $apiKey;
    private $accountId;
    private $generation;
    private $status;
    private $scope;
    private $userId;

    public function __construct(array $data)
    {
        $this->providerId = isset($data['provider']) ? $data['provider'] : 'orcarouter';
        $this->authType = isset($data['auth_type']) ? $data['auth_type'] : 'api_key';
        $this->apiKey = isset($data['key']) ? (string) $data['key'] : '';
        $this->accountId = isset($data['account_id']) ? $data['account_id'] : $this->providerId;
        $this->generation = isset($data['generation']) ? (int) $data['generation'] : 1;
        $this->status = isset($data['status']) ? $data['status'] : self::STATUS_ACTIVE;
        $this->scope = isset($data['scope']) ? $data['scope'] : null;
        $this->userId = isset($data['user_id']) ? $data['user_id'] : null;
    }

    /** The secret. Call sites must not log, echo or serialize the result. */
    public function apiKey()
    {
        return $this->apiKey;
    }

    public function bearerHeader()
    {
        return 'Authorization: Bearer '.$this->apiKey;
    }

    /** Safe for display: never more than four leading and two trailing chars. */
    public function masked()
    {
        return self::mask($this->apiKey);
    }

    public static function mask($secret)
    {
        $secret = (string) $secret;
        $length = strlen($secret);
        if ($length === 0) {
            return '(none)';
        }
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($secret, 0, 4).str_repeat('*', 6).substr($secret, -2);
    }

    public function providerId()
    {
        return $this->providerId;
    }

    public function accountId()
    {
        return $this->accountId;
    }

    public function authType()
    {
        return $this->authType;
    }

    public function generation()
    {
        return $this->generation;
    }

    public function scope()
    {
        return $this->scope;
    }

    public function userId()
    {
        return $this->userId;
    }

    public function needsReauth()
    {
        return $this->status === self::STATUS_NEEDS_REAUTH;
    }

    public function isUsable()
    {
        return $this->apiKey !== '' && !$this->needsReauth();
    }

    /** Wire format for the browser: metadata only, never the key. */
    public function toPublicArray()
    {
        return array(
            'provider' => $this->providerId,
            'account_id' => $this->accountId,
            'auth_type' => $this->authType,
            'masked' => $this->masked(),
            'generation' => $this->generation,
            'status' => $this->status,
            'scope' => $this->scope,
            'user_id' => $this->userId,
            'usable' => $this->isUsable(),
        );
    }

    public function toStorageArray()
    {
        return array(
            'provider' => $this->providerId,
            'account_id' => $this->accountId,
            'auth_type' => $this->authType,
            'key' => $this->apiKey,
            'generation' => $this->generation,
            'status' => $this->status,
            'scope' => $this->scope,
            'user_id' => $this->userId,
        );
    }

    public function __toString()
    {
        return 'OrcaRouterCredential('.$this->accountId.','.$this->authType.','.$this->masked().')';
    }
}
