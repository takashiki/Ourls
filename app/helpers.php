<?php

use Etechnika\IdnaConvert\IdnaConvert;

if (!function_exists('avg')) {
    function avg(array $data)
    {
        return array_sum($data) / count($data);
    }
}

if (!function_exists('url_modify')) {
    function url_modify($url, $defaultScheme = 'http')
    {
        if (parse_url($url, PHP_URL_SCHEME) == null) {
            $url = $defaultScheme.'://'.trim($url, '/');
        }
        $url = (new URL\Normalizer($url, true, true))->normalize();
        if (filter_var(IdnaConvert::encodeString($url), FILTER_VALIDATE_URL) === false) {
            return false;
        } else {
            $fragment = parse_url($url, PHP_URL_FRAGMENT);

            return str_replace('#'.$fragment, '#'.urldecode($fragment), $url);
        }
    }
}

if (!function_exists('real_remote_addr')) {
    function real_remote_addr()
    {
        $ip = Flight::request()->ip;
        $proxy = Flight::request()->proxy_ip;
        if ('' != $proxy && Flight::get('proxies')->match($ip)) {
            return $proxy;
        } else {
            return $ip;
        }
    }
}

/*
 * Registers a class and set a variable to framework method.
 *
 * @param string $name Method name
 * @param string $class Class name
 * @param array $params Class initialization parameters
 * @param callback $callback Function to call after object instantiation
 * @throws \Exception If trying to map over a framework method
 */
Flight::map('instance', function ($name, $class, array $params = [], $callback = null) {
    Flight::register($name, $class, $params, $callback);
    Flight::set($name, Flight::{$name}());
});

if (!function_exists('orcarouter')) {
    /*
     * Lazily build the OrcaRouter manager from the application config.
     *
     * Both the browser-facing routes and the inference path go through this one
     * object, so the credential seam and the model catalog are never duplicated
     * across entry points.
     *
     * @return \app\components\OrcaRouter\Manager
     */
    function orcarouter()
    {
        static $manager = null;
        if ($manager === null) {
            $config = Flight::get('flight.orcarouter');
            $config = is_array($config) ? $config : [];
            $manager = new \app\components\OrcaRouter\Manager(
                new \app\components\OrcaRouter\SecretStore(
                    isset($config['credential_file']) ? $config['credential_file'] : __DIR__.'/../storage/orcarouter.json'
                ),
                \app\components\OrcaRouter\Origins::fromConfig($config)
            );
        }

        return $manager;
    }
}

if (!function_exists('orca_input')) {
    /*
     * Read a JSON request body (or form fields) into an array.
     */
    function orca_input()
    {
        static $data = null;
        if ($data !== null) {
            return $data;
        }
        $data = [];
        $form = Flight::request()->data->getData();
        if (is_array($form)) {
            $data = $form;
        }
        if (!$data) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return $data;
    }
}

if (!function_exists('orca_respond')) {
    /*
     * JSON response. Nothing in this payload is a secret: the credential is
     * only ever represented by its masked form.
     */
    function orca_respond(array $payload, $status = 200)
    {
        Flight::response()->status($status)
            ->header('content-type', 'application/json; charset=utf-8')
            ->header('cache-control', 'no-store')
            ->write(json_encode($payload))
            ->send();
    }
}

if (!function_exists('orca_fail')) {
    function orca_fail($message, $status = 400, array $extra = [])
    {
        orca_respond(array_merge([
            'ok'      => false,
            'error'   => orcarouter()->store()->redact($message),
        ], $extra), $status);
    }
}

if (!function_exists('orca_csrf_guard')) {
    /*
     * State-changing OrcaRouter routes are POST-only and refuse a cross-site
     * Origin, so a page on another origin cannot drive the credential store
     * through the user's browser.
     */
    function orca_csrf_guard()
    {
        $request = Flight::request();
        if (strtoupper($request->method) !== 'POST') {
            orca_fail('This endpoint accepts POST only.', 405);

            return false;
        }
        $origin = isset($request->headers['Origin']) ? $request->headers['Origin'] : null;
        if ($origin) {
            $host = parse_url($origin, PHP_URL_HOST);
            if ($host && strcasecmp($host, $request->host) !== 0) {
                orca_fail('Cross-origin request refused.', 403);

                return false;
            }
        }

        return true;
    }
}
