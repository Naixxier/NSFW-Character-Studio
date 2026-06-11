<?php

declare(strict_types=1);

final class NsfwDirectorAuditor
{
    public function __construct(private Store $store, private UmaCatalog $catalog, private PromptComposer $composer)
    {
    }

    public function audit(): array
    {
        $library = $this->store->getPromptLibrary();
        $director = $library['nsfw_director'] ?? [];
        $acts = is_array($director['acts'] ?? null) ? $director['acts'] : [];
        $issues = [];

        $focuses = array_keys(is_array($director['focuses'] ?? null) ? $director['focuses'] : []);
        $cameras = array_keys(is_array($director['cameras'] ?? null) ? $director['cameras'] : []);
        $sceneIntents = array_keys(is_array($director['scene_intents'] ?? null) ? $director['scene_intents'] : []);
        $effects = is_array($director['effects'] ?? null) ? $director['effects'] : [];
        $required = ['positive_tags', 'hard_tags', 'negative_disambiguation', 'conflict_negatives', 'act_group', 'scene_intent', 'anatomy_tags', 'strict_tags'];
        $character = $this->catalog->get(false)['characters'][0]['name'] ?? '';

        foreach ($acts as $key => $act) {
            if (!is_array($act)) {
                $issues[] = $this->issue('act.invalid', (string) $key, 'Act must be an object.');
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string) $key)) {
                $issues[] = $this->issue('act.key_format', (string) $key, 'Act key contains invalid characters.');
            }
            if (($act['key'] ?? $key) !== $key) {
                $issues[] = $this->issue('act.key_mismatch', (string) $key, 'Act key field does not match map key.');
            }
            foreach ($required as $field) {
                if (trim((string) ($act[$field] ?? '')) === '') {
                    $issues[] = $this->issue('act.missing_field', (string) $key, "Missing {$field}.");
                }
            }
            if (($act['recommended_focus'] ?? '') !== '' && !in_array((string) $act['recommended_focus'], $focuses, true)) {
                $issues[] = $this->issue('act.invalid_focus', (string) $key, 'Recommended focus does not exist.');
            }
            if (($act['recommended_camera'] ?? '') !== '' && !in_array((string) $act['recommended_camera'], $cameras, true)) {
                $issues[] = $this->issue('act.invalid_camera', (string) $key, 'Recommended camera does not exist.');
            }
            if (($act['scene_intent'] ?? '') !== '' && !in_array((string) $act['scene_intent'], $sceneIntents, true)) {
                $issues[] = $this->issue('act.invalid_scene_intent', (string) $key, 'Scene intent does not exist.');
            }

            if ($character !== '') {
                $composed = $this->composer->compose([
                    'uma' => $character,
                    'outfit' => '',
                    'mode' => 'hard_nsfw',
                    'nsfw_enabled' => true,
                    'nsfw_strict' => true,
                    'nsfw_intensity' => 'hard',
                    'nsfw_act' => (string) $key,
                    'negative_preset' => 'audit negative',
                ]);
                $directorPositive = implode(', ', array_filter([
                    $composed['layers']['mode_tags'] ?? '',
                    $composed['layers']['nsfw_intensity'] ?? '',
                    $composed['layers']['nsfw_act'] ?? '',
                    $composed['layers']['nsfw_scene_intent'] ?? '',
                    $composed['layers']['nsfw_focus'] ?? '',
                    $composed['layers']['nsfw_expression'] ?? '',
                    $composed['layers']['nsfw_clothing_state'] ?? '',
                    $composed['layers']['nsfw_camera'] ?? '',
                    $composed['layers']['nsfw_anatomy_lock'] ?? '',
                    $composed['layers']['nsfw_strict_act'] ?? '',
                ]));
                foreach ($this->tagList((string) ($act['conflict_negatives'] ?? '')) as $conflict) {
                    if (!$this->containsTagish($composed['negative_prompt'], $conflict)) {
                        $issues[] = $this->issue('compose.missing_conflict_negative', (string) $key, "Conflict negative not present: {$conflict}");
                    }
                    if ($this->containsTagish($directorPositive, $conflict)) {
                        $issues[] = $this->issue('compose.conflict_in_positive', (string) $key, "Conflict negative appears in positive prompt: {$conflict}");
                    }
                }
                if (!$this->allowsCowgirlBias($act)) {
                    foreach ($this->tagList($this->antiCowgirlBiasNegatives()) as $guard) {
                        if (!$this->containsTagish($composed['negative_prompt'], $guard)) {
                            $issues[] = $this->issue('compose.missing_anti_cowgirl_guard', (string) $key, "Anti-cowgirl guard not present: {$guard}");
                        }
                    }
                }
            }
        }

        foreach ($effects as $key => $effect) {
            if (!is_array($effect)) {
                $issues[] = $this->issue('effect.invalid', (string) $key, 'Effect must be an object.');
                continue;
            }
            foreach (['tags', 'negative_tags', 'compatible_act_groups', 'incompatible_acts', 'requires_scene_intent'] as $field) {
                if (!array_key_exists($field, $effect)) {
                    $issues[] = $this->issue('effect.missing_field', (string) $key, "Missing {$field}.");
                }
            }
            foreach ($this->tagList((string) ($effect['incompatible_acts'] ?? '')) as $actKey) {
                if (!isset($acts[$actKey])) {
                    $issues[] = $this->issue('effect.invalid_incompatible_act', (string) $key, "Unknown incompatible act: {$actKey}");
                }
            }
            foreach ($this->tagList((string) ($effect['requires_scene_intent'] ?? '')) as $sceneKey) {
                if (!in_array($sceneKey, $sceneIntents, true)) {
                    $issues[] = $this->issue('effect.invalid_scene_intent', (string) $key, "Unknown scene intent: {$sceneKey}");
                }
            }
        }

        $counts = [
            'acts' => count($acts),
            'expressions' => count($director['expressions'] ?? []),
            'clothing_states' => count($director['clothing_states'] ?? []),
            'scene_intents' => count($director['scene_intents'] ?? []),
            'effects' => count($effects),
        ];

        return [
            'ok' => count($issues) === 0,
            'summary' => count($issues) === 0 ? 'NSFW Director audit passed.' : 'NSFW Director audit found ' . count($issues) . ' issue(s).',
            'issues' => $issues,
            'counts' => $counts,
        ];
    }

    private function issue(string $code, string $key, string $message): array
    {
        return ['code' => $code, 'key' => $key, 'message' => $message];
    }

    private function tagList(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags)), static fn (string $tag): bool => $tag !== ''));
    }

    private function containsTagish(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }
        return preg_match('/(^|[,()\\s])' . preg_quote($needle, '/') . '([,()\\s]|$)/i', $haystack) === 1;
    }

    private function allowsCowgirlBias(array $act): bool
    {
        $key = mb_strtolower((string) ($act['key'] ?? ''));
        $positive = mb_strtolower(implode(', ', [
            (string) ($act['positive_tags'] ?? ''),
            (string) ($act['hard_tags'] ?? ''),
            (string) ($act['strict_tags'] ?? ''),
        ]));
        if (in_array($key, ['cowgirl_position', 'reverse_cowgirl', 'straddling_tease', 'sitting_sex', 'lap_sitting'], true)) {
            return true;
        }
        foreach (['woman on top', 'reverse cowgirl', 'straddling partner', 'straddling lap', 'riding position'] as $allowedCue) {
            if (str_contains($positive, $allowedCue)) {
                return true;
            }
        }
        return false;
    }

    private function antiCowgirlBiasNegatives(): string
    {
        return 'cowgirl position, reverse cowgirl, woman on top, riding position, straddling partner, straddling sex, hips over partner, western cowgirl, cowgirl outfit, horse riding';
    }
}
