<?php
if (! function_exists('avg')) {
    function avg(array $data) {
        return array_sum($data) / count($data);
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
