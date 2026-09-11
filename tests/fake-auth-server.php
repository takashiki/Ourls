<?php

/*
 * A stand-in for the OrcaRouter consent + exchange endpoints.
 *
 * Used only by tests/PkceIntegrationTest.php, which runs it on loopback so the
 * PKCE flow can be exercised end to end without a human approving anything on
 * the real service. It performs the real PKCE verification: the challenge from
 * the authorize request is stored, and the exchange is only accepted when
 * S256(code_verifier) matches it.
 *
 * Run: php -S 127.0.0.1:<port> tests/fake-auth-server.php
 */

$stateFile = sys_get_temp_dir().'/orca-fake-auth-state.json';

function loadState($file)
{
    $raw = @file_get_contents($file);

    return $raw ? (json_decode($raw, true) ?: array()) : array();
}

function saveState($file, array $state)
{
    file_put_contents($file, json_encode($state));
}

function b64url($raw)
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = loadState($stateFile);

if ($path === '/auth') {
    // Record exactly what the client sent, so the test can assert what was and
    // was not put on the wire.
    $params = $_GET;
    $state['authorize_params'] = $params;
    $state['authorize_requests'] = isset($state['authorize_requests']) ? $state['authorize_requests'] + 1 : 1;

    // The challenge is mandatory and must be S256 for a displayed code.
    if (!isset($params['code_challenge']) || $params['code_challenge'] === '') {
        saveState($stateFile, $state);
        http_response_code(400);
        echo 'code_challenge is required';
        exit;
    }
    if (!isset($params['code_challenge_method']) || $params['code_challenge_method'] !== 'S256') {
        saveState($stateFile, $state);
        http_response_code(400);
        echo 'code_challenge_method must be S256';
        exit;
    }

    // The consent screen: mint a one-time code and display it to the "user".
    $code = 'FAKE-CODE-'.b64url(random_bytes(9));
    $state['codes'][$code] = array(
        'challenge' => $params['code_challenge'],
        'method' => $params['code_challenge_method'],
        'scope' => isset($params['scope']) ? $params['scope'] : 'api',
        'used' => false,
    );
    saveState($stateFile, $state);

    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body><h1>Authorize '.htmlspecialchars(isset($params['app_name']) ? $params['app_name'] : 'app').'</h1>';
    echo '<p>Code: <code id="code">'.htmlspecialchars($code).'</code></p></body></html>';
    exit;
}

if ($path === '/api/v1/auth/keys') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        parse_str($raw, $body);
    }
    header('Content-Type: application/json');

    $code = isset($body['code']) ? $body['code'] : '';
    $verifier = isset($body['code_verifier']) ? $body['code_verifier'] : '';
    $method = isset($body['code_challenge_method']) ? $body['code_challenge_method'] : '';

    $state['exchange_requests'] = isset($state['exchange_requests']) ? $state['exchange_requests'] + 1 : 1;
    $state['last_exchange'] = array(
        'has_code' => $code !== '',
        'has_verifier' => $verifier !== '',
        'method' => $method,
    );

    if ($code === '') {
        saveState($stateFile, $state);
        http_response_code(400);
        echo json_encode(array('error' => 'code is required'));
        exit;
    }
    if (!isset($state['codes'][$code])) {
        saveState($stateFile, $state);
        http_response_code(403);
        echo json_encode(array('error' => 'unknown code'));
        exit;
    }

    $record = $state['codes'][$code];

    // Single use.
    if ($record['used']) {
        saveState($stateFile, $state);
        http_response_code(403);
        echo json_encode(array('error' => 'code already used'));
        exit;
    }

    // Downgrade defence: the method must match what was sent at authorize time.
    if ($method !== $record['method']) {
        saveState($stateFile, $state);
        http_response_code(400);
        echo json_encode(array('error' => 'code_challenge_method mismatch'));
        exit;
    }

    // The real PKCE check.
    if ($verifier === '' || !hash_equals($record['challenge'], b64url(hash('sha256', $verifier, true)))) {
        saveState($stateFile, $state);
        http_response_code(403);
        echo json_encode(array('error' => 'verifier does not match challenge'));
        exit;
    }

    $state['codes'][$code]['used'] = true;
    $state['last_accepted_scope'] = $record['scope'];
    saveState($stateFile, $state);

    echo json_encode(array(
        'key' => 'sk-orca-FAKEPKCECREDENTIAL',
        'user_id' => 'fake-user-1',
        'scope' => $record['scope'],
    ));
    exit;
}

if ($path === '/__state') {
    header('Content-Type: application/json');
    echo json_encode($state);
    exit;
}

if ($path === '/__reset') {
    @unlink($stateFile);
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}

http_response_code(404);
echo 'not found';
