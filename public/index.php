<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = appBasePath();
$path = normalizeRequestPath($rawPath, $basePath);

if (str_starts_with($path, '/generated/')) {
    serveGenerated($path);
    exit;
}

if (str_starts_with($path, '/lora_refs/')) {
    serveLoraReference($path);
    exit;
}

if (str_starts_with($path, '/character_refs/')) {
    serveCharacterReference($path);
    exit;
}

if (str_starts_with($path, '/api/')) {
    handleApi($path);
    exit;
}

if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$appBasePath = $basePath;
include __DIR__ . '/views/app.php';

function appBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($base === '' || $base === '.' || $base === '/public') {
        return '';
    }
    if (str_ends_with($base, '/public')) {
        $base = substr($base, 0, -7);
    }
    return $base === '/' ? '' : $base;
}

function normalizeRequestPath(string $path, string $basePath): string
{
    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath));
    } elseif ($basePath !== '' && $path === $basePath) {
        $path = '/';
    }
    return $path === '' ? '/' : $path;
}

function handleApi(string $path): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $catalog = new UmaCatalog();
    $store = new Store();
    $composer = new PromptComposer($catalog, $store);
    $sd = new SdClient();

    try {
        if ($path === '/api/catalog' && $method === 'GET') {
            $data = $catalog->get(false);
            $store->syncUmaCatalog($data);
            jsonResponse($data);
        }
        if ($path === '/api/health' && $method === 'GET') {
            jsonResponse(healthCheck($store, $sd));
        }
        if ($path === '/api/catalog/refresh' && $method === 'POST') {
            $data = $catalog->get(true);
            $store->syncUmaCatalog($data);
            jsonResponse($data);
        }
        if ($path === '/api/series' && $method === 'GET') {
            jsonResponse(['items' => $store->getSeries()]);
        }
        if ($path === '/api/series' && $method === 'POST') {
            jsonResponse(['item' => $store->saveSeries(readJson()), 'items' => $store->getSeries()]);
        }
        if ($path === '/api/characters' && $method === 'GET') {
            jsonResponse(['items' => $store->getCharacters($_GET)]);
        }
        if ($path === '/api/characters' && $method === 'POST') {
            jsonResponse(['item' => $store->saveCharacter(readJson()), 'items' => $store->getCharacters()]);
        }
        if (preg_match('#^/api/characters/(\d+)$#', $path, $matches) && $method === 'GET') {
            $item = $store->getCharacter((int) $matches[1]);
            jsonResponse($item ? ['item' => $item] : ['error' => 'Not found'], $item ? 200 : 404);
        }
        if (preg_match('#^/api/characters/(\d+)/preview$#', $path, $matches) && $method === 'POST') {
            $file = $_FILES['image'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $item = $store->getCharacter((int) $matches[1]);
                jsonResponse($item ? ['item' => $item, 'items' => $store->getCharacters(), 'skipped' => true] : ['error' => 'Not found'], $item ? 200 : 404);
            }
            jsonResponse($store->saveCharacterPreviewUpload((int) $matches[1], $file));
        }
        if ($path === '/api/characters/seed-defaults' && $method === 'POST') {
            jsonResponse($store->seedDefaultCharacters());
        }
        if ($path === '/api/modes' && $method === 'GET') {
            jsonResponse($composer->modes());
        }
        if ($path === '/api/modes' && $method === 'POST') {
            jsonResponse($store->savePromptLibrary(readJson()));
        }
        if ($path === '/api/modes/nsfw-reset' && $method === 'POST') {
            jsonResponse($store->resetNsfwDirector());
        }
        if ($path === '/api/modes/pose-reset' && $method === 'POST') {
            jsonResponse($store->resetPoseLibrary());
        }
        if ($path === '/api/nsfw/audit' && $method === 'GET') {
            jsonResponse((new NsfwDirectorAuditor($store, $catalog, $composer))->audit());
        }
        if ($path === '/api/director/audit/full' && $method === 'GET') {
            jsonResponse(fullDirectorAudit($store, $catalog, $composer));
        }
        if ($path === '/api/sd/status' && $method === 'GET') {
            jsonResponse($sd->status());
        }
        if ($path === '/api/sd/resources' && $method === 'GET') {
            jsonResponse(resourcesWithSavedLoraMetadata($sd->resources(), $store->getLoraLibrary()));
        }
        if ($path === '/api/sd/upscalers' && $method === 'GET') {
            jsonResponse($sd->upscalers());
        }
        if ($path === '/api/settings' && $method === 'GET') {
            jsonResponse(['settings' => settingsForClient($store->getSettings())]);
        }
        if ($path === '/api/settings' && $method === 'POST') {
            jsonResponse(['settings' => settingsForClient($store->saveSettings(normalizeSettingsPayload(readJson())))]);
        }
        if ($path === '/api/lora-triggers' && $method === 'GET') {
            jsonResponse(['triggers' => $store->getLoraTriggers()]);
        }
        if ($path === '/api/lora-triggers' && $method === 'POST') {
            jsonResponse(['triggers' => $store->saveLoraTriggers(readJson())]);
        }
        if ($path === '/api/lora-library' && $method === 'GET') {
            jsonResponse(['items' => $store->getLoraLibrary(), 'categories' => loraCategories()]);
        }
        if ($path === '/api/lora-library' && $method === 'POST') {
            jsonResponse(['items' => $store->saveLoraLibrary(readJson()), 'categories' => loraCategories()]);
        }
        if (preg_match('#^/api/lora-library/([^/]+)/pack$#', $path, $matches) && $method === 'GET') {
            jsonResponse($store->getLoraPack(rawurldecode($matches[1])));
        }
        if (preg_match('#^/api/lora-library/([^/]+)/variants$#', $path, $matches) && $method === 'POST') {
            jsonResponse($store->saveLoraVariant(rawurldecode($matches[1]), readJson()));
        }
        if (preg_match('#^/api/lora-library/([^/]+)/variants/([^/]+)$#', $path, $matches) && $method === 'DELETE') {
            jsonResponse($store->deleteLoraVariant(rawurldecode($matches[1]), rawurldecode($matches[2])));
        }
        if (preg_match('#^/api/lora-library/([^/]+)/references$#', $path, $matches) && $method === 'POST') {
            $file = $_FILES['image'] ?? null;
            if (!is_array($file)) {
                throw new InvalidArgumentException('Reference image file is required.');
            }
            jsonResponse($store->saveLoraReferenceUpload(
                rawurldecode($matches[1]),
                $file,
                (string) ($_POST['variant_key'] ?? 'default'),
                (string) ($_POST['caption'] ?? '')
            ));
        }
        if (preg_match('#^/api/lora-library/references/(\d+)$#', $path, $matches) && $method === 'DELETE') {
            jsonResponse(['ok' => $store->deleteLoraReference((int) $matches[1])]);
        }
        if ($path === '/api/lora/audit' && $method === 'GET') {
            jsonResponse(auditLoraLibrary($sd->resources(), $store->getLoraLibrary()));
        }
        if ($path === '/api/presets' && $method === 'GET') {
            jsonResponse(['presets' => $store->getPresets($_GET['type'] ?? null)]);
        }
        if ($path === '/api/presets' && $method === 'POST') {
            jsonResponse(['preset' => $store->savePreset(readJson())], 201);
        }
        if (preg_match('#^/api/presets/(\d+)$#', $path, $matches) && $method === 'PUT') {
            $payload = readJson();
            $payload['id'] = (int) $matches[1];
            jsonResponse(['preset' => $store->savePreset($payload)]);
        }
        if (preg_match('#^/api/presets/(\d+)$#', $path, $matches) && $method === 'DELETE') {
            $store->deletePreset((int) $matches[1]);
            jsonResponse(['ok' => true]);
        }
        if ($path === '/api/prompt/compose' && $method === 'POST') {
            jsonResponse($composer->compose(readJson()));
        }
        if ($path === '/api/prompt/audit' && $method === 'POST') {
            jsonResponse((new PromptAuditor($composer, $store))->audit(readJson()));
        }
        if ($path === '/api/prompt/polish' && $method === 'POST') {
            jsonResponse($composer->polish(readJson()));
        }
        if ($path === '/api/prompt/llm-payload' && $method === 'POST') {
            jsonResponse(['payload' => $composer->llmPayload(readJson())]);
        }
        if ($path === '/api/history' && $method === 'GET') {
            jsonResponse(['history' => $store->recentHistory()]);
        }
        if ($path === '/api/gallery' && $method === 'GET') {
            jsonResponse(['items' => $store->getGallery($_GET)]);
        }
        if ($path === '/api/jobs' && $method === 'GET') {
            jsonResponse(['items' => $store->getJobs()]);
        }
        if ($path === '/api/jobs' && $method === 'POST') {
            jsonResponse(['item' => $store->createJob(readJson())], 201);
        }
        if (preg_match('#^/api/jobs/(\d+)$#', $path, $matches) && $method === 'GET') {
            $item = $store->getJob((int) $matches[1]);
            jsonResponse($item ? ['item' => $item] : ['error' => 'Not found'], $item ? 200 : 404);
        }
        if (preg_match('#^/api/jobs/(\d+)/cancel$#', $path, $matches) && $method === 'POST') {
            jsonResponse(['item' => $store->cancelJob((int) $matches[1])]);
        }
        if (preg_match('#^/api/gallery/(\d+)$#', $path, $matches) && $method === 'GET') {
            $item = $store->getGalleryItem((int) $matches[1]);
            jsonResponse($item ? ['item' => $item] : ['error' => 'Not found'], $item ? 200 : 404);
        }
        if (preg_match('#^/api/gallery/(\d+)/enhance/stream$#', $path, $matches) && $method === 'POST') {
            streamEnhance((int) $matches[1], $store);
            exit;
        }
        if ($path === '/api/generate/interrupt' && $method === 'POST') {
            jsonResponse(interruptGeneration($store, readJson()));
        }
        if ($path === '/api/generate/stream' && $method === 'POST') {
            streamGeneration($composer, $store);
            exit;
        }

        jsonResponse(['error' => 'Not found'], 404);
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function readJson(): array
{
    $raw = file_get_contents('php://input') ?: '{}';
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new InvalidArgumentException('Invalid JSON payload.');
    }
    return $json;
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function settingsForClient(array $settings): array
{
    $apiKey = trim((string) ($settings['llm']['api_key'] ?? ''));
    $settings['llm']['api_key'] = '';
    $settings['llm']['api_key_set'] = $apiKey !== '' || Config::llmApiKey() !== '';

    return $settings;
}

function normalizeSettingsPayload(array $payload): array
{
    if (!isset($payload['llm']) || !is_array($payload['llm'])) {
        return $payload;
    }

    $clearApiKey = !empty($payload['llm']['api_key_clear']);
    unset($payload['llm']['api_key_clear']);

    if ($clearApiKey) {
        $payload['llm']['api_key'] = '';
        return $payload;
    }

    if (array_key_exists('api_key', $payload['llm'])) {
        $apiKey = trim((string) $payload['llm']['api_key']);
        if ($apiKey === '') {
            unset($payload['llm']['api_key']);
        } else {
            $payload['llm']['api_key'] = $apiKey;
        }
    }

    return $payload;
}

function interruptGeneration(Store $store, array $input): array
{
    $jobId = max(0, (int) ($input['job_id'] ?? 0));
    try {
        HttpClient::request('POST', Config::sdBaseUrl() . '/sdapi/v1/interrupt', '{}', 10);
    } catch (Throwable $e) {
        if ($jobId > 0) {
            $store->cancelJob($jobId);
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $job = $jobId > 0 ? $store->cancelJob($jobId) : null;
    return ['ok' => true, 'job' => $job];
}

function resourcesWithSavedLoraMetadata(array $resources, array $library): array
{
    if (!isset($resources['loras']) || !is_array($resources['loras'])) {
        return $resources;
    }

    $resources['loras'] = array_map(static function ($lora) use ($library) {
        if (!is_array($lora)) {
            return $lora;
        }

        $alias = trim((string) ($lora['alias'] ?? $lora['name'] ?? ''));
        $name = trim((string) ($lora['name'] ?? ''));
        $meta = [];
        if ($alias !== '' && isset($library[$alias])) {
            $meta = $library[$alias];
        } elseif ($name !== '' && isset($library[$name])) {
            $meta = $library[$name];
        }

        $saved = (string) ($meta['trigger_words'] ?? '');
        $lora['saved_trigger_words'] = $saved;
        $lora['has_saved_trigger_words'] = trim($saved) !== '';
        $lora['category'] = (string) ($meta['category'] ?? 'uncategorized');
        $lora['default_weight'] = (float) ($meta['default_weight'] ?? 0.8);
        $lora['conflict_groups'] = (string) ($meta['conflict_groups'] ?? '');
        $lora['conflict_negatives'] = (string) ($meta['conflict_negatives'] ?? '');
        $lora['compatible_series'] = (string) ($meta['compatible_series'] ?? '');
        $lora['compatible_characters'] = (string) ($meta['compatible_characters'] ?? '');
        $lora['compatible_acts'] = (string) ($meta['compatible_acts'] ?? '');
        $lora['incompatible_acts'] = (string) ($meta['incompatible_acts'] ?? '');
        $lora['act_groups'] = (string) ($meta['act_groups'] ?? '');
        $lora['requires_outfit_none'] = (bool) ($meta['requires_outfit_none'] ?? false);
        $lora['scene_intent_hint'] = (string) ($meta['scene_intent_hint'] ?? '');
        $lora['nsfw_effect_groups'] = (string) ($meta['nsfw_effect_groups'] ?? '');
        $lora['needs_trigger'] = (bool) ($meta['needs_trigger'] ?? false);
        $lora['requires_secondary_characters'] = (bool) ($meta['requires_secondary_characters'] ?? false);
        $lora['min_secondary_characters'] = (int) ($meta['min_secondary_characters'] ?? 0);
        $lora['max_secondary_characters'] = (int) ($meta['max_secondary_characters'] ?? 0);
        $lora['anonymous_partner_tags'] = (string) ($meta['anonymous_partner_tags'] ?? '');
        $lora['ensemble_tags'] = (string) ($meta['ensemble_tags'] ?? '');
        $lora['favorite'] = (bool) ($meta['favorite'] ?? false);
        $lora['variant_count'] = (int) ($meta['variant_count'] ?? 0);
        $lora['reference_count'] = (int) ($meta['reference_count'] ?? 0);
        $lora['metadata_enabled'] = (bool) ($meta['enabled'] ?? true);
        $lora['notes'] = (string) ($meta['notes'] ?? '');
        $lora['metadata'] = $meta;
        return $lora;
    }, $resources['loras']);

    return $resources;
}

function loraCategories(): array
{
    return ['clothing', 'pose', 'style', 'character', 'expression', 'body', 'background', 'effect', 'uncategorized'];
}

function auditLoraLibrary(array $resources, array $library): array
{
    $issues = [];
    $detected = [];
    foreach (($resources['loras'] ?? []) as $lora) {
        if (!is_array($lora)) {
            continue;
        }
        $alias = trim((string) ($lora['alias'] ?? $lora['name'] ?? ''));
        if ($alias === '' || $alias === Config::umaBaseLoraAlias()) {
            continue;
        }
        $detected[$alias] = true;
        $meta = $library[$alias] ?? $library[trim((string) ($lora['name'] ?? ''))] ?? null;
        if (!$meta) {
            $issues[] = ['code' => 'lora.missing_metadata', 'alias' => $alias, 'message' => 'Detected LoRA has no metadata.'];
            continue;
        }
        if (trim((string) ($meta['trigger_words'] ?? '')) === '') {
            $issues[] = ['code' => 'lora.missing_triggers', 'alias' => $alias, 'message' => 'LoRA has no trigger words.'];
        }
        if (!empty($meta['needs_trigger'])) {
            $issues[] = ['code' => 'lora.needs_trigger', 'alias' => $alias, 'message' => 'LoRA is marked as needing confirmed trigger words.'];
        }
        if (trim((string) ($meta['category'] ?? '')) === '' || ($meta['category'] ?? 'uncategorized') === 'uncategorized') {
            $issues[] = ['code' => 'lora.missing_category', 'alias' => $alias, 'message' => 'LoRA category is uncategorized.'];
        }
        $weight = (float) ($meta['default_weight'] ?? 0);
        if ($weight <= 0 || $weight > 2) {
            $issues[] = ['code' => 'lora.invalid_weight', 'alias' => $alias, 'message' => 'Default weight must be between 0 and 2.'];
        }
        if (($meta['category'] ?? '') === 'clothing' && trim((string) ($meta['conflict_groups'] ?? '')) === '') {
            $issues[] = ['code' => 'lora.clothing_missing_conflict_group', 'alias' => $alias, 'message' => 'Clothing LoRA should use conflict group clothing_primary.'];
        }
        if (($meta['category'] ?? '') === 'character' && !str_contains(mb_strtolower((string) ($meta['conflict_groups'] ?? '')), 'character_identity')) {
            $issues[] = ['code' => 'lora.character_missing_identity_group', 'alias' => $alias, 'message' => 'Character LoRA should use conflict group character_identity.'];
        }
        if (trim((string) ($meta['incompatible_acts'] ?? '')) !== '' && trim((string) ($meta['act_groups'] ?? '')) === '') {
            $issues[] = ['code' => 'lora.act_metadata_partial', 'alias' => $alias, 'message' => 'LoRA has incompatible acts but no act_groups.'];
        }
        $metadataText = loraMetadataSearchText($meta, $alias);
        $looksSexual = looksSexualLoraMetadata($meta, $alias);
        if ($looksSexual && trim((string) ($meta['act_groups'] ?? '')) === '') {
            $issues[] = ['code' => 'lora.missing_act_groups', 'alias' => $alias, 'message' => 'NSFW-looking LoRA should declare act_groups.'];
        }
        if (($meta['category'] ?? '') === 'pose'
            && trim((string) ($meta['act_groups'] ?? '')) === ''
            && trim((string) ($meta['compatible_acts'] ?? '')) === ''
            && trim((string) ($meta['incompatible_acts'] ?? '')) === ''
        ) {
            $issues[] = ['code' => 'lora.pose_missing_director_metadata', 'alias' => $alias, 'message' => 'Pose LoRA should declare act_groups or compatible/incompatible Director acts.'];
        }
        if (($meta['category'] ?? '') === 'effect' && trim((string) ($meta['nsfw_effect_groups'] ?? '')) === '') {
            $issues[] = ['code' => 'lora.missing_effect_groups', 'alias' => $alias, 'message' => 'Effect LoRA should declare nsfw_effect_groups.'];
        }
        if (!empty($meta['requires_secondary_characters']) && (int) ($meta['min_secondary_characters'] ?? 0) <= 0) {
            $issues[] = ['code' => 'lora.ensemble_missing_minimum', 'alias' => $alias, 'message' => 'Multi-character LoRA should declare min_secondary_characters.'];
        }
        if ($looksSexual && trim((string) ($meta['scene_intent_hint'] ?? '')) === '' && str_contains($metadataText, 'partner')) {
            $issues[] = ['code' => 'lora.missing_scene_intent_hint', 'alias' => $alias, 'message' => 'Partner-oriented LoRA should declare scene_intent_hint.'];
        }
    }

    foreach ($library as $alias => $meta) {
        if (!isset($detected[$alias])) {
            $issues[] = ['code' => 'lora.metadata_orphaned', 'alias' => $alias, 'message' => 'Metadata exists, but this LoRA was not detected in SD resources.'];
        }
    }

    return [
        'ok' => count($issues) === 0,
        'summary' => count($issues) === 0 ? 'LoRA library audit passed.' : 'LoRA library audit found ' . count($issues) . ' issue(s).',
        'issues' => $issues,
        'counts' => [
            'detected' => count($detected),
            'metadata' => count($library),
            'categories' => loraCategories(),
        ],
    ];
}

function fullDirectorAudit(Store $store, UmaCatalog $catalog, PromptComposer $composer): array
{
    $base = (new NsfwDirectorAuditor($store, $catalog, $composer))->audit();
    $library = $store->getLoraLibrary();
    $director = $store->getPromptLibrary()['nsfw_director'] ?? [];
    $acts = is_array($director['acts'] ?? null) ? $director['acts'] : [];
    $issues = $base['issues'] ?? [];

    foreach ($library as $alias => $meta) {
        $incompatible = tagList((string) ($meta['incompatible_acts'] ?? ''));
        $compatible = tagList((string) ($meta['compatible_acts'] ?? ''));
        foreach (array_merge($incompatible, $compatible) as $actKey) {
            if (in_array($actKey, ['solo'], true)) {
                continue;
            }
            if (!isset($acts[$actKey])) {
                $issues[] = ['code' => 'lora.invalid_act_reference', 'key' => (string) $alias, 'message' => "Referenced act does not exist: {$actKey}"];
            }
        }
        if (!empty($meta['requires_secondary_characters']) && trim((string) ($meta['ensemble_tags'] ?? '')) === '') {
            $issues[] = ['code' => 'lora.ensemble_missing_tags', 'key' => (string) $alias, 'message' => 'Multi-character LoRA should declare ensemble_tags.'];
        }
        if (str_contains(mb_strtolower((string) ($meta['trigger_words'] ?? '')), 'fellatio') && trim((string) ($meta['act_groups'] ?? '')) === '') {
            $issues[] = ['code' => 'lora.unclassified_oral_trigger', 'key' => (string) $alias, 'message' => 'Oral trigger words should declare act_groups/incompatible acts.'];
        }
    }

    return [
        'ok' => count($issues) === 0,
        'summary' => count($issues) === 0 ? 'Full director audit passed.' : 'Full director audit found ' . count($issues) . ' issue(s).',
        'issues' => $issues,
        'counts' => [
            'acts' => count($acts),
            'loras' => count($library),
            'characters' => count($store->getCharacters()),
            'series' => count($store->getSeries()),
        ],
    ];
}

function tagList(string $tags): array
{
    return array_values(array_filter(array_map('trim', explode(',', $tags)), static fn (string $tag): bool => $tag !== ''));
}

function looksSexualLoraMetadata(array $meta, string $alias): bool
{
    $haystack = loraMetadataSearchText($meta, $alias);
    $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $haystack) ?: '';
    $tokens = array_values(array_filter(explode(' ', $normalized), static fn (string $token): bool => $token !== ''));
    $exact = array_fill_keys($tokens, true);
    foreach (['sex', 'oral', 'fellatio', 'cunnilingus', 'blowjob', 'deepthroat', 'handjob', 'facesitting', 'paizuri', 'creampie', 'cum', 'facial', 'penetration', 'anal', 'dildo', 'vibrator', 'hardcore'] as $keyword) {
        if (isset($exact[$keyword])) {
            return true;
        }
    }
    foreach (['titfuck', 'armpitsex', 'sexmarathon', 'facesit', 'facefuck', 'facejerk', 'gangbang'] as $compound) {
        if (str_contains($normalized, $compound)) {
            return true;
        }
    }
    return false;
}

function loraMetadataSearchText(array $meta, string $alias): string
{
    return mb_strtolower(implode(' ', [
        (string) ($meta['alias'] ?? $alias),
        (string) ($meta['name'] ?? ''),
        (string) ($meta['trigger_words'] ?? ''),
        (string) ($meta['notes'] ?? ''),
    ]));
}

function healthCheck(Store $store, SdClient $sd): array
{
    $generatedDir = $store->generatedDir();
    $sqlitePath = Config::sqlitePath();
    $dbWritable = Config::dbDriver() === 'sqlite'
        ? (is_file($sqlitePath) ? is_writable($sqlitePath) : is_writable(dirname($sqlitePath)))
        : Config::databaseUrl() !== '';
    $sdStatus = $sd->status();
    $settings = $store->getSettings();
    $llmApiKey = trim((string) ($settings['llm']['api_key'] ?? ''));

    return [
        'ok' => $dbWritable && is_writable($generatedDir),
        'app' => [
            'env' => Config::appEnv(),
            'php' => PHP_VERSION,
        ],
        'database' => [
            'driver' => Config::dbDriver(),
            'sqlite_path' => Config::dbDriver() === 'sqlite' ? $sqlitePath : null,
            'writable' => $dbWritable,
            'postgres_configured' => Config::databaseUrl() !== '',
        ],
        'storage' => [
            'data_dir' => Config::dataDir(),
            'generated_dir' => $generatedDir,
            'generated_writable' => is_writable($generatedDir),
        ],
        'sd' => $sdStatus,
        'llm' => [
            'base_url' => Config::llmBaseUrl(),
            'model' => Config::llmModel(),
            'configured' => Config::llmBaseUrl() !== '' && Config::llmModel() !== '',
            'api_key_set' => $llmApiKey !== '' || Config::llmApiKey() !== '',
        ],
    ];
}

function serveGenerated(string $path): void
{
    $file = basename($path);
    $store = new Store();
    $target = realpath($store->generatedDir() . '/' . $file);
    $base = realpath($store->generatedDir());
    if (!$target || !$base || !str_starts_with($target, $base) || !is_file($target)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($target);
}

function serveLoraReference(string $path): void
{
    $relative = ltrim($path, '/');
    if (str_contains($relative, '..')) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    $store = new Store();
    $target = realpath(Config::dataDir() . '/' . $relative);
    $base = realpath($store->loraRefsDir());
    if (!$target || !$base || !str_starts_with($target, $base) || !is_file($target)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    $mime = mime_content_type($target) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($target);
}

function serveCharacterReference(string $path): void
{
    $relative = ltrim($path, '/');
    if (str_contains($relative, '..')) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    $store = new Store();
    $target = realpath(Config::dataDir() . '/' . $relative);
    $base = realpath($store->characterRefsDir());
    if (!$target || !$base || !str_starts_with($target, $base) || !is_file($target)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    $mime = mime_content_type($target) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($target);
}

function sse(string $event, array $payload): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    flush();
}

function streamGeneration(PromptComposer $composer, Store $store): void
{
    set_time_limit(0);
    ignore_user_abort(true);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    try {
        $input = readJson();
        $audit = (new PromptAuditor($composer, $store))->audit($input);
        $blockingIssues = array_values(array_filter(
            is_array($audit['issues'] ?? null) ? $audit['issues'] : [],
            static fn (array $issue): bool => ($issue['severity'] ?? '') === 'error'
        ));
        if ($blockingIssues !== []) {
            sse('error', [
                'error' => 'Blocked by prompt audit.',
                'issues' => $blockingIssues,
                'summary' => (string) ($audit['summary'] ?? 'Prompt audit found blocking issues.'),
            ]);
            return;
        }
        $composed = $composer->compose($input);
    } catch (Throwable $e) {
        sse('error', ['error' => $e->getMessage()]);
        return;
    }
    $settings = $store->getSettings();
    $operation = (string) ($input['_operation'] ?? 'generate');
    if (!in_array($operation, ['generate', 'variation'], true)) {
        $operation = 'generate';
    }
    $parentGalleryId = $operation === 'variation' ? max(0, (int) ($input['_parent_gallery_id'] ?? 0)) : 0;
    $payload = $input['sd_payload'] ?? [];
    $payload = array_merge([
        'prompt' => $composed['prompt'],
        'negative_prompt' => $composed['negative_prompt'],
        'steps' => (int) ($settings['sd']['steps'] ?? 28),
        'cfg_scale' => (float) ($settings['sd']['cfg_scale'] ?? 6),
        'width' => (int) ($settings['sd']['width'] ?? 832),
        'height' => (int) ($settings['sd']['height'] ?? 1216),
        'sampler_name' => (string) ($settings['sd']['sampler'] ?? 'Euler a'),
        'seed' => -1,
        'batch_size' => (int) ($settings['sd']['batch_size'] ?? 1),
        'n_iter' => 1,
    ], is_array($payload) ? $payload : []);
    if (!empty($settings['sd']['checkpoint'])) {
        $payload['override_settings'] = array_merge($payload['override_settings'] ?? [], [
            'sd_model_checkpoint' => $settings['sd']['checkpoint'],
        ]);
    }
    $payload = applyHiresSettings($payload, $settings);
    $hiresProfile = resolveHiresProfile($settings, (string) ($input['sd_payload']['hires_profile'] ?? ''), 'fine');
    $hiresAnatomy = hiresAnatomySettings($settings, $hiresProfile);
    $hiresAnatomy['applied'] = !empty($payload['enable_hr']) && $hiresAnatomy['enabled'];
    $hiresAnatomy['profile'] = $hiresProfile['key'];
    $hiresAnatomy['upscaler'] = $hiresProfile['upscaler'];
    $job = $store->createJob([
        'type' => 'generate',
        'status' => 'running',
        'payload' => ['input' => $input, 'sd_payload' => $payload],
        'progress' => 0.01,
        'started_at' => gmdate('c'),
    ]);
    $jobId = (int) ($job['id'] ?? 0);

    sse('start', ['job_id' => $jobId, 'prompt' => $payload['prompt'], 'negative_prompt' => $payload['negative_prompt']]);

    $multi = curl_multi_init();
    $txt2img = curl_init(Config::sdBaseUrl() . '/sdapi/v1/txt2img');
    curl_setopt_array($txt2img, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 0,
    ]);
    curl_multi_add_handle($multi, $txt2img);

    $running = null;
    $lastProgressAt = 0.0;
    do {
        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        $now = microtime(true);
        if ($now - $lastProgressAt >= 0.75) {
            try {
                $progress = HttpClient::getJson(Config::sdBaseUrl() . '/sdapi/v1/progress?skip_current_image=false', 4);
                sse('progress', [
                    'job_id' => $jobId,
                    'progress' => $progress['progress'] ?? 0,
                    'eta_relative' => $progress['eta_relative'] ?? null,
                    'current_image' => $progress['current_image'] ?? null,
                ]);
                if ($jobId > 0 && isset($progress['progress']) && is_numeric($progress['progress'])) {
                    $store->updateJob($jobId, ['progress' => (float) $progress['progress']]);
                }
            } catch (Throwable $e) {
                sse('progress', ['job_id' => $jobId, 'progress' => null, 'message' => $e->getMessage()]);
            }
            $lastProgressAt = $now;
        }

        if ($running) {
            curl_multi_select($multi, 0.25);
        }
    } while ($running);

    $body = curl_multi_getcontent($txt2img);
    $statusCode = (int) curl_getinfo($txt2img, CURLINFO_RESPONSE_CODE);
    $error = curl_error($txt2img);
    curl_multi_remove_handle($multi, $txt2img);
    curl_multi_close($multi);

    if ($body === false || $statusCode >= 400) {
        $message = $error !== '' ? $error : substr((string) $body, 0, 500);
        if ($jobId > 0) {
            $store->updateJob($jobId, ['status' => 'failed', 'error' => $message, 'finished_at' => gmdate('c')]);
        }
        sse('error', ['job_id' => $jobId, 'error' => $message]);
        return;
    }

    $result = json_decode((string) $body, true);
    if (!is_array($result)) {
        if ($jobId > 0) {
            $store->updateJob($jobId, ['status' => 'failed', 'error' => 'SD returned invalid JSON.', 'finished_at' => gmdate('c')]);
        }
        sse('error', ['job_id' => $jobId, 'error' => 'SD returned invalid JSON.']);
        return;
    }

    $imagePaths = saveGeneratedImages($result['images'] ?? [], $store);
    $meta = extractSdMetadata($result, $payload, $imagePaths);
    $storedPayload = $payload;
    $storedPayload['_hires_anatomy_guard'] = $hiresAnatomy;
    $storedPayload['_hires_profile'] = [
        'hires_profile' => $hiresProfile['key'],
        'hires_upscaler' => $hiresProfile['upscaler'],
        'hires_denoise' => (float) ($hiresProfile['denoising_strength'] ?? 0),
        'anatomy_guard_version' => $hiresAnatomy['version'],
    ];
    $storedPayload['_prompt_layers'] = is_array($composed['layers'] ?? null) ? $composed['layers'] : [];
    $autoLlmDebug = is_array($input['_auto_llm'] ?? null) ? $input['_auto_llm'] : [];
    $storedPayload['_auto_llm'] = [
        'enabled' => !empty($autoLlmDebug['enabled']),
        'used' => !empty($autoLlmDebug['used']),
        'used_seed_cache' => !empty($autoLlmDebug['used_seed_cache']),
        'seed_locked' => !empty($autoLlmDebug['seed_locked']),
        'status' => (string) ($autoLlmDebug['status'] ?? ''),
    ];
    $storedPayload['_planner_input'] = [
        'global_prompt' => (string) ($input['global_prompt'] ?? ''),
        'pose_enabled' => (bool) ($input['pose_enabled'] ?? false),
        'pose_intensity' => (string) ($input['pose_intensity'] ?? 'suggestive'),
        'pose_key' => (string) ($input['pose_key'] ?? ''),
        'style_tags' => (string) ($input['style_tags'] ?? ''),
        'manual_prompt' => (string) ($input['manual_prompt'] ?? ''),
        'llm_polish' => (string) ($input['llm_polish'] ?? ''),
        'negative_prompt' => (string) ($input['negative_prompt'] ?? ''),
        'mode' => (string) ($input['mode'] ?? 'standard'),
        'character_appearance' => (string) ($input['character_appearance'] ?? ''),
        'quick_tags' => is_array($input['quick_tags'] ?? null) ? $input['quick_tags'] : [],
        'custom_tags' => (string) ($input['custom_tags'] ?? ''),
        'nsfw_enabled' => (bool) ($input['nsfw_enabled'] ?? false),
        'nsfw_strict' => !array_key_exists('nsfw_strict', $input) || (bool) $input['nsfw_strict'],
        'nsfw_intensity' => (string) ($input['nsfw_intensity'] ?? ''),
        'nsfw_act' => (string) ($input['nsfw_act'] ?? ''),
        'nsfw_scene_intent' => (string) ($input['nsfw_scene_intent'] ?? ''),
        'nsfw_focus' => (string) ($input['nsfw_focus'] ?? ''),
        'nsfw_contact_lock' => (string) ($input['nsfw_contact_lock'] ?? ''),
        'nsfw_expression' => (string) ($input['nsfw_expression'] ?? ''),
        'nsfw_clothing_state' => (string) ($input['nsfw_clothing_state'] ?? ''),
        'nsfw_effects' => is_array($input['nsfw_effects'] ?? null) ? $input['nsfw_effects'] : [],
        'nsfw_boost_enabled' => (bool) ($input['nsfw_boost_enabled'] ?? false),
        'nsfw_camera' => (string) ($input['nsfw_camera'] ?? ''),
        'loras' => is_array($input['loras'] ?? null) ? $input['loras'] : [],
        'secondary_characters' => is_array($input['secondary_characters'] ?? null) ? $input['secondary_characters'] : [],
        'anonymous_partner' => !array_key_exists('anonymous_partner', $input) || (bool) $input['anonymous_partner'],
        'series_id' => (int) ($input['series_id'] ?? ($composed['character']['series_id'] ?? 0)),
        'series_name' => (string) ($input['series_name'] ?? ($composed['character']['series_name'] ?? '')),
        'character_id' => (int) ($input['character_id'] ?? ($composed['character']['id'] ?? 0)),
        'character_name' => (string) ($input['character_name'] ?? ($composed['character']['name'] ?? ($input['uma'] ?? ''))),
        'auto_llm' => $storedPayload['_auto_llm'],
    ];
    $galleryItem = $store->addGalleryItem([
        'uma' => (string) ($input['uma'] ?? ''),
        'outfit' => (string) ($input['outfit'] ?? ''),
        'prompt' => $payload['prompt'],
        'negative_prompt' => $payload['negative_prompt'],
        'payload' => $storedPayload,
        'image_paths' => $imagePaths,
        'seed' => (int) ($payload['seed'] ?? -1),
        'actual_seed' => $meta['seed'],
        'width' => $meta['width'],
        'height' => $meta['height'],
        'parent_gallery_id' => $parentGalleryId > 0 ? $parentGalleryId : null,
        'series_id' => (int) ($composed['character']['series_id'] ?? 0),
        'series_name' => (string) ($composed['character']['series_name'] ?? ''),
        'character_id' => (int) ($composed['character']['id'] ?? 0),
        'character_name' => (string) ($composed['character']['name'] ?? ($input['uma'] ?? '')),
        'base_lora' => (string) ($composed['base_lora'] ?? ''),
        'source_type' => (string) ($composed['character']['source_type'] ?? ''),
        'operation' => $operation,
    ]);

    $store->addHistory([
        'prompt' => $payload['prompt'],
        'negative_prompt' => $payload['negative_prompt'],
        'payload' => $storedPayload,
        'result' => [
            'parameters' => $result['parameters'] ?? null,
            'info' => $result['info'] ?? null,
            'image_count' => isset($result['images']) && is_array($result['images']) ? count($result['images']) : 0,
            'image_paths' => $imagePaths,
            'gallery_id' => $galleryItem['id'] ?? null,
            'parent_gallery_id' => $parentGalleryId > 0 ? $parentGalleryId : null,
            'actual_seed' => $meta['seed'],
            'width' => $meta['width'],
            'height' => $meta['height'],
        ],
    ]);

    if ($jobId > 0) {
        $store->updateJob($jobId, [
            'status' => 'done',
            'progress' => 1,
            'result' => [
                'gallery_id' => $galleryItem['id'] ?? null,
                'parent_gallery_id' => $parentGalleryId > 0 ? $parentGalleryId : null,
                'image_paths' => $imagePaths,
                'actual_seed' => $meta['seed'],
                'width' => $meta['width'],
                'height' => $meta['height'],
            ],
            'finished_at' => gmdate('c'),
        ]);
    }

    sse('done', [
        'job_id' => $jobId,
        'images' => $result['images'] ?? [],
        'image_paths' => $imagePaths,
        'image_urls' => $galleryItem['image_urls'] ?? [],
        'gallery_item' => $galleryItem,
        'info' => $result['info'] ?? null,
        'parameters' => $result['parameters'] ?? null,
    ]);
}

function streamEnhance(int $galleryId, Store $store): void
{
    set_time_limit(0);
    ignore_user_abort(true);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $input = readJson();
    $item = $store->getGalleryItem($galleryId);
    if (!$item) {
        sse('error', ['error' => 'Gallery item not found.']);
        return;
    }

    $imagePath = (string) ($item['image_paths'][0] ?? '');
    $base = realpath($store->generatedDir());
    $target = $imagePath !== '' ? realpath($imagePath) : false;
    if (!$target || !$base || !str_starts_with($target, $base) || !is_file($target)) {
        sse('error', ['error' => 'Saved image file is missing.']);
        return;
    }

    $settings = $store->getSettings();
    $hires = $settings['sd']['hires'] ?? [];
    $hiresProfile = resolveHiresProfile($settings, (string) ($input['hires_profile'] ?? ''), 'enhance');
    $sourceSize = @getimagesize($target) ?: [0, 0];
    $sourceWidth = (int) ($sourceSize[0] ?: $item['width'] ?: ($settings['sd']['width'] ?? 832));
    $sourceHeight = (int) ($sourceSize[1] ?: $item['height'] ?: ($settings['sd']['height'] ?? 1216));
    $scale = max(1.0, (float) ($hiresProfile['scale'] ?? $hires['scale'] ?? 2));
    $resizeX = (int) ($hires['resize_x'] ?? 0);
    $resizeY = (int) ($hires['resize_y'] ?? 0);
    $preserveExactSize = ($hiresProfile['key'] ?? '') === 'repair_preserve';
    $targetWidth = $preserveExactSize ? $sourceWidth : ($resizeX > 0 ? $resizeX : roundToMultiple((int) round($sourceWidth * $scale), 64));
    $targetHeight = $preserveExactSize ? $sourceHeight : ($resizeY > 0 ? $resizeY : roundToMultiple((int) round($sourceHeight * $scale), 64));
    $seed = (int) ($input['seed'] ?? $item['actual_seed'] ?? $item['seed'] ?? -1);

    $hiresAnatomy = hiresAnatomySettings($settings, $hiresProfile);
    $hiresAnatomy['applied'] = $hiresAnatomy['enabled'];
    $hiresAnatomy['profile'] = $hiresProfile['key'];
    $hiresAnatomy['upscaler'] = $hiresProfile['upscaler'];
    $prompt = stripPromptTags((string) $item['prompt'], (string) ($hiresProfile['strip_prompt_tags'] ?? ''));
    $negativePrompt = (string) $item['negative_prompt'];
    $prompt = appendPromptSegments($prompt, (string) ($hiresProfile['style_positive'] ?? ''));
    $negativePrompt = appendPromptSegments($negativePrompt, (string) ($hiresProfile['style_negative'] ?? ''));
    if ($hiresAnatomy['enabled']) {
        $prompt = appendPromptSegments($prompt, $hiresAnatomy['positive']);
        $negativePrompt = appendPromptSegments($negativePrompt, $hiresAnatomy['negative']);
    }

    $payload = [
        'init_images' => [base64_encode((string) file_get_contents($target))],
        'prompt' => $prompt,
        'negative_prompt' => $negativePrompt,
        'steps' => (int) (($hiresProfile['steps'] ?? 0) > 0 ? $hiresProfile['steps'] : ($settings['sd']['steps'] ?? 28)),
        'cfg_scale' => (float) ($hiresProfile['cfg_scale'] ?? $hires['cfg_scale'] ?? $settings['sd']['cfg_scale'] ?? 7),
        'width' => $targetWidth,
        'height' => $targetHeight,
        'sampler_name' => (string) ($settings['sd']['sampler'] ?? 'Euler a'),
        'denoising_strength' => (float) ($hiresProfile['denoising_strength'] ?? $hires['denoising_strength'] ?? 0.6),
        'seed' => $seed,
        'batch_size' => 1,
        'n_iter' => 1,
        'resize_mode' => 0,
    ];
    if (!empty($hiresProfile['sampler'])) {
        $payload['sampler_name'] = (string) $hiresProfile['sampler'];
    }
    if (!empty($hiresProfile['scheduler'])) {
        $payload['scheduler'] = (string) $hiresProfile['scheduler'];
    }
    if (!empty($settings['sd']['checkpoint'])) {
        $payload['override_settings'] = ['sd_model_checkpoint' => $settings['sd']['checkpoint']];
    }
    $job = $store->createJob([
        'type' => 'enhance',
        'status' => 'running',
        'payload' => [
            'source_gallery_id' => $galleryId,
            'input' => $input,
            'sd_payload' => array_diff_key($payload, ['init_images' => true]),
        ],
        'progress' => 0.01,
        'started_at' => gmdate('c'),
    ]);
    $jobId = (int) ($job['id'] ?? 0);

    sse('start', [
        'job_id' => $jobId,
        'prompt' => $payload['prompt'],
        'negative_prompt' => $payload['negative_prompt'],
        'source_gallery_id' => $galleryId,
        'target_width' => $targetWidth,
        'target_height' => $targetHeight,
    ]);

    $result = sdStreamRequest('/sdapi/v1/img2img', $payload);
    if (!$result['ok']) {
        if ($jobId > 0) {
            $store->updateJob($jobId, ['status' => 'failed', 'error' => $result['error'], 'finished_at' => gmdate('c')]);
        }
        sse('error', ['job_id' => $jobId, 'error' => $result['error']]);
        return;
    }

    $body = json_decode((string) $result['body'], true);
    if (!is_array($body)) {
        if ($jobId > 0) {
            $store->updateJob($jobId, ['status' => 'failed', 'error' => 'SD returned invalid JSON.', 'finished_at' => gmdate('c')]);
        }
        sse('error', ['job_id' => $jobId, 'error' => 'SD returned invalid JSON.']);
        return;
    }

    $imagePaths = saveGeneratedImages($body['images'] ?? [], $store);
    $meta = extractSdMetadata($body, $payload, $imagePaths);
    $storedPayload = $payload;
    unset($storedPayload['init_images']);
    $storedPayload['_source_image'] = $target;
    $storedPayload['_hires_anatomy_guard'] = $hiresAnatomy;
    $storedPayload['_hires_profile'] = [
        'hires_profile' => $hiresProfile['key'],
        'hires_upscaler' => $hiresProfile['upscaler'],
        'hires_denoise' => (float) ($hiresProfile['denoising_strength'] ?? 0),
        'anatomy_guard_version' => $hiresAnatomy['version'],
    ];
    if (isset($item['payload']['_planner_input']) && is_array($item['payload']['_planner_input'])) {
        $storedPayload['_planner_input'] = $item['payload']['_planner_input'];
    }
    if (isset($item['payload']['_prompt_layers']) && is_array($item['payload']['_prompt_layers'])) {
        $storedPayload['_prompt_layers'] = $item['payload']['_prompt_layers'];
    }
    $galleryItem = $store->addGalleryItem([
        'uma' => (string) ($item['uma'] ?? ''),
        'outfit' => (string) ($item['outfit'] ?? ''),
        'prompt' => $payload['prompt'],
        'negative_prompt' => $payload['negative_prompt'],
        'payload' => $storedPayload,
        'image_paths' => $imagePaths,
        'seed' => $seed,
        'actual_seed' => $meta['seed'],
        'width' => $meta['width'],
        'height' => $meta['height'],
        'parent_gallery_id' => $galleryId,
        'series_id' => (int) ($item['series_id'] ?? 0),
        'series_name' => (string) ($item['series_name'] ?? ''),
        'character_id' => (int) ($item['character_id'] ?? 0),
        'character_name' => (string) ($item['character_name'] ?? $item['uma'] ?? ''),
        'base_lora' => (string) ($item['base_lora'] ?? ''),
        'source_type' => (string) ($item['source_type'] ?? ''),
        'operation' => 'enhance',
    ]);

    $store->addHistory([
        'prompt' => $payload['prompt'],
        'negative_prompt' => $payload['negative_prompt'],
        'payload' => $storedPayload,
        'result' => [
            'parameters' => $body['parameters'] ?? null,
            'info' => $body['info'] ?? null,
            'image_count' => isset($body['images']) && is_array($body['images']) ? count($body['images']) : 0,
            'image_paths' => $imagePaths,
            'gallery_id' => $galleryItem['id'] ?? null,
            'parent_gallery_id' => $galleryId,
            'operation' => 'enhance',
            'actual_seed' => $meta['seed'],
            'width' => $meta['width'],
            'height' => $meta['height'],
        ],
    ]);

    if ($jobId > 0) {
        $store->updateJob($jobId, [
            'status' => 'done',
            'progress' => 1,
            'result' => [
                'gallery_id' => $galleryItem['id'] ?? null,
                'parent_gallery_id' => $galleryId,
                'operation' => 'enhance',
                'image_paths' => $imagePaths,
                'actual_seed' => $meta['seed'],
                'width' => $meta['width'],
                'height' => $meta['height'],
            ],
            'finished_at' => gmdate('c'),
        ]);
    }

    sse('done', [
        'job_id' => $jobId,
        'images' => $body['images'] ?? [],
        'image_paths' => $imagePaths,
        'image_urls' => $galleryItem['image_urls'] ?? [],
        'gallery_item' => $galleryItem,
        'info' => $body['info'] ?? null,
        'parameters' => $body['parameters'] ?? null,
    ]);
}

function sdStreamRequest(string $path, array $payload): array
{
    $multi = curl_multi_init();
    $request = curl_init(Config::sdBaseUrl() . $path);
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 0,
    ]);
    curl_multi_add_handle($multi, $request);

    $running = null;
    $lastProgressAt = 0.0;
    do {
        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        $now = microtime(true);
        if ($now - $lastProgressAt >= 0.75) {
            try {
                $progress = HttpClient::getJson(Config::sdBaseUrl() . '/sdapi/v1/progress?skip_current_image=false', 4);
                sse('progress', [
                    'progress' => $progress['progress'] ?? 0,
                    'eta_relative' => $progress['eta_relative'] ?? null,
                    'current_image' => $progress['current_image'] ?? null,
                ]);
            } catch (Throwable $e) {
                sse('progress', ['progress' => null, 'message' => $e->getMessage()]);
            }
            $lastProgressAt = $now;
        }

        if ($running) {
            curl_multi_select($multi, 0.25);
        }
    } while ($running);

    $body = curl_multi_getcontent($request);
    $statusCode = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $error = curl_error($request);
    curl_multi_remove_handle($multi, $request);
    curl_multi_close($multi);

    if ($body === false || $statusCode >= 400) {
        return ['ok' => false, 'error' => $error !== '' ? $error : substr((string) $body, 0, 500)];
    }

    return ['ok' => true, 'body' => (string) $body];
}

function applyHiresSettings(array $payload, array $settings): array
{
    if (empty($payload['enable_hr'])) {
        unset(
            $payload['enable_hr'],
            $payload['hr_upscaler'],
            $payload['hr_scale'],
            $payload['hr_second_pass_steps'],
            $payload['denoising_strength'],
            $payload['hr_cfg'],
            $payload['hr_resize_x'],
            $payload['hr_resize_y'],
            $payload['hr_prompt'],
            $payload['hr_negative_prompt'],
            $payload['hires_profile']
        );
        return $payload;
    }

    $hires = $settings['sd']['hires'] ?? [];
    $profile = resolveHiresProfile($settings, (string) ($payload['hires_profile'] ?? ''), 'fine');
    unset($payload['hires_profile']);
    $anatomy = hiresAnatomySettings($settings, $profile);
    $payload['enable_hr'] = true;
    if (!empty($profile['sampler'])) {
        $payload['sampler_name'] = (string) $profile['sampler'];
    }
    if (!empty($profile['scheduler'])) {
        $payload['scheduler'] = (string) $profile['scheduler'];
    }
    $payload['hr_upscaler'] = (string) ($payload['hr_upscaler'] ?? $profile['upscaler'] ?? $hires['upscaler'] ?? 'Latent');
    $payload['hr_scale'] = sdWholeNumber((float) ($payload['hr_scale'] ?? $profile['scale'] ?? $hires['scale'] ?? 2));
    $payload['hr_second_pass_steps'] = (int) ($payload['hr_second_pass_steps'] ?? $profile['steps'] ?? $hires['steps'] ?? 0);
    $payload['denoising_strength'] = (float) ($payload['denoising_strength'] ?? $profile['denoising_strength'] ?? $hires['denoising_strength'] ?? 0.6);
    $payload['hr_cfg'] = sdWholeNumber((float) ($payload['hr_cfg'] ?? $profile['cfg_scale'] ?? $hires['cfg_scale'] ?? 7));
    $payload['hr_resize_x'] = (int) ($payload['hr_resize_x'] ?? $hires['resize_x'] ?? 0);
    $payload['hr_resize_y'] = (int) ($payload['hr_resize_y'] ?? $hires['resize_y'] ?? 0);
    $payload['hr_additional_modules'] = is_array($payload['hr_additional_modules'] ?? null) ? $payload['hr_additional_modules'] : [];
    $payload['hr_prompt'] = (string) ($payload['hr_prompt'] ?? $payload['prompt'] ?? '');
    $payload['hr_negative_prompt'] = (string) ($payload['hr_negative_prompt'] ?? $payload['negative_prompt'] ?? '');
    $payload['hr_prompt'] = stripPromptTags($payload['hr_prompt'], (string) ($profile['strip_prompt_tags'] ?? ''));
    $payload['hr_prompt'] = appendPromptSegments($payload['hr_prompt'], (string) ($profile['style_positive'] ?? ''));
    $payload['hr_negative_prompt'] = appendPromptSegments($payload['hr_negative_prompt'], (string) ($profile['style_negative'] ?? ''));
    if ($anatomy['enabled']) {
        $payload['hr_prompt'] = appendPromptSegments($payload['hr_prompt'], $anatomy['positive']);
        $payload['hr_negative_prompt'] = appendPromptSegments($payload['hr_negative_prompt'], $anatomy['negative']);
    }
    return $payload;
}

function resolveHiresProfile(array $settings, string $requested = '', string $default = 'fine'): array
{
    $hires = $settings['sd']['hires'] ?? [];
    $profiles = is_array($hires['profiles'] ?? null) ? $hires['profiles'] : [];
    $fallbackProfiles = [
        'repair_hiresfix' => [
            'label' => 'Repair + Hires.fix',
            'upscaler' => 'Latent',
            'scale' => 2,
            'steps' => 0,
            'denoising_strength' => 0.45,
            'cfg_scale' => 6,
            'anatomy_guard_enabled' => true,
            'sampler' => 'DPM++ 2M',
            'scheduler' => 'Karras',
            'style_positive' => 'smooth anime rendering, polished anime shading, soft detailed shading, smooth gradients, clean color fill, detailed skin texture, detailed fingernails, delicate fingers, natural highlights',
            'style_negative' => 'motion lines, speed lines, action lines, sketch lines, rough lineart, thick outlines, scratchy lines, noisy outlines, smeared details, oversharpened edges, cel shading artifacts, blocky shading, unfinished hands, undetailed fingernails',
            'strip_prompt_tags' => 'clean lineart, lineart, anime screencap, screencap, motion blur, motion lines, speed lines, action lines, dynamic motion, fine motion',
        ],
        'repair_preserve' => [
            'label' => 'Repair Preserve Quality',
            'upscaler' => 'Latent',
            'scale' => 1,
            'steps' => 0,
            'denoising_strength' => 0.28,
            'cfg_scale' => 5,
            'anatomy_guard_enabled' => true,
            'sampler' => 'DPM++ 2M',
            'scheduler' => 'Karras',
            'style_positive' => '',
            'style_negative' => '',
            'strip_prompt_tags' => '',
        ],
    ];
    $profiles = array_replace_recursive($fallbackProfiles, $profiles);
    $key = preg_replace('/[^a-zA-Z0-9_-]+/', '', trim($requested)) ?: '';
    $legacyAliases = [
        'repair' => 'repair_preserve',
        'repair_latent' => 'repair_hiresfix',
        'repair_polish' => 'repair_hiresfix',
        'repair_fine_detail' => 'repair_hiresfix',
    ];
    $default = $legacyAliases[$default] ?? $default;
    if ($key === '') {
        $key = (string) ($hires['active_profile'] ?? $default);
    }
    $key = $legacyAliases[$key] ?? $key;
    if (!isset($profiles[$key]) || !is_array($profiles[$key])) {
        $key = isset($profiles[$default]) ? $default : 'fine';
    }
    $profile = is_array($profiles[$key] ?? null) ? $profiles[$key] : [];

    return [
        'key' => $key,
        'label' => (string) ($profile['label'] ?? ucfirst($key)),
        'upscaler' => (string) ($profile['upscaler'] ?? $hires['upscaler'] ?? 'Latent'),
        'scale' => (float) ($profile['scale'] ?? $hires['scale'] ?? 2),
        'steps' => (int) ($profile['steps'] ?? $hires['steps'] ?? 0),
        'denoising_strength' => (float) ($profile['denoising_strength'] ?? $hires['denoising_strength'] ?? 0.6),
        'cfg_scale' => (float) ($profile['cfg_scale'] ?? $hires['cfg_scale'] ?? 7),
        'anatomy_guard_enabled' => ($profile['anatomy_guard_enabled'] ?? $hires['anatomy_guard_enabled'] ?? true) !== false,
        'sampler' => trim((string) ($profile['sampler'] ?? '')),
        'scheduler' => trim((string) ($profile['scheduler'] ?? '')),
        'style_positive' => trim((string) ($profile['style_positive'] ?? '')),
        'style_negative' => trim((string) ($profile['style_negative'] ?? '')),
        'strip_prompt_tags' => trim((string) ($profile['strip_prompt_tags'] ?? '')),
    ];
}

function hiresAnatomySettings(array $settings, ?array $profile = null): array
{
    $hires = $settings['sd']['hires'] ?? [];
    $profile ??= resolveHiresProfile($settings);
    $positive = appendPromptSegments(
        (string) ($hires['abdomen_guard'] ?? ''),
        (string) ($hires['hands_guard'] ?? ''),
        (string) ($hires['feet_guard'] ?? '')
    );
    $negative = appendPromptSegments(
        (string) ($hires['anatomy_negative'] ?? ''),
        'extra toes, six toes, seven toes, too many toes, duplicated toes, malformed toes, fused toes'
    );

    return [
        'enabled' => ($profile['anatomy_guard_enabled'] ?? $hires['anatomy_guard_enabled'] ?? true) !== false,
        'version' => (int) ($hires['anatomy_guard_version'] ?? 3),
        'positive' => $positive,
        'negative' => $negative,
        'denoising_strength' => (float) ($profile['denoising_strength'] ?? $hires['anatomy_denoising_strength'] ?? $hires['denoising_strength'] ?? 0.6),
        'abdomen_guard' => trim((string) ($hires['abdomen_guard'] ?? '')),
        'hands_guard' => trim((string) ($hires['hands_guard'] ?? '')),
        'feet_guard' => trim((string) ($hires['feet_guard'] ?? '')),
        'feet_safe_crop' => trim((string) ($hires['feet_safe_crop'] ?? '')),
    ];
}

function appendPromptSegments(string ...$segments): string
{
    $clean = [];
    foreach ($segments as $segment) {
        $segment = trim($segment, " \t\n\r\0\x0B,");
        if ($segment !== '') {
            $clean[] = $segment;
        }
    }
    return implode(', ', $clean);
}

function stripPromptTags(string $prompt, string $stripTags): string
{
    $blocked = array_filter(array_map(static fn (string $tag): string => mb_strtolower(trim($tag)), explode(',', $stripTags)));
    if (!$blocked) {
        return $prompt;
    }
    $parts = array_filter(array_map('trim', explode(',', $prompt)), static fn (string $tag): bool => $tag !== '');
    $parts = array_values(array_filter($parts, static fn (string $tag): bool => !in_array(mb_strtolower($tag), $blocked, true)));
    return implode(', ', $parts);
}

function sdWholeNumber(float $value): int|float
{
    return abs($value - round($value)) < 0.0001 ? (int) round($value) : $value;
}

function extractSdMetadata(array $result, array $payload, array $imagePaths): array
{
    $info = [];
    if (isset($result['info']) && is_string($result['info'])) {
        $decoded = json_decode($result['info'], true);
        if (is_array($decoded)) {
            $info = $decoded;
        }
    } elseif (isset($result['info']) && is_array($result['info'])) {
        $info = $result['info'];
    }

    $parameters = isset($result['parameters']) && is_array($result['parameters']) ? $result['parameters'] : [];
    $seed = (int) ($parameters['seed'] ?? $info['seed'] ?? ($info['all_seeds'][0] ?? null) ?? $payload['seed'] ?? -1);
    $width = (int) ($info['width'] ?? $parameters['width'] ?? $payload['width'] ?? 0);
    $height = (int) ($info['height'] ?? $parameters['height'] ?? $payload['height'] ?? 0);

    if (($width <= 0 || $height <= 0) && !empty($imagePaths[0])) {
        $size = @getimagesize($imagePaths[0]);
        if ($size) {
            $width = (int) $size[0];
            $height = (int) $size[1];
        }
    }

    return ['seed' => $seed, 'width' => $width, 'height' => $height];
}

function roundToMultiple(int $value, int $multiple): int
{
    return max($multiple, (int) (round($value / $multiple) * $multiple));
}

function saveGeneratedImages(array $images, Store $store): array
{
    $paths = [];
    foreach ($images as $index => $image) {
        if (!is_string($image) || $image === '') {
            continue;
        }
        $bytes = base64_decode($image, true);
        if ($bytes === false) {
            continue;
        }
        $name = sprintf('uma_%s_%s_%02d.png', gmdate('Ymd_His'), bin2hex(random_bytes(3)), $index + 1);
        $path = $store->generatedDir() . '/' . $name;
        file_put_contents($path, $bytes);
        $paths[] = $path;
    }
    return $paths;
}
