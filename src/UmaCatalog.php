<?php

declare(strict_types=1);

final class UmaCatalog
{
    public function get(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && is_file(Config::catalogCache())) {
            $cached = json_decode((string) file_get_contents(Config::catalogCache()), true);
            if (is_array($cached) && isset($cached['characters'])) {
                return $cached;
            }
        }

        try {
            $markdown = HttpClient::getText(Config::ULTIMA_RAW_URL, 30);
            $catalog = $this->parse($markdown);
            file_put_contents(Config::catalogCache(), json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $catalog;
        } catch (Throwable $e) {
            if (is_file(Config::catalogCache())) {
                $cached = json_decode((string) file_get_contents(Config::catalogCache()), true);
                if (is_array($cached)) {
                    $cached['meta']['warning'] = 'Using cached catalog: ' . $e->getMessage();
                    return $cached;
                }
            }
            throw $e;
        }
    }

    public function findCharacter(string $name): ?array
    {
        $target = mb_strtolower(trim($name));
        foreach ($this->get()['characters'] as $character) {
            if (mb_strtolower($character['name']) === $target) {
                return $character;
            }
        }
        return null;
    }

    public function parse(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $section = null;
        $currentName = null;
        $characters = [];
        $common = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '### Common Costumes')) {
                $section = 'common';
                continue;
            }
            if (str_starts_with($trimmed, '### Original Clothes')) {
                $section = 'original';
                continue;
            }
            if (str_starts_with($trimmed, '### ') && $section !== null) {
                $section = null;
                continue;
            }
            if ($section === null || !str_starts_with($trimmed, '|')) {
                continue;
            }
            if (preg_match('/^\|\s*:?-+:?\s*\|/u', $trimmed) || stripos($trimmed, '| Full Name ') !== false || stripos($trimmed, '| costume ') !== false) {
                continue;
            }

            $cells = array_map(static fn (string $cell): string => trim(str_replace('\\n', ' ', $cell)), explode('|', trim($trimmed, '|')));
            if ($section === 'common' && count($cells) >= 2) {
                [$costume, $prompts] = [$cells[0], $cells[1]];
                if ($costume !== '' && $prompts !== '') {
                    $common[] = ['name' => $costume, 'prompt' => $this->normalizeTags($prompts)];
                }
                continue;
            }

            if ($section === 'original' && count($cells) >= 3) {
                [$nameCell, $costume, $prompts] = [$cells[0], $cells[1], $cells[2]];
                if ($nameCell !== '') {
                    $currentName = $nameCell;
                    if (!isset($characters[$currentName])) {
                        $characters[$currentName] = [
                            'name' => $currentName,
                            'feature_tags' => '',
                            'outfits' => [],
                        ];
                    }
                }
                if ($currentName === null || $costume === '' || $prompts === '') {
                    continue;
                }

                if (mb_strtolower($costume) === 'the feature tags') {
                    $characters[$currentName]['feature_tags'] = $this->normalizeTags($prompts);
                } else {
                    $characters[$currentName]['outfits'][] = [
                        'name' => $costume,
                        'prompt' => $this->normalizeTags($prompts),
                    ];
                }
            }
        }

        $characters = array_values(array_filter($characters, static fn (array $character): bool => $character['feature_tags'] !== ''));

        return [
            'meta' => [
                'source' => Config::ULTIMA_RAW_URL,
                'generated_at' => gmdate('c'),
                'character_count' => count($characters),
                'common_costume_count' => count($common),
            ],
            'common_costumes' => $common,
            'characters' => $characters,
        ];
    }

    private function normalizeTags(string $tags): string
    {
        $tags = preg_replace('/\s+/u', ' ', $tags) ?: $tags;
        $tags = str_replace(' ,', ',', $tags);
        $tags = preg_replace('/,+/u', ',', $tags) ?: $tags;
        $tags = preg_replace('/,\s*/u', ', ', $tags) ?: $tags;
        return trim($tags, " \t\n\r\0\x0B|,");
    }
}
