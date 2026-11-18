<?php

namespace app\components\OrcaRouter;

/**
 * Random byte source.
 *
 * random_bytes() only exists from PHP 7.0; the project's CI matrix still ships
 * PHP 5.6, so fall back to the OpenSSL CSPRNG there.
 */
class Random
{
    public static function bytes($length)
    {
        if (function_exists('random_bytes')) {
            return random_bytes($length);
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($bytes !== false && strlen($bytes) === $length) {
                return $bytes;
            }
        }
        throw new \RuntimeException('no cryptographic random source available');
    }

    /** base64url without padding. */
    public static function b64url($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function token($length = 32)
    {
        return self::b64url(self::bytes($length));
    }
}
