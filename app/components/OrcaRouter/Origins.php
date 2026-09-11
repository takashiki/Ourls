<?php

namespace app\components\OrcaRouter;

/**
 * OrcaRouter origin resolution.
 *
 * Authentication and inference live on *different* public origins. They are
 * kept as two independent values here and one is never derived from the other
 * by host substitution: the defaults are the documented ones, an explicit
 * override is used verbatim, and the shared ORCA_BASE_URL is only a fallback.
 */
class Origins
{
    const DEFAULT_AUTH = 'https://www.orcarouter.ai';
    const DEFAULT_API = 'https://api.orcarouter.ai/v1';

    /** Path fragments. These are fixed by the protocol, not configurable. */
    const AUTHORIZE_PATH = '/auth';
    const EXCHANGE_PATH = '/api/v1/auth/keys';
    const MODELS_PATH = '/models';
    const CHAT_PATH = '/chat/completions';

    private $auth;
    private $api;

    public function __construct($authBase, $apiBase)
    {
        $this->auth = self::validate($authBase, 'auth');
        $this->api = self::validate($apiBase, 'api');
    }

    /**
     * @param array $config the `orcarouter` config block (may be empty)
     * @param array $env    environment override source, defaults to $_ENV/getenv
     */
    public static function fromConfig(array $config, $env = null)
    {
        if ($env === null) {
            $env = array();
            foreach (array('ORCA_BASE_URL', 'ORCA_AUTH_BASE_URL', 'ORCA_API_BASE_URL') as $name) {
                $value = getenv($name);
                if ($value !== false && $value !== '') {
                    $env[$name] = $value;
                }
            }
        }

        $shared = self::pick($env, 'ORCA_BASE_URL', isset($config['base_url']) ? $config['base_url'] : null);

        $auth = self::pick($env, 'ORCA_AUTH_BASE_URL', isset($config['auth_base_url']) ? $config['auth_base_url'] : null);
        $api = self::pick($env, 'ORCA_API_BASE_URL', isset($config['api_base_url']) ? $config['api_base_url'] : null);

        // Explicit per-origin overrides always win. Only when one is absent does
        // the shared self-hosted value apply, and only the *inference* origin
        // appends the /v1 prefix because that is what the shared self-hosted
        // deployment convention documents.
        if ($auth === null) {
            $auth = ($shared !== null) ? $shared : self::DEFAULT_AUTH;
        }
        if ($api === null) {
            $api = ($shared !== null) ? rtrim($shared, '/').'/v1' : self::DEFAULT_API;
        }

        return new self($auth, $api);
    }

    private static function pick(array $env, $key, $fallback)
    {
        if (isset($env[$key]) && $env[$key] !== '') {
            return $env[$key];
        }

        return ($fallback !== null && $fallback !== '') ? $fallback : null;
    }

    /**
     * Reject anything that would send a credential somewhere unsafe.
     * Remote origins must be HTTPS; plain HTTP is allowed for loopback only.
     */
    private static function validate($url, $label)
    {
        $url = rtrim(trim((string) $url), '/');
        if ($url === '') {
            throw new \InvalidArgumentException('orcarouter '.$label.' base url is empty');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('orcarouter '.$label.' base url is not absolute');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('orcarouter '.$label.' base url must not carry userinfo');
        }
        if (isset($parts['fragment'])) {
            throw new \InvalidArgumentException('orcarouter '.$label.' base url must not carry a fragment');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if ($scheme === 'https') {
            return $url;
        }
        if ($scheme === 'http' && self::isLoopback($host)) {
            return $url;
        }

        throw new \InvalidArgumentException(
            'orcarouter '.$label.' base url must use https (http is allowed for loopback only)'
        );
    }

    public static function isLoopback($host)
    {
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || strpos($host, '127.') === 0;
    }

    public function authBase()
    {
        return $this->auth;
    }

    public function apiBase()
    {
        return $this->api;
    }

    public function authorizeUrl()
    {
        return $this->auth.self::AUTHORIZE_PATH;
    }

    public function exchangeUrl()
    {
        return $this->auth.self::EXCHANGE_PATH;
    }

    public function modelsUrl($capability = null)
    {
        $url = $this->api.self::MODELS_PATH;
        if ($capability !== null && $capability !== '') {
            $url .= '?capability='.rawurlencode($capability);
        }

        return $url;
    }

    public function chatUrl()
    {
        return $this->api.self::CHAT_PATH;
    }

    /** Host of the auth origin, for display and for redaction assertions. */
    public function authHost()
    {
        $parts = parse_url($this->auth);

        return isset($parts['host']) ? $parts['host'] : '';
    }

    public function apiHost()
    {
        $parts = parse_url($this->api);

        return isset($parts['host']) ? $parts['host'] : '';
    }
}
