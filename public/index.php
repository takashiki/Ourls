<?php

require __DIR__.'/../vendor/autoload.php';

require __DIR__.'/../app/helpers.php';

require __DIR__.'/../app/routes.php';

$config = require __DIR__.'/../app/config.php';

Flight::set('flight.log_errors', $config['debug']);
Flight::set('flight.base_url', $config['base_url']);
Flight::set('flight.settings', $config['settings']);
Flight::set('flight.views.path', __DIR__.'/../app/views');
Flight::set('alphabet', $config['hash']['alphabet']);

Flight::instance('hash', '\app\components\Hash', [$config['hash']]);
Flight::instance('db', 'medoo', [$config['db']]);
Flight::instance('db_read', 'medoo', [$config['db_read']]);
Flight::instance('proxies', '\IPSet\IPSet', [$config['proxies']]);

Flight::start();
