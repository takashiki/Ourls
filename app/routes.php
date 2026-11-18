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

/*
 * OrcaRouter provider routes.
 *
 * The browser talks to these endpoints; it never talks to OrcaRouter directly
 * and never holds the API key. Credential resolution, model discovery and
 * inference all run through app\components\OrcaRouter so both authentication
 * entries share one code path.
 */

Flight::route('GET /orcarouter/status', function () {
    orca_respond([
        'ok'     => true,
        'status' => orcarouter()->status(),
    ]);
});

Flight::route('POST /orcarouter/provider', function () {
    if (!orca_csrf_guard()) {
        return;
    }
    $input = orca_input();
    try {
        $active = orcarouter()->setActiveProvider(isset($input['provider']) ? $input['provider'] : '');
    } catch (\app\components\OrcaRouter\OrcaRouterException $e) {
        orca_fail($e->getMessage(), $e->status() ?: 400);

        return;
    }
    orca_respond(['ok' => true, 'active' => $active, 'status' => orcarouter()->status()]);
});

/*
 * API-key entry: paste an existing sk-orca-... key.
 */
Flight::route('POST /orcarouter/api-key', function () {
    if (!orca_csrf_guard()) {
        return;
    }
    $input = orca_input();
    $manager = orcarouter();
    try {
        $credential = $manager->source(\app\components\OrcaRouter\ApiKeySource::PROVIDER_ID)
            ->save(['api_key' => isset($input['api_key']) ? $input['api_key'] : '']);
        $manager->setActiveProvider(\app\components\OrcaRouter\ApiKeySource::PROVIDER_ID);
    } catch (\app\components\OrcaRouter\OrcaRouterException $e) {
        orca_fail($e->getMessage(), $e->status() ?: 400);

        return;
    }
    orca_respond([
        'ok'         => true,
        'credential' => $credential->toPublicArray(),
        'status'     => $manager->status(),
    ]);
});

Flight::route('POST /orcarouter/api-key/clear', function () {
    if (!orca_csrf_guard()) {
        return;
    }
    $manager = orcarouter();
    $removed = $manager->source(\app\components\OrcaRouter\ApiKeySource::PROVIDER_ID)->clear();
    orca_respond(['ok' => true, 'removed' => $removed, 'status' => $manager->status()]);
});

/*
 * PKCE entry, step 1: begin an out-of-band authorization attempt.
 *
 * The response carries the authorize URL (which holds the S256 challenge and
 * the state) and never the verifier, which stays server-side until exchange.
 */
Flight::route('POST /orcarouter/pkce/start', function () {
    if (!orca_csrf_guard()) {
        return;
    }
    $manager = orcarouter();
    $source = $manager->source(\app\components\OrcaRouter\PkceSource::PROVIDER_ID);
    $manager->setActiveProvider(\app\components\OrcaRouter\PkceSource::PROVIDER_ID);

    $attempt = $source->flow()->begin();
    $manager->lock()->acquire($attempt['attempt'], 'oauth_pkce');

    orca_respond([
        'ok'            => true,
        'authorize_url' => $attempt['authorize_url'],
        'attempt'       => $attempt['attempt'],
        'expires_at'    => $attempt['expires_at'],
        'scope'         => $attempt['scope'],
    ]);
});

/*
 * PKCE entry, step 2: exchange the code the consent screen displayed.
 */
Flight::route('POST /orcarouter/pkce/exchange', function () {
    if (!orca_csrf_guard()) {
        return;
    }
    $input = orca_input();
    $manager = orcarouter();
    $source = $manager->source(\app\components\OrcaRouter\PkceSource::PROVIDER_ID);
    $pending = $source->flow()->pending();
    $attempt = $pending ? (int) $pending['attempt'] : null;

    try {
        $credential = $source->save([
            'code'  => isset($input['code']) ? $input['code'] : '',
            'state' => isset($input['state']) ? $input['state'] : null,
        ]);
        $manager->setActiveProvider(\app\components\OrcaRouter\PkceSource::PROVIDER_ID);
        // Success releases the login lock.
        $manager->lock()->release($attempt);
    } catch (\app\components\OrcaRouter\OrcaRouterException $e) {
        // Denial, exchange error and expiry all land here and all release the
        // lock, so the panel is never left stuck in a busy state.
        $manager->lock()->release($attempt);
        orca_fail($e->getMessage(), $e->status() ?: 400);

        return;
    }

    orca_respond([
        'ok'         => true,
        'credential' => $credential->toPublicArray(),
        'status'     => $manager->status(),
    ]);
});

/*
 * Every terminal path that is not an exchange: explicit cancel, a page that
 * went away, or a user who switched authentication method.
 *
 * `release()` is attempt-scoped, so a late cancel from an abandoned page can
 * never unlock a newer sign-in.
 */
Flight::route('POST /orcarouter/pkce/cancel', function () {
    if (!orca_csrf_guard()) {
        return;
    }
    $input = orca_input();
    $manager = orcarouter();
    $attempt = isset($input['attempt']) ? (int) $input['attempt'] : null;

    $manager->source(\app\components\OrcaRouter\PkceSource::PROVIDER_ID)->flow()->cancel();
    $released = $manager->lock()->release($attempt);

    orca_respond(['ok' => true, 'released' => $released, 'status' => $manager->status()]);
});

Flight::route('POST /orcarouter/pkce/forget', function () {
    if (!orca_csrf_guard()) {
        return;
    }
    $manager = orcarouter();
    $removed = $manager->source(\app\components\OrcaRouter\PkceSource::PROVIDER_ID)->clear();
    orca_respond(['ok' => true, 'removed' => $removed, 'status' => $manager->status()]);
});

/*
 * Model discovery.
 *
 * The catalog is fetched server-side with the stored key, bounded and filtered
 * per capability, and only minimal model metadata is returned to the browser.
 */
Flight::route('GET /orcarouter/models', function () {
    $manager = orcarouter();
    $capability = Flight::request()->query['capability'];
    $capability = in_array($capability, [
        \app\components\OrcaRouter\ModelCatalog::CAP_CHAT,
        \app\components\OrcaRouter\ModelCatalog::CAP_EMBEDDING,
        \app\components\OrcaRouter\ModelCatalog::CAP_IMAGE,
        \app\components\OrcaRouter\ModelCatalog::CAP_VIDEO,
        \app\components\OrcaRouter\ModelCatalog::CAP_RERANK,
    ], true) ? $capability : \app\components\OrcaRouter\ModelCatalog::CAP_CHAT;

    $modalities = Flight::request()->query['modalities'];
    $modalities = $modalities ? array_filter(array_map('trim', explode(',', $modalities))) : [];

    $result = $manager->catalog()->listFor($capability, $manager->activeCredential(), $modalities);

    orca_respond([
        'ok'         => true,
        'capability' => $capability,
        'source'     => $result['source'],
        'degraded'   => $result['source'] !== \app\components\OrcaRouter\ModelCatalog::SOURCE_LIVE,
        'notice'     => $result['error'],
        'count'      => $result['count'],
        'models'     => $result['models'],
    ]);
});

/*
 * Reject a model that the current capability filter no longer offers, so the
 * UI clears an incompatible selection instead of silently keeping it.
 */
Flight::route('POST /orcarouter/model', function () {
    if (!orca_csrf_guard()) {
        return;
    }
    $input = orca_input();
    $manager = orcarouter();
    $capability = isset($input['capability']) ? $input['capability'] : \app\components\OrcaRouter\ModelCatalog::CAP_CHAT;
    $modalities = isset($input['modalities']) && is_array($input['modalities']) ? $input['modalities'] : [];

    $accepted = $manager->setSelectedModel(
        isset($input['model']) ? $input['model'] : '',
        $capability,
        $modalities
    );

    orca_respond([
        'ok'       => true,
        'accepted' => $accepted,
        'cleared'  => $accepted === null,
        'status'   => $manager->status(),
    ]);
});

/*
 * Inference through the provider adapter.
 *
 * `attachment_modalities` describes what the request actually carries; the
 * model is validated against the same capability filter before anything is
 * sent, and a model that does not declare a required modality is refused.
 */
Flight::route('POST /orcarouter/chat', function () {
    if (!orca_csrf_guard()) {
        return;
    }
    $input = orca_input();
    $manager = orcarouter();

    $credential = $manager->activeCredential();
    if ($credential === null) {
        orca_fail('Connect OrcaRouter first: paste an API key or sign in.', 401);

        return;
    }

    $model = isset($input['model']) ? trim((string) $input['model']) : '';
    $prompt = isset($input['prompt']) ? (string) $input['prompt'] : '';
    $modalities = isset($input['attachment_modalities']) && is_array($input['attachment_modalities'])
        ? $input['attachment_modalities']
        : [];
    $maxTokens = isset($input['max_tokens']) && is_numeric($input['max_tokens'])
        ? max(1, min(4096, (int) $input['max_tokens']))
        : null;

    if ($prompt === '') {
        orca_fail('Enter a prompt first.', 400);

        return;
    }

    // Second layer of protection behind the filtered selector: refuse to send
    // a request whose attachments the chosen model does not declare.
    $allowed = $manager->catalog()
        ->listFor(\app\components\OrcaRouter\ModelCatalog::CAP_CHAT, $credential, $modalities)['models'];
    $match = null;
    foreach ($allowed as $candidate) {
        if ($candidate['id'] === $model) {
            $match = $candidate;
            break;
        }
    }
    if ($match === null) {
        orca_fail('That model is not available for this request. Pick another OrcaRouter model.', 409);

        return;
    }

    try {
        $result = $manager->provider()->chat(
            $credential,
            $model,
            [['role' => 'user', 'content' => $prompt]],
            $modalities,
            $maxTokens
        );
    } catch (\app\components\OrcaRouter\OrcaRouterException $e) {
        orca_fail($e->getMessage(), $e->status() ?: 502, ['status' => $manager->status()]);

        return;
    }

    orca_respond([
        'ok'      => true,
        'model'   => $result['model'],
        'content' => $result['content'],
        'usage'   => $result['usage'],
        'status'  => $manager->status(),
    ]);
});
