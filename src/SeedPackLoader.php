<?php

declare(strict_types=1);

final class SeedPackLoader
{
    public function __construct(private string $root)
    {
    }

    public function load(): array
    {
        $pack = $this->emptyPack();
        foreach (['public', 'private'] as $scope) {
            $dir = $this->root . DIRECTORY_SEPARATOR . $scope;
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $decoded = $this->readPack($file);
                $pack = $this->mergePack($pack, $decoded);
            }
        }
        return $pack;
    }

    public static function fingerprint(array $pack): string
    {
        return hash('sha256', json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function readPack(string $file): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Unable to read seed pack: ' . basename($file));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid seed pack JSON: ' . basename($file));
        }
        return $decoded;
    }

    private function emptyPack(): array
    {
        return [
            'series' => [],
            'characters' => [],
            'lora_library' => [],
            'lora_variants' => [],
            'presets' => [],
            'prompt_library' => [],
        ];
    }

    private function mergePack(array $base, array $next): array
    {
        foreach (['series', 'characters', 'lora_library', 'lora_variants', 'presets'] as $key) {
            if (isset($next[$key]) && is_array($next[$key])) {
                $base[$key] = array_merge($base[$key] ?? [], array_values($next[$key]));
            }
        }
        if (isset($next['prompt_library']) && is_array($next['prompt_library'])) {
            $base['prompt_library'] = array_replace_recursive($base['prompt_library'] ?? [], $next['prompt_library']);
        }
        return $base;
    }
}
