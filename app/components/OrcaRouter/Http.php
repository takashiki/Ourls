<?php

namespace app\components\OrcaRouter;

/**
 * Bounded HTTP client.
 *
 * Every OrcaRouter call in this application goes through here so that the
 * timeout, redirect policy and response-size ceiling are enforced in one
 * place. Response bodies are read incrementally and truncated at $maxBytes so
 * a hostile or broken endpoint cannot exhaust memory.
 */
class Http
{
    const CONNECT_TIMEOUT = 10;
    const TIMEOUT = 20;

    /**
     * Injectable transport, used by the test suite to record exactly which
     * origin a request went to without a network round trip. Null in normal
     * operation, in which case curl is used.
     *
     * @var callable|null function($method, $url, array $headers, $body, $timeout, $maxBytes): array
     */
    public static $transport = null;

    /** Every request made through this class, in order. Useful for assertions. */
    public static $log = array();

    public static function reset()
    {
        self::$transport = null;
        self::$log = array();
    }

    /**
     * @return array {status:int, body:string, error:string|null, truncated:bool}
     */
    public static function request($method, $url, array $headers = array(), $body = null, $timeout = self::TIMEOUT, $maxBytes = 4194304)
    {
        $result = array('status' => 0, 'body' => '', 'error' => null, 'truncated' => false);

        $entry = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
        if (self::$transport !== null) {
            $transport = self::$transport;
            $response = $transport($method, $url, $headers, $body, $timeout, $maxBytes);
            $entry['response'] = $response;
            self::$log[] = $entry;

            return $response + $result;
        }

        if (!function_exists('curl_init')) {
            $result['error'] = 'curl_unavailable';

            return $result;
        }

        $ch = curl_init();
        $received = 0;

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        // Never follow a redirect: the auth origin must not be able to hand a
        // credential exchange off to a different host.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$received, $maxBytes, &$result) {
            $length = strlen($chunk);
            if ($received + $length > $maxBytes) {
                $chunk = substr($chunk, 0, max(0, $maxBytes - $received));
                $result['truncated'] = true;
            }
            $received += strlen($chunk);
            $result['body'] .= $chunk;

            return $length;
        });

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno !== 0) {
            $result['error'] = 'transport_error';
        }
        $result['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $entry['response'] = $result;
        self::$log[] = $entry;

        return $result;
    }

    public static function getJson($url, array $headers = array(), $timeout = self::TIMEOUT, $maxBytes = 4194304)
    {
        return self::request('GET', $url, $headers, null, $timeout, $maxBytes);
    }

    public static function postJson($url, $payload, array $headers = array(), $timeout = self::TIMEOUT, $maxBytes = 4194304)
    {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        return self::request('POST', $url, $headers, json_encode($payload), $timeout, $maxBytes);
    }

    /** @return array|null decoded body, or null when it is not a JSON object/array */
    public static function decode($body)
    {
        if (!is_string($body) || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
