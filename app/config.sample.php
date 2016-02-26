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
];
