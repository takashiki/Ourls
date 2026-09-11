<?php

return [
    'debug'    => true,
    'base_url' => 'YourSiteUrl',
    'hash'     => [
        'salt'     => 'SomeRandomKey',
        'length'   => 5,
        'alphabet' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
    ],
    'db' => [
        'database_type' => 'mysql',
        'database_name' => 'name',
        'server'        => 'localhost',
        'username'      => 'your_username',
        'password'      => 'your_password',
        'charset'       => 'utf8',
        'port'          => 3306,
        'option'        => [
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
        ],
    ],
    'db_read' => [
        'database_type' => 'mysql',
        'database_name' => 'name',
        'server'        => 'localhost',
        'username'      => 'your_username',
        'password'      => 'your_password',
        'charset'       => 'utf8',
        'port'          => 3306,
        'option'        => [
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
        ],
    ],
    'settings' => [
        'external_js' => null,
    ],
    /*
     * OrcaRouter provider (https://www.orcarouter.ai).
     *
     * Authentication and inference live on different origins. Leave these null
     * to use the public defaults:
     *   auth  https://www.orcarouter.ai
     *   api   https://api.orcarouter.ai/v1
     *
     * For a self-hosted deployment set 'base_url' to the single shared origin,
     * or set 'auth_base_url' / 'api_base_url' individually. A specific value
     * wins over the shared one, and the environment variables ORCA_BASE_URL,
     * ORCA_AUTH_BASE_URL and ORCA_API_BASE_URL win over both. Remote origins
     * must be https; http is accepted for loopback only.
     *
     * 'credential_file' stores the encrypted API key and is never committed.
     */
    'orcarouter' => [
        'base_url'        => null,
        'auth_base_url'   => null,
        'api_base_url'    => null,
        'credential_file' => __DIR__.'/../storage/orcarouter.json',
    ],
    'proxies' => [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fd00::/8',
    ],
];
