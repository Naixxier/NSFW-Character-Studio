<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$sqlitePath = Config::env('SQLITE_SOURCE_PATH', Config::sqlitePath());
$databaseUrl = Config::databaseUrl();
if (!$sqlitePath || !is_file($sqlitePath)) {
    fwrite(STDERR, "SQLite source not found: {$sqlitePath}\n");
    exit(1);
}
if ($databaseUrl === '') {
    fwrite(STDERR, "DATABASE_URL is required.\n");
    exit(1);
}

$source = new PDO('sqlite:' . $sqlitePath);
$source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$target = pgPdoFromUrl($databaseUrl);
$target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
runPgMigrations($target);
if (truthy(Config::env('MIGRATION_TRUNCATE_TARGET', '0'))) {
    truncatePgTarget($target);
}

$tables = [
    'settings' => ['key'],
    'lora_triggers' => ['alias'],
    'presets' => ['id'],
    'series' => ['id'],
    'characters' => ['id'],
    'character_outfits' => ['id'],
    'character_appearances' => ['id'],
    'character_loras' => ['id'],
    'lora_library' => ['alias'],
    'history' => ['id'],
    'gallery' => ['id'],
    'generation_jobs' => ['id'],
    'image_assets' => ['id'],
];

$report = [];
$target->beginTransaction();
try {
    foreach ($tables as $table => $keys) {
        if (!sqliteTableExists($source, $table)) {
            $report[$table] = ['skipped' => true, 'count' => 0];
            continue;
        }
        $rows = $source->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $row = normalizePgRow($table, $row);
            upsertPgRow($target, $table, $row, $keys);
        }
        if (in_array('id', $keys, true)) {
            resetPgSequence($target, $table);
        }
        $report[$table] = ['skipped' => false, 'count' => count($rows)];
    }
    $target->commit();
} catch (Throwable $e) {
    $target->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo json_encode(['ok' => true, 'source' => $sqlitePath, 'report' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function pgPdoFromUrl(string $url): PDO
{
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'pgsql') {
        throw new InvalidArgumentException('DATABASE_URL must use pgsql://');
    }
    $host = $parts['host'] ?? 'localhost';
    $port = (int) ($parts['port'] ?? 5432);
    $db = ltrim((string) ($parts['path'] ?? ''), '/');
    $user = rawurldecode((string) ($parts['user'] ?? ''));
    $pass = rawurldecode((string) ($parts['pass'] ?? ''));
    return new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass);
}

function runPgMigrations(PDO $pdo): void
{
    $migration = __DIR__ . '/../migrations/pgsql/001_initial.sql';
    if (!is_file($migration)) {
        return;
    }
    $pdo->exec((string) file_get_contents($migration));
}

function truncatePgTarget(PDO $pdo): void
{
    $tables = [
        'image_assets',
        'generation_jobs',
        'gallery',
        'history',
        'lora_library',
        'lora_triggers',
        'character_loras',
        'character_outfits',
        'character_appearances',
        'characters',
        'series',
        'presets',
        'settings',
    ];
    $quoted = implode(', ', array_map(static fn (string $table): string => '"' . $table . '"', $tables));
    $pdo->exec('TRUNCATE TABLE ' . $quoted . ' RESTART IDENTITY CASCADE');
}

function truthy(?string $value): bool
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function sqliteTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function normalizePgRow(string $table, array $row): array
{
    $jsonColumns = [
        'settings' => ['value_json'],
        'presets' => ['meta_json'],
        'characters' => ['aliases_json', 'nsfw_profile_json'],
        'history' => ['payload_json', 'result_json'],
        'gallery' => ['payload_json', 'image_paths_json'],
        'generation_jobs' => ['payload_json', 'result_json'],
        'image_assets' => ['metadata_json'],
    ];
    $boolColumns = [
        'series' => ['nsfw_default'],
        'lora_library' => ['enabled', 'favorite', 'requires_outfit_none', 'needs_trigger', 'requires_secondary_characters'],
        'lora_variants' => ['enabled', 'strip_clothing_when_outfit_active', 'requires_secondary_characters'],
    ];
    foreach ($jsonColumns[$table] ?? [] as $column) {
        $decoded = json_decode((string) ($row[$column] ?? ''), true);
        $row[$column] = json_encode($decoded ?? (str_ends_with($column, '_json') && str_contains($column, 'aliases') ? [] : new stdClass()), JSON_UNESCAPED_SLASHES);
    }
    foreach ($boolColumns[$table] ?? [] as $column) {
        $row[$column] = !empty($row[$column]) ? 'true' : 'false';
    }
    return $row;
}

function upsertPgRow(PDO $pdo, string $table, array $row, array $keys): void
{
    $columns = array_keys($row);
    $quoted = array_map(static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"', $columns);
    $params = array_map(static fn (string $column): string => ':' . $column, $columns);
    $conflict = implode(', ', array_map(static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"', $keys));
    $updates = array_values(array_filter($columns, static fn (string $column): bool => !in_array($column, $keys, true)));
    $updateSql = $updates
        ? ' DO UPDATE SET ' . implode(', ', array_map(static fn (string $column): string => '"' . $column . '" = EXCLUDED."' . $column . '"', $updates))
        : ' DO NOTHING';
    $sql = 'INSERT INTO "' . $table . '" (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $params) . ') ON CONFLICT (' . $conflict . ')' . $updateSql;
    $stmt = $pdo->prepare($sql);
    foreach ($row as $column => $value) {
        $stmt->bindValue(':' . $column, $value);
    }
    $stmt->execute();
}

function resetPgSequence(PDO $pdo, string $table): void
{
    $stmt = $pdo->prepare("SELECT pg_get_serial_sequence(:table, 'id')");
    $stmt->execute(['table' => $table]);
    $sequence = $stmt->fetchColumn();
    if (!is_string($sequence) || $sequence === '') {
        return;
    }
    $quotedTable = '"' . str_replace('"', '""', $table) . '"';
    $pdo->exec("SELECT setval(" . $pdo->quote($sequence) . ", COALESCE((SELECT MAX(id) FROM {$quotedTable}), 1), true)");
}
