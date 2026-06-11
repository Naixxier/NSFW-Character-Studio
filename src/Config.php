<?php

declare(strict_types=1);

final class Config
{
    public const DATA_DIR = __DIR__ . '/../data';
    public const PACKS_DIR = __DIR__ . '/../packs';
    public const DB_PATH = self::DATA_DIR . '/planner.sqlite';
    public const CATALOG_CACHE = self::DATA_DIR . '/ultima_catalog.json';
    public const ULTIMA_RAW_URL = 'https://huggingface.co/datasets/UmaDiffusion/ULTIMA-prompts/raw/main/README.md';

    public const SD_BASE_URL = 'http://localhost:7860';
    public const LLM_BASE_URL = '';
    public const LLM_MODEL = '';

    public const UMA_BASE_LORA_ALIAS = 'UmaDiffusionXL_4th_weighted';
    public const UMA_BASE_LORA_WEIGHT = 1.0;

    public static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function appEnv(): string
    {
        return self::env('APP_ENV', 'local') ?? 'local';
    }

    public static function dataDir(): string
    {
        return rtrim(self::env('DATA_DIR', self::DATA_DIR) ?? self::DATA_DIR, '/\\');
    }

    public static function packsDir(): string
    {
        return rtrim(self::env('PACKS_DIR', self::PACKS_DIR) ?? self::PACKS_DIR, '/\\');
    }

    public static function dbDriver(): string
    {
        return strtolower(self::env('DB_DRIVER', 'sqlite') ?? 'sqlite');
    }

    public static function sqlitePath(): string
    {
        return self::env('SQLITE_PATH', self::dataDir() . '/planner.sqlite') ?? self::DB_PATH;
    }

    public static function databaseUrl(): string
    {
        return self::env('DATABASE_URL', '') ?? '';
    }

    public static function catalogCache(): string
    {
        return self::dataDir() . '/ultima_catalog.json';
    }

    public static function sdBaseUrl(): string
    {
        return rtrim(self::env('SD_BASE_URL', self::SD_BASE_URL) ?? self::SD_BASE_URL, '/');
    }

    public static function llmBaseUrl(): string
    {
        return rtrim(self::env('LLM_BASE_URL', self::LLM_BASE_URL) ?? self::LLM_BASE_URL, '/');
    }

    public static function llmModel(): string
    {
        return self::env('LLM_MODEL', self::LLM_MODEL) ?? self::LLM_MODEL;
    }

    public static function llmApiKey(): string
    {
        return self::env('LLM_API_KEY', '') ?? '';
    }

    public static function umaBaseLoraAlias(): string
    {
        return self::env('UMA_BASE_LORA_ALIAS', self::UMA_BASE_LORA_ALIAS) ?? self::UMA_BASE_LORA_ALIAS;
    }
}
