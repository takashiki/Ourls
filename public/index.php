<?php
require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/../app/helpers.php';

require __DIR__ . '/../app/routes.php';

$config = require __DIR__ . '/../app/config.php';

Flight::set('base_url', $config['base_url']);
Flight::set('alphabet', $config['hash']['alphabet']);

Flight::instance('hash', '\app\components\Hash', [$config['hash']]);

Flight::instance('db', 'medoo', [$config['db']]);

Flight::start();
