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
    'proxies' => [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fd00::/8',
    ],
];
