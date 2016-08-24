<?php

Flight::route('/', function () {
    Flight::render('index.php');
});

Flight::route('/shorten', function () {
    $url = url_modify(Flight::request()->query['url']);
    if ($url) {
        if (strpos($url, Flight::get('flight.base_url')) !== false) {
            Flight::json(['status' => 0, 'msg' => '该地址无法被缩短']);
        } else {
            $sha1 = sha1($url);
            $store = Flight::get('db_read')->select('urls', ['id'], [
                'sha1' => $sha1,
            ]);
            if (!$store) {
                $id = Flight::get('db')->insert('urls', [
                    'sha1'      => $sha1,
                    'url'       => $url,
                    'create_at' => time(),
                    'creator'   => ip2long(real_remote_addr()),
                ]);
            } else {
                $id = $store[0]['id'];
            }
            $s_url = Flight::get('flight.base_url').Flight::get('hash')->encode($id);
            Flight::json(['status' => 1, 's_url' => $s_url]);
        }
    } else {
        Flight::json(['status' => 0, 'msg' => '请传入正确的url']);
    }
});

Flight::route('/expand', function () {
    $s_url = Flight::request()->query['s_url'];
    if ($s_url) {
        $hash = str_replace(Flight::get('flight.base_url'), '', $s_url);
        if (!preg_match('/^['.Flight::get('alphabet').']+$/', $hash)) {
            Flight::json(['status' => 0, 'msg' => '短址不正确']);
        } else {
            $id = Flight::get('hash')->decode($hash);
            if (!$id) {
                Flight::json(['status' => 0, 'msg' => '短址无法解析']);
            } else {
                $store = Flight::get('db_read')->select('urls', ['url'], [
                    'id' => $id,
                ]);
                if (!$store) {
                    Flight::json(['status' => 0, 'msg' => '地址不存在']);
                } else {
                    Flight::json(['status' => 1, 'url' => $store[0]['url']]);
                }
            }
        }
    }
});

Flight::route('/@hash', function ($hash) {
    $id = Flight::get('hash')->decode($hash);
    if (!$id) {
        Flight::notFound('短址无法解析');
    } else {
        $store = Flight::get('db_read')->select('urls', ['url'], [
            'id' => $id,
        ]);
        if (!$store) {
            Flight::notFound('地址不存在');
        } else {
            Flight::get('db')->update('urls', ['count[+]' => 1], [
                'id' => $id,
            ]);
            Flight::redirect($store[0]['url'], 302);
        }
    }
});

Flight::map('notFound', function ($message) {
    Flight::response()->status(404)
        ->header('content-type', 'text/html; charset=utf-8')
        ->write(
            '<h1>404 页面未找到</h1>'.
            "<h3>{$message}</h3>".
            '<p><a href="'.Flight::get('flight.base_url').'">回到首页</a></p>'.
            str_repeat(' ', 512)
        )
        ->send();
});

Flight::map('error', function (Exception $ex) {
    $message = Flight::get('flight.log_errors') ? $ex->getTraceAsString() : '出错了';
    Flight::response()->status(500)
        ->header('content-type', 'text/html; charset=utf-8')
        ->write(
            '<h1>500 服务器内部错误</h1>'.
            "<h3>{$message}</h3>".
            '<p><a href="'.Flight::get('flight.base_url').'">回到首页</a></p>'.
            str_repeat(' ', 512)
        )
        ->send();
});
