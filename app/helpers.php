<?php
use Etechnika\IdnaConvert\IdnaConvert;

if (! function_exists('avg')) {
    function avg(array $data) {
        return array_sum($data) / count($data);
    }
}

if (! function_exists('url_modify')) {
    function url_modify($url, $defaultScheme = 'http') {
        if (parse_url($url, PHP_URL_SCHEME) == null) {
            $url = $defaultScheme . '://' . trim($url, '/');
        }
        $url = (new URL\Normalizer($url, true, true))->normalize();
        if (filter_var(IdnaConvert::encodeString($url), FILTER_VALIDATE_URL) === false) {
            return false;
        } else {
            $fragment = parse_url($url, PHP_URL_FRAGMENT);
            return str_replace('#' . $fragment, '#' . urldecode($fragment), $url);
        }
    }
}

/**
 * Registers a class and set a variable to framework method.
 *
 * @param string $name Method name
 * @param string $class Class name
 * @param array $params Class initialization parameters
 * @param callback $callback Function to call after object instantiation
 * @throws \Exception If trying to map over a framework method
 */
Flight::map('instance', function($name, $class, array $params = array(), $callback = null) {
    Flight::register($name, $class, $params, $callback);
    Flight::set($name, Flight::{$name}());
});
