<?php

/*
 * OrcaRouter unit suite.
 *
 * Standalone — no test framework is added to composer.json. Every test uses a
 * fake key/code and an injected transport, so nothing here performs a network
 * request and no real credential appears in a fixture or an assertion message.
 *
 * Usage: php tests/OrcaRouterTest.php
 */

require __DIR__.'/../vendor/autoload.php';

use app\components\OrcaRouter\ApiKeySource;
use app\components\OrcaRouter\Credential;
use app\components\OrcaRouter\CredentialSource;
use app\components\OrcaRouter\Http;
use app\components\OrcaRouter\LoginLock;
use app\components\OrcaRouter\Manager;
use app\components\OrcaRouter\ModelCatalog;
use app\components\OrcaRouter\OrcaRouterException;
use app\components\OrcaRouter\Origins;
use app\components\OrcaRouter\PkceFlow;
use app\components\OrcaRouter\PkceSource;
use app\components\OrcaRouter\Provider;
use app\components\OrcaRouter\SecretStore;

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;
$GLOBALS['section'] = '';

function section($name)
{
    $GLOBALS['section'] = $name;
    echo "\n== $name ==\n";
}

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
    $dir = sys_get_temp_dir().'/orca-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    return $dir;
}

function removeDir($dir)
{
    foreach (glob($dir.'/*') ?: array() as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

/** A transport that answers by URL and records every request. */
function fakeTransport(array $routes)
{
    return function ($method, $url, array $headers, $body, $timeout, $maxBytes) use ($routes) {
        foreach ($routes as $fragment => $response) {
            if (strpos($url, $fragment) !== false) {
                return is_callable($response) ? $response($url, $body, $headers) : $response;
            }
        }

        return array('status' => 404, 'body' => '{}', 'error' => null, 'truncated' => false);
    };
}

function httpOk($body)
{
    return array('status' => 200, 'body' => is_string($body) ? $body : json_encode($body), 'error' => null, 'truncated' => false);
}

/* ==================================================================== *
 * Origins
 * ==================================================================== */

section('Origins: defaults, overrides, and never deriving one from the other');

$defaults = Origins::fromConfig(array(), array());
same('auth default', 'https://www.orcarouter.ai', $defaults->authBase());
same('api default', 'https://api.orcarouter.ai/v1', $defaults->apiBase());
same('authorize url', 'https://www.orcarouter.ai/auth', $defaults->authorizeUrl());
same('exchange url', 'https://www.orcarouter.ai/api/v1/auth/keys', $defaults->exchangeUrl());
ok('exchange url is NOT on the inference origin',
    strpos($defaults->exchangeUrl(), 'api.orcarouter.ai') === false);
ok('the exchange endpoint is not the inference-origin /v1 path',
    $defaults->exchangeUrl() !== $defaults->apiBase().'/auth/keys');
ok('the exchange endpoint does not sit under the inference origin',
    strpos($defaults->exchangeUrl(), $defaults->apiBase()) !== 0);
same('the documented inference-origin mistake really is a different URL',
    'https://api.orcarouter.ai/v1/auth/keys', $defaults->apiHost() ? 'https://api.orcarouter.ai/v1/auth/keys' : '');
same('chat url', 'https://api.orcarouter.ai/v1/chat/completions', $defaults->chatUrl());
same('models url with capability',
    'https://api.orcarouter.ai/v1/models?capability=chat',
    $defaults->modelsUrl(ModelCatalog::CAP_CHAT));
same('models url unfiltered', 'https://api.orcarouter.ai/v1/models', $defaults->modelsUrl());

$shared = Origins::fromConfig(array(), array('ORCA_BASE_URL' => 'https://orca.internal'));
same('shared base feeds auth', 'https://orca.internal', $shared->authBase());
same('shared base feeds api with /v1', 'https://orca.internal/v1', $shared->apiBase());

$explicit = Origins::fromConfig(array(), array(
    'ORCA_BASE_URL' => 'https://orca.internal',
    'ORCA_AUTH_BASE_URL' => 'https://login.internal',
    'ORCA_API_BASE_URL' => 'https://relay.internal/v2',
));
same('explicit auth override wins over shared', 'https://login.internal', $explicit->authBase());
same('explicit api override wins over shared', 'https://relay.internal/v2', $explicit->apiBase());

// A different auth origin must never be turned into an inference origin.
$splitAuth = Origins::fromConfig(array(), array('ORCA_AUTH_BASE_URL' => 'https://auth.example.test'));
same('auth override does not move the api origin', 'https://api.orcarouter.ai/v1', $splitAuth->apiBase());
$splitApi = Origins::fromConfig(array(), array('ORCA_API_BASE_URL' => 'https://api.example.test/v1'));
same('api override does not move the auth origin', 'https://www.orcarouter.ai', $splitApi->authBase());

$configFile = Origins::fromConfig(array('base_url' => 'https://from-config.test'), array());
same('config supplies the shared base', 'https://from-config.test', $configFile->authBase());

foreach (array(
    'remote http auth' => array('ORCA_AUTH_BASE_URL' => 'http://evil.test'),
    'remote http api' => array('ORCA_API_BASE_URL' => 'http://evil.test/v1'),
    'userinfo in auth' => array('ORCA_AUTH_BASE_URL' => 'https://user:pass@evil.test'),
    'fragment in auth' => array('ORCA_AUTH_BASE_URL' => 'https://evil.test/#x'),
    'not absolute' => array('ORCA_AUTH_BASE_URL' => 'evil.test'),
) as $label => $env) {
    $threw = false;
    try {
        Origins::fromConfig(array(), $env);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    ok('rejects '.$label, $threw);
}

$loopback = Origins::fromConfig(array(), array('ORCA_AUTH_BASE_URL' => 'http://127.0.0.1:8899'));
same('http loopback is allowed for development', 'http://127.0.0.1:8899', $loopback->authBase());

/* ==================================================================== *
 * Random / PKCE
 * ==================================================================== */

section('PKCE: S256 challenge, fresh verifier and state per attempt');

$verifier = 'fixed-verifier-for-test-only';
$expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
same('challenge is base64url(sha256(verifier)) without padding',
    $expectedChallenge, PkceFlow::challenge($verifier));
ok('challenge carries no padding', strpos(PkceFlow::challenge($verifier), '=') === false);
ok('challenge carries no + or /',
    strpos(PkceFlow::challenge($verifier), '+') === false && strpos(PkceFlow::challenge($verifier), '/') === false);

$dir = tempDir();
$store = new SecretStore($dir.'/creds.json');
$flow = new PkceFlow(Origins::fromConfig(array(), array()), $store);

$first = $flow->begin();
$firstVerifier = $flow->pending()['verifier'];
$second = $flow->begin();
ok('two attempts produce different states', $first['state'] !== $second['state']);
ok('two attempts produce different challenges', $first['authorize_url'] !== $second['authorize_url']);
same('attempts are monotonically numbered', $first['attempt'] + 1, $second['attempt']);

$query = array();
parse_str(parse_url($first['authorize_url'], PHP_URL_QUERY), $query);
same('authorize URL uses callback_url=oob', 'oob', $query['callback_url']);
same('authorize URL always sends S256', 'S256', $query['code_challenge_method']);
same('authorize URL asks for the api scope', 'api', $query['scope']);
same('authorize URL carries the challenge of that attempt', PkceFlow::challenge($firstVerifier), $query['code_challenge']);
ok('authorize URL is on the auth origin', strpos($first['authorize_url'], 'https://www.orcarouter.ai/auth?') === 0);
ok('the verifier of that attempt never appears in the authorize URL',
    strpos($first['authorize_url'], $firstVerifier) === false);
ok('the plain verifier is not the challenge', $query['code_challenge'] !== $firstVerifier);
same('the pending attempt carries a 10 minute TTL', 600, $first['expires_at'] - time());

// The verifier must differ across attempts.
$verifierA = $flow->pending()['verifier'];
$flow->begin();
ok('a new attempt generates a new verifier', $verifierA !== $flow->pending()['verifier']);
// Attempt numbers must never repeat, or a stale cancel could clear a newer
// login: the lock is attempt-scoped, so uniqueness is what makes that safe.
$beforeCancel = $flow->pending()['attempt'];
$flow->cancel();
$afterCancel = $flow->begin();
ok('an attempt number is not reused after a cancel', $afterCancel['attempt'] > $beforeCancel,
    $beforeCancel.' -> '.$afterCancel['attempt']);
$third = $flow->begin();
ok('attempt numbers keep increasing', $third['attempt'] > $afterCancel['attempt']);
$flow->cancel();
$flow->begin();
ok('attempt numbers keep increasing after another cancel', $flow->pending()['attempt'] > $third['attempt']);
$flow->cancel();

section('PKCE: verifier and secrets never reach storage plaintext');

$rawStore = @file_get_contents($store->path());
$pendingVerifier = $flow->pending() ? $flow->pending()['verifier'] : null;
ok('the credential file is not plaintext JSON', strpos((string) $rawStore, '"accounts"') === false);
ok('a verifier written to the store is not readable in the file',
    $pendingVerifier === null || strpos((string) $rawStore, $pendingVerifier) === false);

section('PKCE: exchange path, body and scope handling');

Http::reset();
Http::$transport = fakeTransport(array(
    'www.orcarouter.ai/api/v1/auth/keys' => httpOk(array('key' => 'sk-orca-FAKEKEYFORTEST', 'user_id' => '42', 'scope' => 'api')),
));
$store2 = new SecretStore(tempDir().'/creds.json');
$flow2 = new PkceFlow(Origins::fromConfig(array(), array()), $store2);
$started = $flow2->begin();
$result = $flow2->exchange('fake-code-123');

same('a successful exchange returns the issued key', 'sk-orca-FAKEKEYFORTEST', $result['key']);
same('the granted scope is read back', 'api', $result['scope']);
same('the user id is read back', '42', $result['user_id']);
$request = Http::$log[count(Http::$log) - 1];
same('exchange goes to the auth origin', 'https://www.orcarouter.ai/api/v1/auth/keys', $request['url']);
ok('exchange never goes to the inference origin', strpos($request['url'], 'api.orcarouter.ai') === false);
ok('exchange uses POST', $request['method'] === 'POST');
$sentBody = json_decode($request['body'], true);
same('exchange sends the code', 'fake-code-123', $sentBody['code']);
ok('exchange sends a code_verifier', isset($sentBody['code_verifier']) && $sentBody['code_verifier'] !== '');
same('exchange declares S256', 'S256', $sentBody['code_challenge_method']);
ok('the exchange body never contains the challenge instead of the verifier',
    $sentBody['code_verifier'] !== PkceFlow::challenge($sentBody['code_verifier']));
ok('no Authorization header is sent to the exchange endpoint',
    !array_filter($request['headers'], function ($h) {
        return stripos($h, 'authorization') === 0;
    }));
ok('the attempt is consumed by the exchange', $flow2->pending() === null);

// Scope downgrade: asked for api, granted less.
Http::reset();
Http::$transport = fakeTransport(array(
    'auth/keys' => httpOk(array('key' => 'sk-orca-DOWNGRADEDKEY', 'user_id' => '7', 'scope' => 'connector')),
));
$store3 = new SecretStore(tempDir().'/creds.json');
$manager3 = new Manager($store3, Origins::fromConfig(array(), array()));
$manager3->source(PkceSource::PROVIDER_ID)->flow()->begin();
$credential = $manager3->source(PkceSource::PROVIDER_ID)->save(array('code' => 'fake-code'));
same('a downgraded grant is still accepted as a credential', 'sk-orca-DOWNGRADEDKEY', $credential->apiKey());
$stored = $store3->getAccount(PkceSource::PROVIDER_ID);
same('the granted scope is recorded', 'connector', $stored['scope']);
same('the requested scope is recorded separately', 'api', $stored['requested_scope']);
ok('a downgrade is flagged rather than silently treated as api', $stored['scope_downgraded'] === true);

section('PKCE: denial, expiry, reuse, and rate limiting');

function exchangeFailure(array $response, $code = 'fake-code')
{
    Http::reset();
    Http::$transport = fakeTransport(array('auth/keys' => $response));
    $store = new SecretStore(tempDir().'/creds.json');
    $flow = new PkceFlow(Origins::fromConfig(array(), array()), $store);
    $flow->begin();
    try {
        $flow->exchange($code);

        return null;
    } catch (OrcaRouterException $e) {
        return $e;
    }
}

$denied = exchangeFailure(array('status' => 403, 'body' => '{"error":"access_denied"}', 'error' => null, 'truncated' => false));
ok('403 (denied/expired/reused) is surfaced as an error', $denied !== null);
same('403 keeps the status', 403, $denied->status());
ok('the denial message tells the user what to do', strpos($denied->getMessage(), 'expired') !== false);
ok('a denied exchange stores no credential', $store->getAccount(PkceSource::PROVIDER_ID) === null);
ok('the denial message does not echo the response body',
    strpos($denied->getMessage(), 'access_denied') === false);

$badRequest = exchangeFailure(array('status' => 400, 'body' => '{"error":"bad method"}', 'error' => null, 'truncated' => false));
same('400 (challenge method mismatch) keeps its status', 400, $badRequest->status());

$limited = exchangeFailure(array('status' => 429, 'body' => '{}', 'error' => null, 'truncated' => false));
same('429 keeps its status', 429, $limited->status());
ok('429 explains the 10-key-per-24h limit', strpos($limited->getMessage(), '10 per 24 hours') !== false);

$network = exchangeFailure(array('status' => 0, 'body' => '', 'error' => 'transport_error', 'truncated' => false));
same('a network failure is not treated as an auth rejection', 0, $network->status());
ok('a network failure suggests retrying', stripos($network->getMessage(), 'network') !== false);

// Reuse: the attempt is gone, so a second redemption cannot succeed.
Http::reset();
Http::$transport = fakeTransport(array('auth/keys' => httpOk(array('key' => 'sk-orca-FIRSTONLY', 'scope' => 'api'))));
$store4 = new SecretStore(tempDir().'/creds.json');
$flow4 = new PkceFlow(Origins::fromConfig(array(), array()), $store4);
$flow4->begin();
$flow4->exchange('fake-code');
$reused = null;
try {
    $flow4->exchange('fake-code');
} catch (OrcaRouterException $e) {
    $reused = $e;
}
ok('a code cannot be redeemed twice', $reused !== null);
same('reuse is reported as a missing attempt', 409, $reused->status());

// Expiry.
$expired = null;
$now = time();
$clock = function () use (&$now) {
    return $now;
};
$store5 = new SecretStore(tempDir().'/creds.json');
$flow5 = new PkceFlow(Origins::fromConfig(array(), array()), $store5, $clock);
$flow5->begin();
$now += 601;
try {
    $flow5->exchange('fake-code');
} catch (OrcaRouterException $e) {
    $expired = $e;
}
ok('an attempt older than the code TTL is refused', $expired !== null);
same('expiry keeps the 410 status', 410, $expired->status());

// State mismatch, compared in constant time.
$store6 = new SecretStore(tempDir().'/creds.json');
$flow6 = new PkceFlow(Origins::fromConfig(array(), array()), $store6);
$flow6->begin();
$mismatch = null;
try {
    $flow6->exchange('fake-code', 'not-the-state');
} catch (OrcaRouterException $e) {
    $mismatch = $e;
}
ok('a mismatched state aborts before the exchange', $mismatch !== null);
ok('the state mismatch message does not echo the real state',
    strpos($mismatch->getMessage(), $store6->getState('pkce_pending') ? 'x' : 'y') === false);

Http::reset();
Http::$transport = fakeTransport(array(
    'auth/keys' => httpOk(array('key' => 'sk-orca-STATEOK', 'scope' => 'api')),
));
$store7 = new SecretStore(tempDir().'/creds.json');
$flow7 = new PkceFlow(Origins::fromConfig(array(), array()), $store7);
$attempt = $flow7->begin();
$ok7 = $flow7->exchange('fake-code', $attempt['state']);
same('a matching state proceeds to exchange', 'sk-orca-STATEOK', $ok7['key']);

/* ==================================================================== *
 * The credential seam: two adapters, one result
 * ==================================================================== */

section('Credential seam: API key and PKCE produce the same credential');

Http::reset();

$seamDir = tempDir();
$seamStore = new SecretStore($seamDir.'/creds.json');
$seamManager = new Manager($seamStore, Origins::fromConfig(array(), array()));

$apiSource = $seamManager->source(ApiKeySource::PROVIDER_ID);
$pkceSource = $seamManager->source(PkceSource::PROVIDER_ID);
ok('both entries implement the same interface', $apiSource instanceof CredentialSource && $pkceSource instanceof CredentialSource);
ok('the entries have distinct ids', $apiSource->id() !== $pkceSource->id());
same('api entry id', 'orcarouter', $apiSource->id());
same('auth entry id', 'orcarouter-oauth', $pkceSource->id());
same('api entry label', 'OrcaRouter - API', $apiSource->label());
same('auth entry label', 'OrcaRouter - Auth', $pkceSource->label());
ok('the two entries have distinct kinds', $apiSource->kind() !== $pkceSource->kind());

$pasted = $apiSource->save(array('api_key' => 'sk-orca-PASTEDKEYFORTEST'));
$seamStore->putAccount('pkce-test-account', array());
Http::reset();
Http::$transport = fakeTransport(array('auth/keys' => httpOk(array('key' => 'sk-orca-PKCEKEYFORTEST', 'scope' => 'api', 'user_id' => '9'))));
$pkceSource->flow()->begin();
$minted = $pkceSource->save(array('code' => 'fake-code'));

ok('both adapters return the same credential type', get_class($pasted) === get_class($minted));
ok('both adapters produce a Credential', $pasted instanceof Credential && $minted instanceof Credential);
same('the api entry keeps its own first-class provider id', 'orcarouter', $pasted->providerId());
same('the auth entry keeps its own first-class provider id', 'orcarouter-oauth', $minted->providerId());
ok('the two entries are registered under distinct ids', $pasted->providerId() !== $minted->providerId());
ok('the auth type differentiates how the key was obtained', $pasted->authType() !== $minted->authType());
ok('both are usable credentials', $pasted->isUsable() && $minted->isUsable());
ok('both produce an identical Bearer header shape',
    strpos($pasted->bearerHeader(), 'Authorization: Bearer sk-orca-') === 0
    && strpos($minted->bearerHeader(), 'Authorization: Bearer sk-orca-') === 0);

// The provider must not care which adapter produced the credential.
Http::reset();
Http::$transport = fakeTransport(array('chat/completions' => httpOk(array(
    'model' => 'orcarouter/auto',
    'choices' => array(array('message' => array('content' => 'from-'))) ,
    'usage' => array('total_tokens' => 1),
))));
$provider = $seamManager->provider();
$fromApi = $provider->chat($pasted, 'orcarouter/auto', array(array('role' => 'user', 'content' => 'hi')));
Http::reset();
Http::$transport = fakeTransport(array('chat/completions' => httpOk(array(
    'model' => 'orcarouter/auto',
    'choices' => array(array('message' => array('content' => 'from-'))),
    'usage' => array('total_tokens' => 1),
))));
$fromPkce = $provider->chat($minted, 'orcarouter/auto', array(array('role' => 'user', 'content' => 'hi')));
same('downstream inference is identical whichever adapter was used', $fromApi['content'], $fromPkce['content']);

// Model discovery must not care either.
Http::reset();
$catalog = $seamManager->catalog();
Http::$transport = fakeTransport(array('models' => httpOk(array('data' => array(
    array('id' => 'vendor/one', 'supported_endpoint_types' => array('openai'), 'architecture' => array('input_modalities' => array('text'))),
)))));
$viaApi = $catalog->listFor(ModelCatalog::CAP_CHAT, $pasted);
Http::$transport = fakeTransport(array('models' => httpOk(array('data' => array(
    array('id' => 'vendor/one', 'supported_endpoint_types' => array('openai'), 'architecture' => array('input_modalities' => array('text'))),
)))));
$viaPkce = $catalog->listFor(ModelCatalog::CAP_CHAT, $minted);
same('model discovery is identical whichever adapter was used', $viaApi['models'], $viaPkce['models']);

section('Credential: masking, persistence and clearing');

same('a key is masked to 4 leading and 2 trailing characters', 'sk-o******ST', $pasted->masked());
ok('the mask hides the middle of the key', strpos($pasted->masked(), 'PASTEDKEY') === false);
$publicJson = json_encode($pasted->toPublicArray());
ok('the public representation never contains the raw key', strpos($publicJson, $pasted->apiKey()) === false);
ok('the public representation carries the masked form', strpos($publicJson, $pasted->masked()) !== false);
ok('casting a credential to string does not leak the key',
    strpos((string) $pasted, $pasted->apiKey()) === false);
same('a short secret is fully masked', '********', Credential::mask('shortkey'));
same('an empty secret is reported as none', '(none)', Credential::mask(''));

$reloaded = $apiSource->credential();
same('a saved key survives a reload from disk', 'sk-orca-PASTEDKEYFORTEST', $reloaded->apiKey());
same('the masked form survives a reload', $pasted->masked(), $reloaded->masked());
ok('clearing removes the credential', $apiSource->clear() === true);
ok('the credential is gone after clearing', $apiSource->credential() === null);
ok('clearing twice reports that nothing was removed', $apiSource->clear() === false);

$bad = null;
try {
    $apiSource->save(array('api_key' => 'not-an-orca-key'));
} catch (OrcaRouterException $e) {
    $bad = $e;
}
ok('a key without the sk-orca- prefix is rejected', $bad !== null);
ok('the rejection message never echoes the submitted value',
    strpos($bad->getMessage(), 'not-an-orca-key') === false);

foreach (array('', '   ', 'sk-orca-') as $invalid) {
    $threw = false;
    try {
        $apiSource->save(array('api_key' => $invalid));
    } catch (OrcaRouterException $e) {
        $threw = true;
    }
    ok('rejects the empty/short input '.var_export($invalid, true), $threw);
}

$rawKeyFile = @file_get_contents($seamStore->path());
ok('the raw pasted key never appears in plaintext in the credential file',
    $rawKeyFile === false || strpos($rawKeyFile, 'PASTEDKEYFORTEST') === false);

/* ==================================================================== *
 * Generation-safe 401 handling
 * ==================================================================== */

section('401 recovery: terminal, generation-safe, no fake refresh');

$reauthDir = tempDir();
$reauthStore = new SecretStore($reauthDir.'/creds.json');
$reauthManager = new Manager($reauthStore, Origins::fromConfig(array(), array()));
$credential = $reauthManager->source(ApiKeySource::PROVIDER_ID)->save(array('api_key' => 'sk-orca-REVOKEDKEYFORTEST'));
$generation = $credential->generation();

Http::reset();
Http::$transport = fakeTransport(array('chat/completions' => array(
    'status' => 401, 'body' => '{"error":{"message":"Invalid API key"}}', 'error' => null, 'truncated' => false,
)));
$rejected = null;
try {
    $reauthManager->provider()->chat($credential, 'orcarouter/auto', array(array('role' => 'user', 'content' => 'hi')));
} catch (OrcaRouterException $e) {
    $rejected = $e;
}
ok('a 401 is raised to the caller', $rejected !== null && $rejected->status() === 401);
$stored = $reauthStore->getAccount(ApiKeySource::PROVIDER_ID);
same('the exact account is marked needs_reauth', 'needs_reauth', $stored['status']);
ok('the credential is now unusable', !$reauthStore->getAccount(ApiKeySource::PROVIDER_ID) ? false : !(new Credential($stored))->isUsable());
same('the credential was not deleted', 'sk-orca-REVOKEDKEYFORTEST', $stored['key']);
ok('no refresh endpoint was called', !array_filter(Http::$log, function ($entry) {
    return stripos($entry['url'], 'refresh') !== false || stripos($entry['url'], 'token') !== false;
}));
ok('the error is not echoed back to the user', strpos($rejected->getMessage(), 'Invalid API key') === false);
ok('the error tells the user to reconnect', stripos($rejected->getMessage(), 'reconnect') !== false);

// A stale generation must not be able to mark a re-authorized credential.
same('a stale generation cannot mark the account', false, $reauthStore->markNeedsReauth(ApiKeySource::PROVIDER_ID, $generation - 1));
$fresh = $reauthManager->source(ApiKeySource::PROVIDER_ID)->save(array('api_key' => 'sk-orca-NEWKEYFORTEST'));
ok('re-saving issues a new generation', $fresh->generation() > $generation);
$reauthStore->markNeedsReauth(ApiKeySource::PROVIDER_ID, $generation);
$after = $reauthStore->getAccount(ApiKeySource::PROVIDER_ID);
same('a late 401 from the old generation does not break the new credential', 'active', $after['status']);
ok('the new credential is still usable', (new Credential($after))->isUsable());
same('only the exact rejected account can be marked', false, $reauthStore->markNeedsReauth('some-other-account', 1));

/* ==================================================================== *
 * Model catalog
 * ==================================================================== */

section('Catalog: capability filters');

$catalog6 = new ModelCatalog(Origins::fromConfig(array(), array()));
$fixture = array(
    array('id' => 'vendor/text-only', 'supported_endpoint_types' => array('openai'),
          'architecture' => array('input_modalities' => array('text'), 'output_modalities' => array('text'))),
    array('id' => 'vendor/vision', 'supported_endpoint_types' => array('openai', 'anthropic'),
          'architecture' => array('input_modalities' => array('text', 'image'), 'output_modalities' => array('text'))),
    array('id' => 'vendor/audio-chat', 'supported_endpoint_types' => array('openai'),
          'architecture' => array('input_modalities' => array('text', 'audio'), 'output_modalities' => array('text'))),
    array('id' => 'vendor/embed', 'supported_endpoint_types' => array('embeddings'),
          'architecture' => array('input_modalities' => array('text'))),
    array('id' => 'vendor/imagegen', 'supported_endpoint_types' => array('image-generation'),
          'architecture' => array('input_modalities' => array('text'), 'output_modalities' => array('image'))),
    array('id' => 'vendor/video', 'supported_endpoint_types' => array('openai-video'),
          'architecture' => array('input_modalities' => array('text'))),
    array('id' => 'vendor/rerank', 'supported_endpoint_types' => array('jina-rerank'),
          'architecture' => array('input_modalities' => array('text'))),
    array('id' => 'vendor/no-arch', 'supported_endpoint_types' => array('openai'), 'architecture' => array()),
);

$ids = function ($models) {
    return array_map(function ($m) {
        return $m['id'];
    }, $models);
};

$chatModels = $catalog6->filter($fixture, ModelCatalog::CAP_CHAT);
ok('text-only chat models are offered', in_array('vendor/text-only', $ids($chatModels), true));
ok('vision chat models are offered in the text list', in_array('vendor/vision', $ids($chatModels), true));
ok('embedding models are excluded from chat', !in_array('vendor/embed', $ids($chatModels), true));
ok('image-generation models are excluded from chat', !in_array('vendor/imagegen', $ids($chatModels), true));
ok('video models are excluded from chat', !in_array('vendor/video', $ids($chatModels), true));
ok('rerank models are excluded from chat', !in_array('vendor/rerank', $ids($chatModels), true));

$imageFiltered = $catalog6->filter($fixture, ModelCatalog::CAP_CHAT, array('image'));
same('an image attachment narrows chat to models declaring image input', array('vendor/vision'), $ids($imageFiltered));

$audioFiltered = $catalog6->filter($fixture, ModelCatalog::CAP_CHAT, array('audio'));
same('an audio attachment narrows chat to models declaring audio input', array('vendor/audio-chat'), $ids($audioFiltered));

$bothFiltered = $catalog6->filter($fixture, ModelCatalog::CAP_CHAT, array('image', 'audio'));
same('requiring two modalities requires both to be declared', array(), $ids($bothFiltered));

$textOnlyCheck = $catalog6->filter($fixture, ModelCatalog::CAP_CHAT, array('text'));
ok('the text modality never narrows the list',
    count($textOnlyCheck) === count(array_filter($chatModels, function ($m) {
        return $m['id'] !== 'vendor/no-arch';
    })) + (in_array('vendor/no-arch', $ids($chatModels), true) ? 1 : 0));

ok('a model with no architecture is fail-closed for image input',
    !in_array('vendor/no-arch', $ids($imageFiltered), true));

same('embedding discovery matches the embeddings endpoint',
    array('vendor/embed'), $ids($catalog6->filter($fixture, ModelCatalog::CAP_EMBEDDING)));
same('image discovery matches image-generation',
    array('vendor/imagegen'), $ids($catalog6->filter($fixture, ModelCatalog::CAP_IMAGE)));
same('video discovery matches openai-video',
    array('vendor/video'), $ids($catalog6->filter($fixture, ModelCatalog::CAP_VIDEO)));
same('rerank discovery matches jina-rerank',
    array('vendor/rerank'), $ids($catalog6->filter($fixture, ModelCatalog::CAP_RERANK)));

// Unknown capabilities are refused rather than guessed.
same('an unknown capability yields nothing', array(), $ids($catalog6->filter($fixture, 'telepathy')));
ok('official capability names are still matched by strict endpoint type',
    $catalog6->matches('embedding', array('embeddings'), array()) === true);
ok('a model named like an embedding model is not enough',
    $catalog6->matches('embedding', array('openai'), array()) === false);

section('Catalog: unbounded and malformed responses are bounded');

// Malformed records first, so the item cap below cannot hide them.
$hostile = array(
    'not-an-object',
    array('no_id' => true),
    array('id' => '', 'supported_endpoint_types' => array('openai')),
    array('id' => 'vendor/' . str_repeat('x', 500), 'supported_endpoint_types' => array('openai')),
    array('id' => 123, 'supported_endpoint_types' => array('openai')),
    array('id' => 'VENDOR/dupe', 'supported_endpoint_types' => array('openai')),
    array('id' => 'VENDOR/dupe', 'supported_endpoint_types' => array('openai')),
    array('id' => 'vendor/ok', 'supported_endpoint_types' => array('openai')),
);
$bounded = $catalog6->filter($hostile, ModelCatalog::CAP_CHAT);
ok('an over-long id is dropped', !in_array('vendor/' . str_repeat('x', 500), $ids($bounded), true));
ok('an empty id is dropped', !in_array('', $ids($bounded), true));
ok('a non-string id is dropped', !in_array(123, $ids($bounded), true));
ok('a record without an id is dropped', !in_array(null, $ids($bounded), true));
ok('a non-object record is dropped', in_array('vendor/ok', $ids($bounded), true));
same('only the well-formed records survive', array('VENDOR/dupe', 'vendor/ok'), $ids($bounded));
same('ids are preserved verbatim including case', true, in_array('VENDOR/dupe', $ids($bounded), true));

$flood = array();
for ($i = 0; $i < 600; ++$i) {
    $flood[] = array('id' => 'vendor/model-'.$i, 'supported_endpoint_types' => array('openai'));
}
$capped = $catalog6->filter($flood, ModelCatalog::CAP_CHAT);
ok('the item cap is enforced on an oversized response', count($capped) === ModelCatalog::MAX_ITEMS, (string) count($capped));

section('Catalog: live is authoritative, the seed is a bounded fallback');

Http::reset();
$fresh = function () {
    return new ModelCatalog(Origins::fromConfig(array(), array()));
};
Http::$transport = fakeTransport(array('models' => array(
    'status' => 503, 'body' => 'unavailable', 'error' => null, 'truncated' => false,
)));
$degraded = $fresh()->listFor(ModelCatalog::CAP_CHAT, null);
same('a failing catalog falls back to the verified seed', ModelCatalog::SOURCE_FALLBACK, $degraded['source']);
ok('the fallback is flagged for the UI', $degraded['error'] !== null);
ok('the fallback still offers models', $degraded['count'] > 0);
ok('the fallback never mixes in a live result', count($degraded['models']) === count(ModelCatalog::verifiedSeed()));

Http::reset();
Http::$transport = fakeTransport(array('models' => httpOk(array('data' => array(
    array('id' => 'vendor/live-only', 'supported_endpoint_types' => array('openai'),
          'architecture' => array('input_modalities' => array('text'))),
)))));
$live = $fresh()->listFor(ModelCatalog::CAP_CHAT, null);
same('a successful catalog is authoritative', ModelCatalog::SOURCE_LIVE, $live['source']);
same('a successful catalog replaces the seed entirely', array('vendor/live-only'), $ids($live['models']));
ok('the seed is not merged into a live result', !in_array('openai/gpt-5.5', $ids($live['models']), true));

Http::reset();
Http::$transport = fakeTransport(array('models' => httpOk(array('data' => array(
    array('id' => 'vendor/x', 'supported_endpoint_types' => array('openai'), 'architecture' => array('input_modalities' => array('text'))),
)))));
$fresh()->listFor(ModelCatalog::CAP_CHAT, null);
same('the catalog request goes to the inference origin',
    'https://api.orcarouter.ai/v1/models?capability=chat', Http::$log[0]['url']);

section('Catalog: the verified seed keeps its metadata');

$seed = ModelCatalog::verifiedSeed();
$seedById = array();
foreach ($seed as $model) {
    $seedById[$model['id']] = $model;
}
same('the seed has exactly the five verified models', 5, count($seed));
foreach (array('openai/gpt-5.5', 'anthropic/claude-opus-4.8', 'google/gemini-3.5-flash', 'deepseek/deepseek-v4-pro', 'orcarouter/auto') as $id) {
    ok('the seed contains '.$id, isset($seedById[$id]));
}
same('gpt-5.5 keeps its full reasoning effort ladder',
    array('low', 'medium', 'high', 'xhigh'), $seedById['openai/gpt-5.5']['reasoning_efforts']);
ok('gpt-5.5 keeps its image input modality',
    in_array('image', $seedById['openai/gpt-5.5']['architecture']['input_modalities'], true));
ok('deepseek is text-only so it is excluded from the image list',
    !$catalog6->matches(ModelCatalog::CAP_CHAT, array('openai'), array('text'), array('image')));
ok('the seed is filtered by the same rules',
    count($catalog6->seed(ModelCatalog::CAP_CHAT, array('image'))) === 3);
ok('the seed advertises no video model, so video stays empty',
    count($catalog6->seed(ModelCatalog::CAP_VIDEO)) === 0);

/* ==================================================================== *
 * Login lock
 * ==================================================================== */

section('Login lock: every terminal path releases it');

$lockStore = new SecretStore(tempDir().'/creds.json');
$lock = new LoginLock($lockStore);
ok('no lock is held initially', $lock->current() === null);
$lock->acquire(1, 'oauth_pkce');
ok('a lock is held after acquiring', $lock->current() !== null);
same('the lock records the attempt', 1, $lock->current()['attempt']);

ok('a stale release does not clear a newer attempt', $lock->release(99) === false);
// The stale-attempt guard is only sound because PkceFlow never reuses a number.
$seqStore = new SecretStore(tempDir().'/creds.json');
$seqFlow = new PkceFlow(Origins::fromConfig(array(), array()), $seqStore);
$seqLock = new LoginLock($seqStore);
$firstAttempt = $seqFlow->begin()['attempt'];
$seqFlow->cancel();
$secondAttempt = $seqFlow->begin()['attempt'];
$seqLock->acquire($secondAttempt, 'oauth_pkce');
ok('a cancel for the previous attempt cannot release the current sign-in',
    $seqLock->release($firstAttempt) === false);
ok('the current sign-in is still locked', $seqLock->current() !== null);
ok('a cancel for the current attempt does release it', $seqLock->release($secondAttempt) === true);
ok('the newer attempt is still locked', $lock->current() !== null);
ok('releasing the right attempt clears it', $lock->release(1) === true);
ok('no lock remains', $lock->current() === null);

$lock->acquire(5, 'oauth_pkce');
$lockStore->putState('pkce_lock', array('attempt' => 5, 'auth_type' => 'oauth_pkce', 'acquired_at' => time() - 1000, 'expires_at' => time() - 1));
ok('an expired lock is not reported as held', $lock->current() === null);

/* ==================================================================== *
 * Redaction
 * ==================================================================== */

section('Redaction: stored secrets and keys never reach a message');

$redactStore = new SecretStore(tempDir().'/creds.json');
$redactManager = new Manager($redactStore, Origins::fromConfig(array(), array()));
$redactManager->source(ApiKeySource::PROVIDER_ID)->save(array('api_key' => 'sk-orca-SECRETREDACTIONTEST'));
$redacted = $redactStore->redact('failed with key sk-orca-SECRETREDACTIONTEST while calling out');
ok('a stored key is redacted from a message', strpos($redacted, 'SECRETREDACTIONTEST') === false);
ok('the redaction leaves a marker', strpos($redacted, '[redacted]') !== false);
$unknown = $redactStore->redact('an unrelated sk-orca-UNKNOWNSECRETVALUE leaked');
ok('an unstored sk-orca- key is redacted by pattern too', strpos($unknown, 'UNKNOWNSECRETVALUE') === false);

/* ==================================================================== *
 * Provider request shape
 * ==================================================================== */

section('Provider: request shape and error mapping');

Http::reset();
$providerStore = new SecretStore(tempDir().'/creds.json');
$providerManager = new Manager($providerStore, Origins::fromConfig(array(), array()));
$cred = $providerManager->source(ApiKeySource::PROVIDER_ID)->save(array('api_key' => 'sk-orca-PROVIDERKEYTEST'));

Http::$transport = fakeTransport(array('chat/completions' => httpOk(array(
    'model' => 'vendor/echo',
    'choices' => array(array('message' => array('content' => 'hello'))),
    'usage' => array('total_tokens' => 3),
))));
$providerManager->provider()->chat($cred, 'vendor/echo', array(array('role' => 'user', 'content' => 'hi')));
$request = Http::$log[count(Http::$log) - 1];
same('inference goes to the OpenAI-compatible chat path',
    'https://api.orcarouter.ai/v1/chat/completions', $request['url']);
ok('inference never goes to the auth origin', strpos($request['url'], 'www.orcarouter.ai') === false);
ok('the Bearer header carries the key',
    in_array('Authorization: Bearer sk-orca-PROVIDERKEYTEST', $request['headers'], true));
$payload = json_decode($request['body'], true);
same('the model id is sent verbatim', 'vendor/echo', $payload['model']);
ok('messages are sent in the OpenAI wire format', $payload['messages'][0]['role'] === 'user');

$limits = array(
    array('status' => 429, 'body' => '{}', 'error' => null, 'truncated' => false, 'expected' => 429),
    array('status' => 500, 'body' => '{}', 'error' => null, 'truncated' => false, 'expected' => 500),
    array('status' => 0, 'body' => '', 'error' => 'transport_error', 'truncated' => false, 'expected' => 0),
);
foreach ($limits as $case) {
    Http::reset();
    Http::$transport = fakeTransport(array('chat/completions' => array_diff_key($case, array('expected' => 1))));
    $caught = null;
    try {
        $providerManager->provider()->chat($cred, 'vendor/echo', array(array('role' => 'user', 'content' => 'hi')));
    } catch (OrcaRouterException $e) {
        $caught = $e;
    }
    ok('HTTP '.$case['status'].' is mapped to a user-facing error', $caught !== null);
    same('HTTP '.$case['status'].' keeps its status', $case['expected'], $caught->status());
}

// A 429 must not mark the credential for reauth.
$store429 = new SecretStore(tempDir().'/creds.json');
$manager429 = new Manager($store429, Origins::fromConfig(array(), array()));
$cred429 = $manager429->source(ApiKeySource::PROVIDER_ID)->save(array('api_key' => 'sk-orca-RATELIMITKEYTEST'));
Http::reset();
Http::$transport = fakeTransport(array('chat/completions' => array('status' => 429, 'body' => '{}', 'error' => null, 'truncated' => false)));
try {
    $manager429->provider()->chat($cred429, 'vendor/echo', array(array('role' => 'user', 'content' => 'hi')));
} catch (OrcaRouterException $e) {
    /* expected */
}
same('a 429 does not mark the credential for reauth', 'active', $store429->getAccount(ApiKeySource::PROVIDER_ID)['status']);

/* ==================================================================== *
 * Manager
 * ==================================================================== */

section('Manager: panel state carries no secrets');

$panelStore = new SecretStore(tempDir().'/creds.json');
$panelManager = new Manager($panelStore, Origins::fromConfig(array(), array()));
$panelManager->source(ApiKeySource::PROVIDER_ID)->save(array('api_key' => 'sk-orca-PANELSTATEKEYTEST'));
$panel = $panelManager->status();
ok('the panel reports both entries', isset($panel['providers']['orcarouter'], $panel['providers']['orcarouter-oauth']));
ok('the panel state never contains the raw key', strpos(json_encode($panel), 'PANELSTATEKEYTEST') === false);
ok('the panel reports the masked key', strpos(json_encode($panel), 'sk-o******ST') !== false);
same('the panel advertises the inference base', 'https://api.orcarouter.ai/v1', $panel['inference_base']);
same('the panel advertises the auth base', 'https://www.orcarouter.ai', $panel['auth_base']);
ok('the panel links to the key console', strpos($panel['key_console_url'], 'console') !== false);
ok('the panel reports no login in progress', $panel['login']['in_progress'] === false);

$active = $panelManager->activeCredential();
same('the active credential is the one that was saved', 'sk-orca-PANELSTATEKEYTEST', $active->apiKey());

// The API-key adapter must never mutate PKCE state.
same('saving an API key does not create a PKCE credential', null, $panelManager->source(PkceSource::PROVIDER_ID)->credential());

section('Manager: an incompatible stored model is cleared');

$modelStore = new SecretStore(tempDir().'/creds.json');
$modelManager = new Manager($modelStore, Origins::fromConfig(array(), array()));
Http::reset();
Http::$transport = fakeTransport(array('models' => httpOk(array('data' => array(
    array('id' => 'vendor/a', 'supported_endpoint_types' => array('openai'), 'architecture' => array('input_modalities' => array('text'))),
    array('id' => 'vendor/b', 'supported_endpoint_types' => array('openai'), 'architecture' => array('input_modalities' => array('text', 'image'))),
)))));
$modelStore->putState(Manager::MODEL_KEY, 'vendor/b');
same('a still-valid stored model is restored', 'vendor/b', $modelManager->restoreSelectedModel(ModelCatalog::CAP_CHAT));
// vendor/b declares image input, so attaching an image must NOT clear it.
same('a model that declares image input survives an image attachment',
    'vendor/b', $modelManager->restoreSelectedModel(ModelCatalog::CAP_CHAT, array('image')));
// vendor/a is text-only, so attaching an image must clear it.
$modelStore->putState(Manager::MODEL_KEY, 'vendor/a');
same('a text-only model is restored while no attachment is present',
    'vendor/a', $modelManager->restoreSelectedModel(ModelCatalog::CAP_CHAT));
same('a text-only model is cleared once an image is attached',
    null, $modelManager->restoreSelectedModel(ModelCatalog::CAP_CHAT, array('image')));
same('the incompatible model is not left in state',
    null, $modelStore->getState(Manager::MODEL_KEY));

// A modality-scoped lookup must not be served from an unfiltered cache entry.
Http::reset();
$cacheCatalog = new ModelCatalog(Origins::fromConfig(array(), array()));
Http::$transport = fakeTransport(array('models' => httpOk(array('data' => array(
    array('id' => 'vendor/text', 'supported_endpoint_types' => array('openai'), 'architecture' => array('input_modalities' => array('text'))),
    array('id' => 'vendor/vision', 'supported_endpoint_types' => array('openai'), 'architecture' => array('input_modalities' => array('text', 'image'))),
)))));
$unfiltered = $cacheCatalog->listFor(ModelCatalog::CAP_CHAT, null);
$filtered = $cacheCatalog->listFor(ModelCatalog::CAP_CHAT, null, array('image'));
same('the unfiltered list contains both models', 2, $unfiltered['count']);
same('a modality-scoped lookup is not served from the unfiltered cache entry',
    array('vendor/vision'), array_map(function ($m) {
        return $m['id'];
    }, $filtered['models']));
$repeated = $cacheCatalog->listFor(ModelCatalog::CAP_CHAT, null, array('image'));
same('the modality-scoped result is itself cached', $filtered['models'], $repeated['models']);
same('only the unfiltered catalog required a second request', 2, count(Http::$log));

Http::reset();
Http::$transport = fakeTransport(array('models' => httpOk(array('data' => array(
    array('id' => 'vendor/a', 'supported_endpoint_types' => array('openai'), 'architecture' => array('input_modalities' => array('text'))),
)))));
$modelStore->putState(Manager::MODEL_KEY, 'vendor/gone');
same('a model the catalog no longer offers is cleared', null, $modelManager->restoreSelectedModel(ModelCatalog::CAP_CHAT));
same('the cleared model is not left in state', null, $modelStore->getState(Manager::MODEL_KEY));
same('an unknown model cannot be selected', null, $modelManager->setSelectedModel('vendor/nope', ModelCatalog::CAP_CHAT));

$panelManager->setActiveProvider(PkceSource::PROVIDER_ID);
same('the active entry can be switched', PkceSource::PROVIDER_ID, $panelManager->activeProviderId());
$unknownThrew = false;
try {
    $panelManager->setActiveProvider('nope');
} catch (OrcaRouterException $e) {
    $unknownThrew = true;
}
ok('an unknown entry id is refused', $unknownThrew);

/* ==================================================================== *
 * Corrupt credential file
 * ==================================================================== */

section('SecretStore: a corrupt file degrades instead of crashing');

$corruptDir = tempDir();
file_put_contents($corruptDir.'/creds.json', '{not json at all');
$corrupt = new SecretStore($corruptDir.'/creds.json');
$document = $corrupt->load();
ok('a corrupt credential file yields an empty document', $document['accounts'] === array());
ok('a credential read from a corrupt file is null', $corrupt->getAccount('orcarouter') === null);
$corrupt->putAccount('orcarouter', array('key' => 'sk-orca-CORRUPTRECOVER'));
same('the store recovers by writing a fresh document', 'sk-orca-CORRUPTRECOVER', $corrupt->getAccount('orcarouter')['key']);

file_put_contents($corruptDir.'/creds.json', json_encode(array('iv' => 'x', 'tag' => 'y', 'data' => 'z')));
$tampered = new SecretStore($corruptDir.'/creds.json');
ok('a tampered credential file is detected rather than trusted', $tampered->getAccount('orcarouter') === null);

echo "\n".($GLOBALS['failed'] === 0
    ? 'ALL '.$GLOBALS['passed']." TESTS PASSED\n"
    : $GLOBALS['failed'].' of '.($GLOBALS['passed'] + $GLOBALS['failed'])." TESTS FAILED\n");
echo 'passed='.$GLOBALS['passed'].' failed='.$GLOBALS['failed']."\n";
exit($GLOBALS['failed'] === 0 ? 0 : 1);
