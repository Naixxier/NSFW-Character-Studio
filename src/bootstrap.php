<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$envPath = $root . '/.env';
if (is_file($envPath) && is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $trimmed, 2));
        $value = trim($value, "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

spl_autoload_register(static function (string $class) use ($root): void {
    $path = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
