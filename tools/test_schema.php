<?php

/*
 * Live catalog schema check.
 *
 * This is the falsifiability test for the capability filters: it pulls the real
 * OrcaRouter catalog and asserts that every filter decision the application
 * makes is correct against real data. It fails loudly if the live catalog shape
 * stops matching the assumptions in app/components/OrcaRouter/ModelCatalog.php.
 *
 * Usage: ORCAROUTER_API_KEY=... php tools/test_schema.php
 */

require __DIR__.'/../vendor/autoload.php';

use app\components\OrcaRouter\Credential;
use app\components\OrcaRouter\Http;
use app\components\OrcaRouter\ModelCatalog;
use app\components\OrcaRouter\Origins;

$key = getenv('ORCAROUTER_API_KEY');
if (!$key) {
    fwrite(STDERR, "ORCAROUTER_API_KEY is not set\n");
    exit(2);
}

$failures = 0;
$checks = 0;

function check($label, $condition, $detail = '')
{
    global $failures, $checks;
    ++$checks;
    if ($condition) {
        echo "  ok   $label\n";
    } else {
        ++$failures;
        echo "  FAIL $label".($detail !== '' ? " -- $detail" : '')."\n";
    }
}

$origins = Origins::fromConfig(array());
$catalog = new ModelCatalog($origins);
$credential = new Credential(array('key' => $key, 'account_id' => 'schema-check', 'generation' => 1));

echo "auth origin:  ".$origins->authBase()."\n";
echo "inference:    ".$origins->apiBase()."\n";
echo "models url:   ".$origins->modelsUrl(ModelCatalog::CAP_CHAT)."\n\n";

$raw = Http::getJson($origins->modelsUrl(), array($credential->bearerHeader()), 20, 8388608);
if ($raw['error'] !== null || $raw['status'] !== 200) {
    fwrite(STDERR, "catalog fetch failed: status={$raw['status']} error={$raw['error']}\n");
    exit(2);
}
$decoded = Http::decode($raw['body']);
$items = $decoded['data'];
echo 'live catalog records: '.count($items)."\n\n";

// Go through the real pipeline: the server-side capability gate followed by
// the local rules. Filtering the unfiltered catalog locally is NOT equivalent
// and is exactly the mistake this test exists to catch.
function pipeline(ModelCatalog $catalog, $capability, array $modalities = array())
{
    $result = $catalog->listFor($capability, $GLOBALS['credential'], $modalities);
    if ($result['source'] !== ModelCatalog::SOURCE_LIVE) {
        fwrite(STDERR, "live catalog unavailable for $capability\n");
        exit(2);
    }

    return $result['models'];
}

$chat = pipeline($catalog, ModelCatalog::CAP_CHAT);
$embedding = pipeline($catalog, ModelCatalog::CAP_EMBEDDING);
$image = pipeline($catalog, ModelCatalog::CAP_IMAGE);
$video = pipeline($catalog, ModelCatalog::CAP_VIDEO);
$rerank = pipeline($catalog, ModelCatalog::CAP_RERANK);
$multimodal = pipeline($catalog, ModelCatalog::CAP_CHAT, array('image'));

echo "-- counts --\n";
printf("chat=%d embedding=%d image=%d video=%d rerank=%d multimodal(image)=%d\n\n",
    count($chat), count($embedding), count($image), count($video), count($rerank), count($multimodal));

echo "-- chat filter --\n";
$textEndpoints = array('openai', 'anthropic', 'gemini', 'openai-response');
$badText = array();
$noText = array();
foreach ($chat as $model) {
    if (array_intersect($model['supported_endpoint_types'], array('image-generation', 'openai-video', 'jina-rerank', 'embeddings'))) {
        $badText[] = $model['id'];
    }
    // A record that declares routes must declare a text-capable one. A record
    // that declares nothing is a documented exception: the server's chat gate
    // already asserted the capability, and the absence is surfaced to the UI.
    if ($model['endpoint_types_declared'] && !array_intersect($model['supported_endpoint_types'], $textEndpoints)) {
        $noText[] = $model['id'];
    }
}
check('every chat model that declares routes declares a text endpoint type', !$noText, implode(',', $noText));
check('no chat model carries a non-text-only endpoint type', !$badText, implode(',', $badText));

// Speech-synthesis records are indistinguishable from chat records by catalog
// metadata alone: they advertise `openai` and a ["text"] input modality. Only
// the server-side ?capability=chat gate removes them, so a local-only filter
// would leak them into the chat selector.
$serverChat = Http::getJson($origins->modelsUrl(ModelCatalog::CAP_CHAT), array($credential->bearerHeader()), 20, 8388608);
$serverChatIds = array();
foreach (Http::decode($serverChat['body'])['data'] as $item) {
    $serverChatIds[$item['id']] = true;
}
$localOnly = $catalog->filter($items, ModelCatalog::CAP_CHAT);
$localOnlyIds = array();
foreach ($localOnly as $model) {
    $localOnlyIds[$model['id']] = true;
}
$leakedTts = array();
foreach ($localOnly as $model) {
    if (!isset($serverChatIds[$model['id']])) {
        $leakedTts[] = $model['id'];
    }
}
check('a local-only filter would leak speech-synthesis models (proves the server gate is required)',
    count($leakedTts) > 0, 'none leaked');
$chatIdMap = array_flip(array_map(function ($m) {
    return $m['id'];
}, $chat));
$outsideGate = array_diff_key($chatIdMap, $serverChatIds);
check('the shipped pipeline carries no model the server-side chat gate excludes',
    count($outsideGate) === 0, implode(',', array_slice(array_keys($outsideGate), 0, 8)));
check('the shipped pipeline carries every model the server-side chat gate offers',
    count(array_diff_key($serverChatIds, $chatIdMap)) === 0,
    (string) count(array_diff_key($serverChatIds, $chatIdMap)).' missing');

// Records with no endpoint metadata at all are accepted only because the
// server's chat gate asserted the capability; that absence is reported so the
// UI can be honest about it.
$undeclaredIds = array();
foreach ($chat as $model) {
    if ($model['endpoint_types_declared'] === false) {
        $undeclaredIds[] = $model['id'];
    }
}
check('records with no endpoint metadata are flagged as undeclared',
    count($undeclaredIds) > 0 && count($undeclaredIds) <= 20, (string) count($undeclaredIds));
printf("  note: %d chat records declare no endpoint types (accepted on the server gate only)\n", count($undeclaredIds));
foreach ($undeclaredIds as $id) {
    check('  undeclared-route record: '.$id, isset($serverChatIds[$id]));
}
printf("  note: server-side chat gate keeps %d; the local filter alone would keep %d\n",
    count($serverChatIds), count($localOnly));

echo "\n-- non-text caps --\n";
$embeddingOk = true;
foreach ($embedding as $model) {
    if (!in_array('embeddings', $model['supported_endpoint_types'], true)) {
        $embeddingOk = false;
    }
}
check('every embedding model declares the embeddings endpoint', $embeddingOk);
$imageOk = true;
$imageUndeclared = array();
foreach ($image as $model) {
    if (!in_array('image-generation', $model['supported_endpoint_types'], true)) {
        if ($model['endpoint_types_declared']) {
            $imageOk = false;
        } else {
            $imageUndeclared[] = $model['id'];
        }
    }
}
check('every image model that declares routes declares image-generation', $imageOk);
printf("  note: %d image records declare no endpoint types (server gate only): %s\n",
    count($imageUndeclared), implode(',', $imageUndeclared));
$videoOk = true;
foreach ($video as $model) {
    if (!in_array('openai-video', $model['supported_endpoint_types'], true)) {
        $videoOk = false;
    }
}
check('every video model declares openai-video', $videoOk);

echo "\n-- multimodal fail-closed --\n";
$chatIds = array();
foreach ($chat as $model) {
    $chatIds[$model['id']] = true;
}
$multimodalOk = true;
$undeclared = array();
foreach ($multimodal as $model) {
    if (!isset($chatIds[$model['id']])) {
        $multimodalOk = false;
    }
    if (!in_array('image', $model['input_modalities'], true)) {
        $multimodalOk = false;
        $undeclared[] = $model['id'];
    }
}
check('every multimodal model is also a chat model', $multimodalOk && !$undeclared, implode(',', $undeclared));
check('the multimodal list is strictly smaller than the chat list', count($multimodal) < count($chat),
    count($multimodal).' vs '.count($chat));

// A model that does not declare image input must not appear in the image list.
$textOnlyIds = array();
foreach ($chat as $model) {
    if ($model['input_modalities'] === array('text')) {
        $textOnlyIds[] = $model['id'];
    }
}
$multimodalIds = array();
foreach ($multimodal as $model) {
    $multimodalIds[$model['id']] = true;
}
$leaked = array();
foreach ($textOnlyIds as $id) {
    if (isset($multimodalIds[$id])) {
        $leaked[] = $id;
    }
}
check('no text-only chat model leaks into the image-filtered list', !$leaked, implode(',', $leaked));
printf("  note: %d text-only chat models, %d image-capable chat models\n", count($textOnlyIds), count($multimodal));

// A model with no architecture block at all is fail-closed for a modality.
$noArch = 0;
$noArchLeak = array();
foreach ($chat as $model) {
    if (!$model['input_modalities']) {
        ++$noArch;
        if (isset($multimodalIds[$model['id']])) {
            $noArchLeak[] = $model['id'];
        }
    }
}
check('models with no declared input modalities are excluded from the image list',
    !$noArchLeak, implode(',', $noArchLeak));
printf("  note: %d chat models declare no input modalities\n", $noArch);

echo "\n-- namespace and names --\n";
// Ids are passed through verbatim, including the vendor/ prefix when present.
// The live catalog also carries bare ids (gpt-5.6-luna, claude-opus-4-6), so a
// rewrite that prepended a namespace would corrupt real model names.
$rawIds = array();
foreach ($items as $item) {
    $rawIds[$item['id']] = true;
}
$rewritten = array();
foreach ($chat as $model) {
    if (!isset($rawIds[$model['id']])) {
        $rewritten[] = $model['id'];
    }
}
check('every catalog id is preserved verbatim, with no namespace rewriting',
    !$rewritten, implode(',', array_slice($rewritten, 0, 8)));
check('bare ids survive unchanged', in_array('gpt-5.6-luna', array_map(function ($m) {
    return $m['id'];
}, $chat), true) === isset($rawIds['gpt-5.6-luna']));

echo "\n-- verified seed --\n";
$liveById = array();
foreach ($items as $item) {
    $liveById[$item['id']] = true;
}
$missing = array();
foreach (ModelCatalog::verifiedSeed() as $seed) {
    if (!isset($liveById[$seed['id']])) {
        $missing[] = $seed['id'];
    }
}
check('every verified fallback model is present in the live catalog', !$missing, implode(',', $missing));

echo "\n-- fallback metadata preserved --\n";
$gpt = null;
foreach (ModelCatalog::verifiedSeed() as $seed) {
    if ($seed['id'] === 'openai/gpt-5.5') {
        $gpt = $seed;
    }
}
check('gpt-5.5 keeps its reasoning effort ladder',
    $gpt !== null && $gpt['reasoning_efforts'] === array('low', 'medium', 'high', 'xhigh'));
check('gpt-5.5 declares image input', $gpt !== null && in_array('image', $gpt['architecture']['input_modalities'], true));

// The live record for gpt-5.5 must still advertise what the seed claims.
$liveGpt = null;
foreach ($items as $item) {
    if ($item['id'] === 'openai/gpt-5.5') {
        $liveGpt = $item;
    }
}
check('gpt-5.5 live record still supports a text endpoint',
    $liveGpt !== null && (bool) array_intersect($liveGpt['supported_endpoint_types'], $textEndpoints));
check('gpt-5.5 live record still declares image input',
    $liveGpt !== null && in_array('image', $liveGpt['architecture']['input_modalities'], true));

echo "\n-- seed is filtered by the same rules --\n";
check('the seed is non-empty for chat', count($catalog->seed(ModelCatalog::CAP_CHAT)) > 0);
check('the seed is empty for video (no verified video model)', count($catalog->seed(ModelCatalog::CAP_VIDEO)) === 0);
check('the seed is empty for rerank', count($catalog->seed(ModelCatalog::CAP_RERANK)) === 0);
// gpt-5.5, claude-opus-4.8 and gemini-3.5-flash declare image input;
// deepseek-v4-pro is text-only and orcarouter/auto declares nothing.
check('the seed yields exactly 3 image-capable chat models',
    count($catalog->seed(ModelCatalog::CAP_CHAT, array('image'))) === 3,
    (string) count($catalog->seed(ModelCatalog::CAP_CHAT, array('image'))));
check('the text-only seed model is excluded from the image list',
    !in_array('deepseek/deepseek-v4-pro', array_map(function ($m) {
        return $m['id'];
    }, $catalog->seed(ModelCatalog::CAP_CHAT, array('image'))), true));

echo "\n-- capability totals match the live catalog --\n";
check('embedding totals match the server gate', count($embedding) === count(array_filter($items, function ($i) {
    return in_array('embeddings', isset($i['supported_endpoint_types']) ? $i['supported_endpoint_types'] : array(), true);
})));
check('video is discovered unfiltered (no server capability) and narrowed locally',
    count($video) === count(array_filter($items, function ($i) {
        return in_array('openai-video', isset($i['supported_endpoint_types']) ? $i['supported_endpoint_types'] : array(), true);
    })));
// The server's own ?capability=image gate is the authority for this capability.
$serverImage = Http::getJson($origins->modelsUrl(ModelCatalog::CAP_IMAGE), array($credential->bearerHeader()), 20, 8388608);
$serverImageIds = array();
foreach (Http::decode($serverImage['body'])['data'] as $item) {
    $serverImageIds[$item['id']] = true;
}
$imageIdMap = array_flip(array_map(function ($m) {
    return $m['id'];
}, $image));
check('the image list is exactly the server-side ?capability=image set',
    count(array_diff_key($imageIdMap, $serverImageIds)) === 0 && count(array_diff_key($serverImageIds, $imageIdMap)) === 0,
    (string) count($imageIdMap).' local vs '.count($serverImageIds).' server');
check('no image record carries a text-chat-only route clash',
    count(array_filter($image, function ($m) {
        return in_array('openai-video', $m['supported_endpoint_types'], true)
            || in_array('jina-rerank', $m['supported_endpoint_types'], true)
            || in_array('embeddings', $m['supported_endpoint_types'], true);
    })) === 0);
printf("  note: image declared=%d undeclared=%d\n",
    count(array_filter($image, function ($m) {
        return $m['endpoint_types_declared'];
    })),
    count(array_filter($image, function ($m) {
        return !$m['endpoint_types_declared'];
    })));
printf("  note: video=%d rerank=%d image=%d embedding=%d\n", count($video), count($rerank), count($image), count($embedding));

echo "\n-- divergence between live and seed (live must not be the seed) --\n";
check('live chat catalog is much larger than the seed', count($chat) > count(ModelCatalog::verifiedSeed()) * 4,
    count($chat).' live vs '.count(ModelCatalog::verifiedSeed()).' seed');

echo "\n".($failures === 0 ? "ALL $checks CHECKS PASSED\n" : "$failures of $checks CHECKS FAILED\n");
echo 'catalog_model_count='.count($chat)."\n";
echo 'image_model_count='.count($multimodal)."\n";
exit($failures === 0 ? 0 : 1);
