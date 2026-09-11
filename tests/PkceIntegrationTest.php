<?php

/*
 * PKCE end-to-end test through the shipped connect adapter.
 *
 * This exercises the whole Flow B path against a local fake consent+exchange
 * server: begin() builds the authorize URL, the "consent screen" issues a code,
 * and PkceSource::save() redeems it through the real exchange code and persists
 * the result. The fake server performs the genuine PKCE verification, so a
 * broken challenge would fail here.
 *
 * No real account, credential or consent is involved.
 *
 * Usage: php tests/PkceIntegrationTest.php
 */

require __DIR__.'/../vendor/autoload.php';

use app\components\OrcaRouter\ApiKeySource;
use app\components\OrcaRouter\Http;
use app\components\OrcaRouter\Manager;
use app\components\OrcaRouter\OrcaRouterException;
use app\components\OrcaRouter\Origins;
use app\components\OrcaRouter\PkceFlow;
use app\components\OrcaRouter\PkceSource;
use app\components\OrcaRouter\SecretStore;

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function ok($label, $condition, $detail = '')
{
    if ($condition) {
        ++$GLOBALS['passed'];
        echo "  ok   $label\n";
    } else {
        ++$GLOBALS['failed'];
        echo "  FAIL $label".($detail !== '' ? " -- $detail" : '')."\n";
    }
}

function same($label, $expected, $actual)
{
    ok($label, $expected === $actual, 'expected '.var_export($expected, true).', got '.var_export($actual, true));
}

function tempDir()
{
    $dir = sys_get_temp_dir().'/orca-pkce-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    return $dir;
}

/* ---------------------------------------------------------------- *
 * Start the fake consent + exchange server on a loopback port.
 * ---------------------------------------------------------------- */

$port = 9400 + random_int(0, 300);
$documentRoot = __DIR__;
$server = proc_open(
    escapeshellarg(PHP_BINARY).' -S 127.0.0.1:'.$port.' '.escapeshellarg($documentRoot.'/fake-auth-server.php'),
    array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "could not start the fake auth server\n");
    exit(2);
}

$base = 'http://127.0.0.1:'.$port;

// Wait for the server to accept connections.
$ready = false;
for ($i = 0; $i < 60; ++$i) {
    usleep(100000);
    $probe = @file_get_contents($base.'/__reset');
    if ($probe !== false) {
        $ready = true;
        break;
    }
}
if (!$ready) {
    proc_terminate($server);
    fwrite(STDERR, "the fake auth server never became ready\n");
    exit(2);
}

function stopServer($server, $base)
{
    @file_get_contents($base.'/__reset');
    proc_terminate($server);
    proc_close($server);
}

/** Fetch a URL even when the server answers 4xx, so the body can be asserted. */
function fetchAllowingErrors($url)
{
    $context = stream_context_create(array('http' => array('ignore_errors' => true, 'timeout' => 10)));

    return @file_get_contents($url, false, $context);
}

function fakeState($base)
{
    return json_decode(file_get_contents($base.'/__state'), true);
}

// The production origins stay untouched; only the auth base is redirected to
// the loopback fake, exactly as ORCA_AUTH_BASE_URL would do.
$origins = Origins::fromConfig(array(), array('ORCA_AUTH_BASE_URL' => $base));
same('the auth origin follows the override', $base, $origins->authBase());
same('the inference origin is unaffected by the auth override',
    'https://api.orcarouter.ai/v1', $origins->apiBase());

/* ---------------------------------------------------------------- *
 * 1. authorize -> code -> exchange -> persist
 * ---------------------------------------------------------------- */

echo "\n== authorize -> displayed code -> exchange -> persist ==\n";

$dir = tempDir();
$store = new SecretStore($dir.'/creds.json');
$manager = new Manager($store, $origins);
$pkce = $manager->source(PkceSource::PROVIDER_ID);

Http::reset();
$attempt = $pkce->flow()->begin();
ok('the authorize URL points at the configured auth origin',
    strpos($attempt['authorize_url'], $base.'/auth?') === 0, $attempt['authorize_url']);

// The "user" opens the consent screen.
$consent = file_get_contents($attempt['authorize_url']);
ok('the consent screen renders', $consent !== false && strpos($consent, 'Code:') !== false);
preg_match('/<code id="code">([^<]+)<\/code>/', (string) $consent, $matches);
$code = isset($matches[1]) ? $matches[1] : '';
ok('the consent screen displayed a code', $code !== '');

// What the client actually put on the wire.
$wire = fakeState($base);
same('authorize sent callback_url=oob', 'oob', $wire['authorize_params']['callback_url']);
same('authorize sent S256', 'S256', $wire['authorize_params']['code_challenge_method']);
same('authorize asked for the api scope', 'api', $wire['authorize_params']['scope']);
same('authorize named the app', 'Ourls', $wire['authorize_params']['app_name']);
ok('authorize sent a state', !empty($wire['authorize_params']['state']));
$challenge = $wire['authorize_params']['code_challenge'];
ok('the challenge is not padding-encoded', strpos($challenge, '=') === false);

// The verifier must be server-side only, so it cannot be in the recorded params.
$pending = $pkce->flow()->pending();
$verifier = $pending['verifier'];
ok('the verifier is still held server-side', $verifier !== '');
ok('the verifier never appeared in the authorize URL',
    strpos($attempt['authorize_url'], $verifier) === false);
foreach ($wire['authorize_params'] as $name => $value) {
    ok('authorize param "'.$name.'" does not carry the verifier', (string) $value !== $verifier);
}
same('the challenge really is S256(verifier)', PkceFlow::challenge($verifier), $challenge);
ok('the verifier differs from the challenge', $verifier !== $challenge);

// Redeem it through the shipped adapter.
$credential = $pkce->save(array('code' => $code));
same('the exchange persisted the issued key', 'sk-orca-FAKEPKCECREDENTIAL', $credential->apiKey());
same('the credential records how it was obtained', 'oauth_pkce', $credential->authType());
same('the granted scope was read back', 'api', $credential->scope());
same('the user id was read back', 'fake-user-1', $credential->userId());
same('the fake server performed a PKCE verification for this exchange',
    true, fakeState($base)['last_exchange']['has_verifier']);
same('the exchange declared S256', 'S256', fakeState($base)['last_exchange']['method']);

// Persistence.
$reloaded = (new Manager(new SecretStore($dir.'/creds.json'), $origins))
    ->source(PkceSource::PROVIDER_ID)->credential();
ok('the credential survives a fresh process-level reload', $reloaded !== null);
same('the reloaded key matches', 'sk-orca-FAKEPKCECREDENTIAL', $reloaded->apiKey());
ok('the reloaded credential is usable', $reloaded->isUsable());

// The stored file must not expose the key.
$raw = file_get_contents($store->path());
ok('the credential file holds no plaintext key', strpos($raw, 'FAKEPKCECREDENTIAL') === false);

/* ---------------------------------------------------------------- *
 * 2. A code cannot be redeemed twice
 * ---------------------------------------------------------------- */

echo "\n== a displayed code is single use ==\n";

Http::reset();
$attempt2 = $pkce->flow()->begin();
$consent2 = file_get_contents($attempt2['authorize_url']);
preg_match('/<code id="code">([^<]+)<\/code>/', (string) $consent2, $m2);
$code2 = $m2[1];
$pkce->save(array('code' => $code2));
$reused = null;
try {
    $pkce->flow()->begin();
    $pkce->save(array('code' => $code2));
} catch (OrcaRouterException $e) {
    $reused = $e;
}
ok('the fake server rejects a reused code', $reused !== null);
same('the reuse is reported as a rejected code', 403, $reused->status());

/* ---------------------------------------------------------------- *
 * 3. A wrong verifier is refused
 * ---------------------------------------------------------------- */

echo "\n== a tampered verifier is refused by the exchange ==\n";

Http::reset();
$wrongStore = new SecretStore(tempDir().'/creds.json');
$wrongFlow = new PkceFlow($origins, $wrongStore);
$attempt3 = $wrongFlow->begin();
$consent3 = file_get_contents($attempt3['authorize_url']);
preg_match('/<code id="code">([^<]+)<\/code>/', (string) $consent3, $m3);
// Corrupt the stored verifier: the challenge on the wire no longer matches it.
$wrongStore->putState(PkceFlow::PENDING_STATE_KEY, array_merge(
    $wrongFlow->pending(),
    array('verifier' => 'tampered-verifier-value')
));
$refused = null;
try {
    $wrongFlow->exchange($m3[1]);
} catch (OrcaRouterException $e) {
    $refused = $e;
}
ok('a verifier that does not hash to the challenge is refused', $refused !== null);
same('the refusal is reported as a rejected code', 403, $refused->status());
ok('the refusal message never echoes a verifier',
    strpos($refused->getMessage(), 'tampered-verifier-value') === false);

/* ---------------------------------------------------------------- *
 * 4. Denial leaves no credential and releases the lock
 * ---------------------------------------------------------------- */

echo "\n== denial, and a broken auth origin, end cleanly ==\n";

Http::reset();
$denyStore = new SecretStore(tempDir().'/creds.json');
$denyManager = new Manager($denyStore, Origins::fromConfig(array(), array('ORCA_AUTH_BASE_URL' => $base)));
$denyManager->source(PkceSource::PROVIDER_ID)->flow()->begin();
$denied = null;
try {
    $denyManager->source(PkceSource::PROVIDER_ID)->save(array('code' => 'FAKE-CODE-unknown-value'));
} catch (OrcaRouterException $e) {
    $denied = $e;
}
ok('an unknown code is refused', $denied !== null);
ok('no credential was stored for the denial', $denyManager->source(PkceSource::PROVIDER_ID)->credential() === null);
ok('the pending attempt was released by the denial', $denyManager->source(PkceSource::PROVIDER_ID)->flow()->pending() === null);

// An unreachable auth origin must fail with a network message, not hang or hot-loop.
Http::reset();
$deadOrigins = Origins::fromConfig(array(), array('ORCA_AUTH_BASE_URL' => 'http://127.0.0.1:1'));
$deadFlow = new PkceFlow($deadOrigins, new SecretStore(tempDir().'/creds.json'));
$deadFlow->begin();
$dead = null;
$startedAt = microtime(true);
try {
    $deadFlow->exchange('FAKE-CODE-whatever');
} catch (OrcaRouterException $e) {
    $dead = $e;
}
$elapsed = microtime(true) - $startedAt;
ok('an unreachable auth origin produces an error', $dead !== null);
ok('it reports a network problem rather than an auth rejection',
    stripos($dead->getMessage(), 'network') !== false, $dead->getMessage());
ok('it returns promptly instead of hanging', $elapsed < 15, $elapsed.'s');
ok('the pending attempt is released even on a transport failure', $deadFlow->pending() === null);

/* ---------------------------------------------------------------- *
 * 5. The API-key path is independent and needs no browser
 * ---------------------------------------------------------------- */

echo "\n== the API-key entry works without any PKCE traffic ==\n";

$before = fakeState($base)['authorize_requests'];
$seamStore = new SecretStore(tempDir().'/creds.json');
$seamManager = new Manager($seamStore, $origins);
$pasted = $seamManager->source(ApiKeySource::PROVIDER_ID)->save(array('api_key' => 'sk-orca-PASTEDWITHOUTBROWSER'));
same('the API-key entry stores the pasted key', 'sk-orca-PASTEDWITHOUTBROWSER', $pasted->apiKey());
same('no authorize request was made by the API-key entry', $before, fakeState($base)['authorize_requests']);
ok('the PKCE entry holds no credential after the API-key path',
    $seamManager->source(PkceSource::PROVIDER_ID)->credential() === null);

/* ---------------------------------------------------------------- *
 * 6. Hostile callback URLs are rejected before a code is minted
 * ---------------------------------------------------------------- */

echo "\n== the consent server refuses weak or missing challenges ==\n";

$noChallenge = fetchAllowingErrors($base.'/auth?callback_url=oob&app_name=Ourls');
ok('a request with no challenge is refused', strpos((string) $noChallenge, 'code_challenge is required') !== false);

$plainChallenge = fetchAllowingErrors($base.'/auth?callback_url=oob&code_challenge=abc&code_challenge_method=plain');
ok('a request with a plain challenge is refused',
    strpos((string) $plainChallenge, 'must be S256') !== false);

stopServer($server, $base);

echo "\n".($GLOBALS['failed'] === 0
    ? 'ALL '.$GLOBALS['passed']." PKCE INTEGRATION TESTS PASSED\n"
    : $GLOBALS['failed'].' of '.($GLOBALS['passed'] + $GLOBALS['failed'])." PKCE INTEGRATION TESTS FAILED\n");
echo 'passed='.$GLOBALS['passed'].' failed='.$GLOBALS['failed']."\n";
exit($GLOBALS['failed'] === 0 ? 0 : 1);
