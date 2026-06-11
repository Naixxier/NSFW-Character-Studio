<?php

declare(strict_types=1);

final class PromptComposer
{
    public function __construct(private UmaCatalog $catalog, private ?Store $store = null)
    {
    }

    public function compose(array $input): array
    {
        $character = $this->resolveCharacter($input);
        if ($character === null) {
            throw new InvalidArgumentException('Character not found.');
        }

        $outfitName = trim((string) ($input['outfit'] ?? ''));
        $outfit = $this->findByName($character['outfits'], $outfitName);
        if ($outfitName !== '' && $outfit === null) {
            throw new InvalidArgumentException('Outfit not found for selected character.');
        }
        $appearance = $this->resolveAppearance($character, (string) ($input['character_appearance'] ?? $input['appearance'] ?? ''));
        $secondary = $this->resolveSecondaryCharacters($input, (int) ($character['id'] ?? 0));

        $commonPrompts = [];
        $catalog = $this->catalog->get();
        foreach (($input['common_costumes'] ?? []) as $commonName) {
            $common = $this->findByName($catalog['common_costumes'], (string) $commonName);
            if ($common !== null) {
                $commonPrompts[] = $common['prompt'];
            }
        }

        $baseLora = $this->baseLoraFor($character);
        $globalPrompt = trim((string) ($input['global_prompt'] ?? ''));
        $styleTags = trim((string) ($input['style_tags'] ?? ''));
        $manualPrompt = trim((string) ($input['manual_prompt'] ?? ''));
        $llmPolish = trim((string) ($input['llm_polish'] ?? ''));
        $loras = is_array($input['loras'] ?? null) ? $input['loras'] : [];
        $mode = (string) ($input['mode'] ?? 'standard');
        $modeConfig = $this->modeConfig($mode);
        $quickTagResolution = $this->resolveSemanticQuickTags($input);
        $input = $quickTagResolution['input'];
        $modeTags = (string) ($modeConfig['tags'] ?? '');
        $modeNegativeTags = (string) ($modeConfig['negative_tags'] ?? '');
        $nsfw = $this->nsfwDirectorLayers($input, $modeConfig);
        if (!empty($nsfw['selection']['act']) && in_array((string) ($modeConfig['key'] ?? ''), ['soft_nsfw', 'hard_nsfw'], true)) {
            $modeTags = $this->focusedNsfwModeTags((string) $modeConfig['key'], (string) ($nsfw['selection']['intensity'] ?? ''));
        }
        $pose = $this->poseLayer($input, $nsfw['selection'] ?? []);
        $variantCameraLock = $this->loraVariantCameraLock($loras);
        $cameraFilteredGlobal = $this->filterVariantCameraLockText($globalPrompt, $variantCameraLock);
        $globalPrompt = $cameraFilteredGlobal['text'];
        $cameraFilteredStyle = $this->filterVariantCameraLockText($styleTags, $variantCameraLock);
        $styleTags = $cameraFilteredStyle['text'];
        $cameraFilteredManual = $this->filterVariantCameraLockText($manualPrompt, $variantCameraLock);
        $manualPrompt = $cameraFilteredManual['text'];
        $variantCameraRemoved = $this->joinSegments([
            $cameraFilteredGlobal['removed'] !== '' ? 'global: ' . $cameraFilteredGlobal['removed'] : '',
            $cameraFilteredStyle['removed'] !== '' ? 'style: ' . $cameraFilteredStyle['removed'] : '',
            $cameraFilteredManual['removed'] !== '' ? 'manual: ' . $cameraFilteredManual['removed'] : '',
        ]);
        $styleTags = $this->filterStrictStyleTags($styleTags, $nsfw['selection'] ?? []);
        $composition = $this->compositionDirective($styleTags);
        $globalPrompt = $this->filterCompositionConflicts($globalPrompt, $composition['key']);
        $styleTags = $this->expandCompositionStyleTags($styleTags, $composition['positive_tags']);
        $quickTags = $this->formatTags($quickTagResolution['visual_tags']);
        $customTags = $this->formatTags($input['custom_tags'] ?? '');
        $rawOutfitTags = (string) ($outfit['prompt'] ?? '');
        $clothingOverrideKey = $this->clothingOverrideKey($nsfw['selection']['clothing_state'] ?? '', $input);
        $clothingOverride = $this->clothingOverride($clothingOverrideKey, $rawOutfitTags);
        $loraClothing = $this->normalizeLoraVariantClothing($loras, [
            'has_outfit' => $rawOutfitTags !== '',
            'clothing_override_key' => $clothingOverrideKey,
            'active_clothing_lora_count' => $this->activeClothingLoraCount($loras),
        ]);
        $input['loras'] = $loraClothing['loras'];
        $characterTemplates = $this->loraCharacterTemplates($character, $appearance, $clothingOverride['outfit_tags'], $secondary);
        $extraLoras = $this->formatLoras($input['loras'], $characterTemplates);
        $loraConflictNegatives = $this->formatLoraConflictNegatives($input['loras']);
        $loraVariantLayer = $this->formatLoraVariantLayer($input['loras'], $characterTemplates);
        $loraVariantCharacterTemplates = $this->formatLoraVariantCharacterTemplateLayer($input['loras'], $characterTemplates);
        $loraVariantNegatives = $this->joinSegments([
            $this->formatLoraVariantNegatives($input['loras']),
            $loraClothing['negative_tags'],
        ]);
        $loraDirectorGuard = $this->loraDirectorGuardLayers($input, $nsfw['selection'] ?? []);
        $ensemble = $this->ensembleLayers($input['loras'], !array_key_exists('anonymous_partner', $input) || (bool) $input['anonymous_partner']);

        $locked = [
            'full_name' => $character['full_name'] ?? $character['name'],
            'adult_framing_tags' => (string) ($character['adult_framing_tags'] ?? ''),
            'feature_tags' => $character['feature_tags'],
            'appearance_name' => (string) ($appearance['name'] ?? ''),
            'appearance_tags' => (string) ($appearance['prompt'] ?? ''),
            'outfit_name' => $outfit['name'] ?? '',
            'outfit_tags' => $clothingOverride['outfit_tags'],
            'common_costume_tags' => $commonPrompts,
        ];

        $layers = [
            'base_lora' => $baseLora,
            'secondary_base_loras' => $secondary['base_loras'],
            'global' => $globalPrompt,
            'full_name' => $locked['full_name'],
            'adult_framing' => $locked['adult_framing_tags'],
            'feature_tags' => $locked['feature_tags'],
            'appearance' => $locked['appearance_tags'],
            'outfit' => $locked['outfit_tags'],
            'secondary_characters' => $secondary['characters'],
            'secondary_appearances' => $secondary['appearances'],
            'secondary_outfits' => $secondary['outfits'],
            'ensemble_tags' => $ensemble['tags'],
            'outfit_filtered' => $clothingOverride['active'] ? $clothingOverride['outfit_tags'] : '',
            'clothing_override' => $clothingOverride['positive_tags'],
            'clothing_override_negative' => $clothingOverride['negative_tags'],
            'clothing_override_removed' => $clothingOverride['removed_tags'],
            'common_costumes' => implode(', ', $commonPrompts),
            'mode_tags' => $modeTags,
            'pose' => $pose['tags'],
            'pose_blocked' => $pose['blocked'],
            'nsfw_intensity' => $nsfw['layers']['nsfw_intensity'],
            'nsfw_act' => $nsfw['layers']['nsfw_act'],
            'nsfw_scene_intent' => $nsfw['layers']['nsfw_scene_intent'],
            'nsfw_focus' => $nsfw['layers']['nsfw_focus'],
            'nsfw_contact_lock' => $nsfw['layers']['nsfw_contact_lock'],
            'nsfw_contact_lock_negative' => $nsfw['layers']['nsfw_contact_lock_negative'],
            'nsfw_expression' => $nsfw['layers']['nsfw_expression'],
            'nsfw_clothing_state' => $nsfw['layers']['nsfw_clothing_state'],
            'nsfw_camera' => $nsfw['layers']['nsfw_camera'],
            'nsfw_anatomy_lock' => $nsfw['layers']['nsfw_anatomy_lock'],
            'nsfw_strict_act' => $nsfw['layers']['nsfw_strict_act'],
            'nsfw_effects' => $nsfw['layers']['nsfw_effects'],
            'nsfw_effects_blocked' => $nsfw['layers']['nsfw_effects_blocked'],
            'nsfw_effects_warning' => $nsfw['layers']['nsfw_effects_warning'],
            'nsfw_boost' => $nsfw['layers']['nsfw_boost'],
            'quick_tags' => $quickTags,
            'quick_tags_visual' => $quickTags,
            'quick_tags_semantic_applied' => $this->joinSegments($quickTagResolution['applied']),
            'quick_tags_semantic_ignored' => $this->joinSegments($quickTagResolution['ignored']),
            'custom_tags' => $customTags,
            'lora_variant_camera_lock' => $variantCameraLock['positive_tags'],
            'lora_variant_camera_removed' => $variantCameraRemoved,
            'lora_variant_camera_negative' => $variantCameraLock['negative_tags'],
            'extra_loras' => $extraLoras,
            'lora_conflict_negatives' => $loraConflictNegatives,
            'lora_variants' => $loraVariantLayer,
            'lora_variant_character_templates' => $loraVariantCharacterTemplates,
            'lora_variant_negatives' => $loraVariantNegatives,
            'lora_variant_clothing_policy' => $loraClothing['policy_layer'],
            'lora_variant_clothing_removed' => $loraClothing['removed_tags'],
            'lora_variant_clothing_warning' => $loraClothing['warnings'],
            'lora_director_guard' => $loraDirectorGuard['lora_director_guard'],
            'lora_director_warnings' => $loraDirectorGuard['lora_director_warnings'],
            'lora_director_blocked' => $loraDirectorGuard['lora_director_blocked'],
            'style_tags' => $styleTags,
            'composition_negative_guard' => $composition['negative_tags'],
            'manual' => $manualPrompt,
            'llm_polish' => $llmPolish,
        ];

        $layers = $this->filterPositiveLayersForVariantCameraLock($layers, $variantCameraLock);
        $positiveLayers = $layers;
        if ($loraVariantCharacterTemplates !== '') {
            unset(
                $positiveLayers['full_name'],
                $positiveLayers['adult_framing'],
                $positiveLayers['feature_tags'],
                $positiveLayers['appearance'],
                $positiveLayers['outfit'],
                $positiveLayers['secondary_characters'],
                $positiveLayers['secondary_appearances'],
                $positiveLayers['secondary_outfits'],
                $positiveLayers['ensemble_tags']
            );
        }
        unset($positiveLayers['lora_conflict_negatives'], $positiveLayers['lora_variants'], $positiveLayers['lora_variant_character_templates'], $positiveLayers['lora_variant_negatives'], $positiveLayers['lora_variant_clothing_policy'], $positiveLayers['lora_variant_clothing_removed'], $positiveLayers['lora_variant_clothing_warning'], $positiveLayers['lora_variant_camera_removed'], $positiveLayers['lora_variant_camera_negative'], $positiveLayers['lora_director_guard'], $positiveLayers['lora_director_warnings'], $positiveLayers['lora_director_blocked'], $positiveLayers['pose_blocked'], $positiveLayers['nsfw_contact_lock_negative'], $positiveLayers['nsfw_effects_blocked'], $positiveLayers['nsfw_effects_warning'], $positiveLayers['composition_negative_guard'], $positiveLayers['clothing_override_negative'], $positiveLayers['clothing_override_removed'], $positiveLayers['outfit_filtered'], $positiveLayers['quick_tags_visual'], $positiveLayers['quick_tags_semantic_applied'], $positiveLayers['quick_tags_semantic_ignored']);
        $segments = array_filter($positiveLayers, static fn (string $segment): bool => trim($segment) !== '');

        $negative = $this->joinSegments([
            (string) ($input['negative_preset'] ?? ''),
            $modeNegativeTags,
            $pose['negative_tags'],
            $composition['negative_tags'],
            $nsfw['negative_tags'],
            $nsfw['effect_negative_tags'] ?? '',
            $clothingOverride['negative_tags'],
            $loraConflictNegatives,
            $loraVariantNegatives,
            $variantCameraLock['negative_tags'],
            (string) ($input['negative_prompt'] ?? ''),
        ]);

        return [
            'prompt' => $this->normalizeFinalPrompt($segments, true),
            'negative_prompt' => $this->normalizeFinalPrompt([$negative], false),
            'locked' => $locked,
            'layers' => $layers,
            'base_lora' => $baseLora,
            'extra_loras' => $extraLoras,
            'mode' => $modeConfig['key'],
            'nsfw_director' => $nsfw['selection'],
            'pose' => $pose['selection'],
            'character' => [
                'id' => (int) ($character['id'] ?? 0),
                'name' => (string) ($character['name'] ?? ''),
                'series_id' => (int) ($character['series_id'] ?? 0),
                'series_name' => (string) ($character['series_name'] ?? ''),
                'source_type' => (string) ($character['source_type'] ?? 'ultima'),
            ],
            'secondary_characters' => $secondary['items'],
        ];
    }

    public function modes(): array
    {
        return [
            'modes' => $this->modeDefinitions(),
            'quick_tags' => $this->quickTags(),
            'quick_tag_map' => $this->quickTagMap(),
            'pose_library' => $this->poseLibrary(),
            'nsfw_director' => $this->nsfwDirector(),
        ];
    }

    public function polish(array $input): array
    {
        $settings = $this->store?->getSettings() ?? [];
        $payload = $this->llmPayload($input, $settings);

        $apiBase = rtrim((string) ($settings['llm']['api_base'] ?? Config::llmBaseUrl()), '/');
        $apiKey = trim((string) ($settings['llm']['api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = Config::llmApiKey();
        }
        $headers = $apiKey !== '' ? ['Authorization: Bearer ' . $apiKey] : [];
        $response = HttpClient::postJson($apiBase . '/chat/completions', $payload, 60, $headers);

        $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        $composed = $this->compose($input);
        $content = $this->sanitizePolish($content, $composed['locked']);
        $content = $this->filterPolishAgainstNsfwConflicts($content, $input);
        $input['llm_polish'] = $content;

        return [
            'polish' => $content,
            'payload' => $payload,
            'composed' => $this->compose($input),
        ];
    }

    public function llmPayload(array $input, ?array $settings = null): array
    {
        $settings ??= $this->store?->getSettings() ?? [];
        $composed = $this->compose($input);
        $lockedBlock = implode("\n", array_filter([
            'FULL NAME: ' . $composed['locked']['full_name'],
            $composed['locked']['adult_framing_tags'] !== '' ? 'ADULT FRAMING: ' . $composed['locked']['adult_framing_tags'] : '',
            'FEATURE TAGS: ' . $composed['locked']['feature_tags'],
            $composed['locked']['appearance_tags'] !== '' ? 'APPEARANCE TAGS: ' . $composed['locked']['appearance_tags'] : '',
            $composed['locked']['outfit_name'] !== '' ? 'OUTFIT TAGS: ' . $composed['locked']['outfit_tags'] : '',
        ]));

        $systemTemplate = (string) ($settings['prompt_debug']['system_template'] ?? 'You are a Stable Diffusion prompt editor.');
        $userTemplate = (string) ($settings['prompt_debug']['user_template'] ?? '{{prompt}}');
        $replacements = [
            '{{locked_tags}}' => $lockedBlock,
            '{{prompt}}' => $composed['prompt'],
            '{{style_tags}}' => (string) ($input['style_tags'] ?? ''),
            '{{negative_prompt}}' => $composed['negative_prompt'],
        ];

        return [
            'model' => (string) ($settings['llm']['model'] ?? Config::llmModel()),
            'messages' => [
                ['role' => 'system', 'content' => strtr($systemTemplate, $replacements)],
                ['role' => 'user', 'content' => strtr($userTemplate, $replacements)],
            ],
            'temperature' => (float) ($settings['llm']['temperature'] ?? 0.45),
            'max_tokens' => (int) ($settings['llm']['max_tokens'] ?? 220),
        ];
    }

    private function sanitizePolish(string $content, array $locked): string
    {
        $content = preg_replace('/```.*?```/s', '', $content) ?: $content;
        $content = str_replace(["\r", "\n", ';'], ', ', $content);
        foreach ([$locked['full_name'], $locked['feature_tags'], $locked['appearance_tags'] ?? '', $locked['outfit_tags']] as $blocked) {
            if ($blocked !== '') {
                $content = str_replace($blocked, '', $content);
            }
        }
        $content = preg_replace('/\s*,\s*/u', ', ', $content) ?: $content;
        $content = preg_replace('/,+/u', ',', $content) ?: $content;
        $content = trim($content, " \t\n\r\0\x0B,.");
        $tags = array_filter(array_map('trim', explode(',', $content)), static fn (string $tag): bool => $tag !== '');
        $seen = [];
        $clean = [];
        foreach ($tags as $tag) {
            $key = mb_strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = $tag;
            if (count($clean) >= 48) {
                break;
            }
        }
        return implode(', ', $clean);
    }

    private function resolveCharacter(array $input): ?array
    {
        $characterId = (int) ($input['character_id'] ?? 0);
        if ($characterId > 0 && $this->store) {
            $character = $this->store->getCharacter($characterId);
            if ($character !== null) {
                return $character;
            }
        }

        $name = (string) ($input['character_name'] ?? $input['uma'] ?? '');
        if ($this->store && trim($name) !== '') {
            foreach ($this->store->getCharacters(['q' => $name]) as $candidate) {
                if (mb_strtolower((string) $candidate['name']) === mb_strtolower(trim($name))) {
                    return $candidate;
                }
            }
        }

        $uma = $this->catalog->findCharacter((string) ($input['uma'] ?? $name));
        if ($uma === null) {
            return null;
        }
        $uma['full_name'] = $uma['name'];
        $uma['adult_framing_tags'] = '';
        $uma['base_lora_alias'] = Config::umaBaseLoraAlias();
        $uma['base_lora_weight'] = Config::UMA_BASE_LORA_WEIGHT;
        $uma['series_name'] = 'Umamusume';
        $uma['series_id'] = 0;
        $uma['source_type'] = 'ultima';
        return $uma;
    }

    private function baseLoraFor(array $character): string
    {
        $alias = trim((string) ($character['base_lora_alias'] ?? ''));
        if ($alias === '') {
            return '';
        }
        $weight = (float) ($character['base_lora_weight'] ?? 1);
        return sprintf('<lora:%s:%s>', $alias, $this->formatWeight($weight));
    }

    private function resolveSecondaryCharacters(array $input, int $primaryCharacterId): array
    {
        $items = [];
        $baseLoras = [];
        $characterSegments = [];
        $appearanceSegments = [];
        $outfitSegments = [];
        $seen = $primaryCharacterId > 0 ? [$primaryCharacterId => true] : [];
        $slots = is_array($input['secondary_characters'] ?? null) ? array_slice($input['secondary_characters'], 0, 3) : [];

        foreach ($slots as $slot) {
            if (!is_array($slot) || !$this->store) {
                continue;
            }
            $characterId = (int) ($slot['character_id'] ?? 0);
            if ($characterId <= 0 || isset($seen[$characterId])) {
                continue;
            }
            $character = $this->store->getCharacter($characterId);
            if (!$character) {
                continue;
            }
            $seen[$characterId] = true;
            $appearance = $this->resolveAppearance($character, (string) ($slot['appearance'] ?? $slot['character_appearance'] ?? ''));
            $outfitName = trim((string) ($slot['outfit'] ?? ''));
            $outfit = $outfitName === '' ? null : $this->findByName(is_array($character['outfits'] ?? null) ? $character['outfits'] : [], $outfitName);
            $includeBaseLora = !array_key_exists('include_base_lora', $slot) || (bool) $slot['include_base_lora'];
            $baseLora = $includeBaseLora ? $this->baseLoraFor($character) : '';

            if ($baseLora !== '') {
                $baseLoras[] = $baseLora;
            }
            $characterTags = $this->joinSegments([
                (string) ($character['full_name'] ?? $character['name'] ?? ''),
                (string) ($character['adult_framing_tags'] ?? ''),
                (string) ($character['feature_tags'] ?? ''),
            ]);
            if ($characterTags !== '') {
                $characterSegments[] = $characterTags;
            }
            if (!empty($appearance['prompt'])) {
                $appearanceSegments[] = (string) $appearance['prompt'];
            }
            if ($outfit !== null && trim((string) ($outfit['prompt'] ?? '')) !== '') {
                $outfitSegments[] = (string) $outfit['prompt'];
            }

            $items[] = [
                'id' => $characterId,
                'name' => (string) ($character['name'] ?? ''),
                'series_id' => (int) ($character['series_id'] ?? 0),
                'series_name' => (string) ($character['series_name'] ?? ''),
                'appearance' => (string) ($appearance['name'] ?? ''),
                'outfit' => (string) ($outfit['name'] ?? ''),
                'include_base_lora' => $includeBaseLora,
                'base_lora' => $baseLora,
                'character_tags' => $characterTags,
                'appearance_tags' => (string) ($appearance['prompt'] ?? ''),
                'outfit_tags' => (string) ($outfit['prompt'] ?? ''),
            ];
        }

        return [
            'items' => $items,
            'base_loras' => $this->joinSegments($baseLoras),
            'characters' => $this->joinSegments($characterSegments),
            'appearances' => $this->joinSegments($appearanceSegments),
            'outfits' => $this->joinSegments($outfitSegments),
        ];
    }

    private function ensembleLayers(array $loras, bool $anonymousPartner): array
    {
        $ensembleTags = [];
        $anonymousTags = [];
        foreach ($loras as $lora) {
            if (!is_array($lora) || (array_key_exists('enabled', $lora) && !$lora['enabled'])) {
                continue;
            }
            $ensembleTags[] = (string) ($lora['ensemble_tags'] ?? '');
            $ensembleTags[] = (string) ($lora['variant_ensemble_tags'] ?? '');
            if ($anonymousPartner) {
                $anonymousTags[] = (string) ($lora['anonymous_partner_tags'] ?? '');
                $anonymousTags[] = (string) ($lora['variant_anonymous_partner_tags'] ?? '');
            }
        }
        return [
            'tags' => $this->joinSegments([$this->joinSegments($ensembleTags), $this->joinSegments($anonymousTags)]),
        ];
    }

    private function resolveAppearance(array $character, string $appearanceName): ?array
    {
        $appearances = is_array($character['appearances'] ?? null) ? $character['appearances'] : [];
        if (!$appearances) {
            return null;
        }
        $appearanceName = trim($appearanceName);
        if ($appearanceName !== '') {
            $found = $this->findByName($appearances, $appearanceName);
            if ($found !== null) {
                return $found;
            }
        }
        return $appearances[0] ?? null;
    }

    private function loraCharacterTemplates(array $character, ?array $appearance, string $outfitTags, array $secondary): array
    {
        $templates = [
            '{{character_1}}' => $this->joinSegments([
                (string) ($character['full_name'] ?? $character['name'] ?? ''),
                (string) ($character['adult_framing_tags'] ?? ''),
                (string) ($character['feature_tags'] ?? ''),
                (string) ($appearance['prompt'] ?? ''),
                $outfitTags,
            ]),
        ];

        foreach (array_values($secondary['items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $templates['{{character_' . ($index + 2) . '}}'] = $this->joinSegments([
                (string) ($item['character_tags'] ?? ''),
                (string) ($item['appearance_tags'] ?? ''),
                (string) ($item['outfit_tags'] ?? ''),
            ]);
        }

        return $templates;
    }

    private function applyLoraCharacterTemplates(string $text, array $templates): string
    {
        if ($text === '' || !str_contains($text, '{{character_')) {
            return $text;
        }

        $resolved = strtr($text, array_filter($templates, static fn (string $value): bool => trim($value) !== ''));
        $resolved = preg_replace('/\{\{character_\d+\}\}/u', '', $resolved) ?: $resolved;
        return trim($resolved, " \t\n\r\0\x0B,");
    }

    private function unresolvedLoraCharacterTemplates(string $text, array $templates): array
    {
        if ($text === '' || !preg_match_all('/\{\{character_\d+\}\}/u', $text, $matches)) {
            return [];
        }

        return array_values(array_unique(array_filter($matches[0], static fn (string $token): bool => trim((string) ($templates[$token] ?? '')) === '')));
    }

    private function formatLoras(array $loras, array $characterTemplates = []): string
    {
        $parts = [];
        foreach ($loras as $lora) {
            if (!is_array($lora) || isset($lora['enabled']) && !$lora['enabled']) {
                continue;
            }
            $alias = trim((string) ($lora['alias'] ?? $lora['name'] ?? ''));
            if ($alias === '' || $alias === Config::umaBaseLoraAlias()) {
                continue;
            }
            $weight = (float) ($lora['weight'] ?? 0.8);
            $triggerWords = trim((string) (($lora['trigger_words'] ?? '') !== '' ? $lora['trigger_words'] : ($lora['saved_trigger_words'] ?? '')));
            $variantTriggerWords = trim((string) ($lora['variant_trigger_words'] ?? ''));
            $variantTags = trim((string) ($lora['variant_tags_effective'] ?? $lora['variant_tags'] ?? $lora['variant_positive_tags'] ?? ''));
            $variantTriggerWords = $this->applyLoraCharacterTemplates($variantTriggerWords, $characterTemplates);
            $variantTags = $this->applyLoraCharacterTemplates($variantTags, $characterTemplates);
            $parts[] = $this->joinSegments([
                sprintf('<lora:%s:%s>', $alias, $this->formatWeight($weight)),
                $triggerWords,
                $variantTriggerWords,
                $variantTags,
            ]);
        }
        return implode(', ', array_filter($parts));
    }

    private function formatLoraVariantLayer(array $loras, array $characterTemplates = []): string
    {
        $parts = [];
        foreach ($loras as $lora) {
            if (!is_array($lora) || isset($lora['enabled']) && !$lora['enabled']) {
                continue;
            }
            $variantKey = trim((string) ($lora['variant_key'] ?? ''));
            if ($variantKey === '') {
                continue;
            }
            $label = trim((string) ($lora['variant_label'] ?? $variantKey));
            $parts[] = $this->joinSegments([
                trim((string) ($lora['alias'] ?? $lora['name'] ?? '')) . ':' . $label,
                $this->applyLoraCharacterTemplates(trim((string) ($lora['variant_trigger_words'] ?? '')), $characterTemplates),
                $this->applyLoraCharacterTemplates(trim((string) ($lora['variant_tags_effective'] ?? $lora['variant_tags'] ?? $lora['variant_positive_tags'] ?? '')), $characterTemplates),
            ]);
        }
        return $this->joinSegments($parts);
    }

    private function formatLoraVariantCharacterTemplateLayer(array $loras, array $characterTemplates = []): string
    {
        $parts = [];
        foreach ($loras as $lora) {
            if (!is_array($lora) || isset($lora['enabled']) && !$lora['enabled']) {
                continue;
            }
            $text = $this->joinSegments([
                trim((string) ($lora['variant_trigger_words'] ?? '')),
                trim((string) ($lora['variant_tags_effective'] ?? $lora['variant_tags'] ?? $lora['variant_positive_tags'] ?? '')),
            ]);
            if (!str_contains($text, '{{character_')) {
                continue;
            }
            $label = trim((string) ($lora['alias'] ?? $lora['name'] ?? ''));
            $variant = trim((string) ($lora['variant_label'] ?? $lora['variant_key'] ?? ''));
            if ($variant !== '') {
                $label .= '/' . $variant;
            }
            $unresolved = $this->unresolvedLoraCharacterTemplates($text, $characterTemplates);
            $parts[] = $label . ': ' . (empty($unresolved) ? 'resolved' : 'missing ' . implode(', ', $unresolved));
        }
        return $this->joinSegments($parts);
    }

    private function normalizeLoraVariantClothing(array $loras, array $context): array
    {
        $classifier = new ClothingTagClassifier();
        $normalized = [];
        $removed = [];
        $warnings = [];
        $policies = [];
        $negative = [];
        $activeClothingLoraCount = (int) ($context['active_clothing_lora_count'] ?? 0);

        foreach ($loras as $lora) {
            if (!is_array($lora)) {
                $normalized[] = $lora;
                continue;
            }
            if (isset($lora['enabled']) && !$lora['enabled']) {
                $normalized[] = $lora;
                continue;
            }

            $variantKey = trim((string) ($lora['variant_key'] ?? ''));
            $variantTags = trim((string) ($lora['variant_tags'] ?? $lora['variant_positive_tags'] ?? ''));
            $policy = $this->variantClothingPolicy((string) ($lora['variant_clothing_policy'] ?? $lora['clothing_policy'] ?? 'incidental'));
            $explicitClothingTags = trim((string) ($lora['variant_clothing_tags'] ?? $lora['clothing_tags'] ?? ''));
            $requiredClothingTags = trim((string) ($lora['variant_clothing_required_tags'] ?? $lora['clothing_required_tags'] ?? ''));
            $stripWhenActive = !array_key_exists('variant_strip_clothing_when_outfit_active', $lora)
                ? (!array_key_exists('strip_clothing_when_outfit_active', $lora) || !empty($lora['strip_clothing_when_outfit_active']))
                : !empty($lora['variant_strip_clothing_when_outfit_active']);
            $detected = $explicitClothingTags !== '' ? $explicitClothingTags : $this->joinSegments($classifier->detect($variantTags));
            $category = mb_strtolower(trim((string) ($lora['category'] ?? '')));
            $groups = mb_strtolower((string) ($lora['conflict_groups'] ?? ''));
            $isClothingLora = $category === 'clothing' || str_contains($groups, 'clothing_primary') || !empty($lora['requires_outfit_none']);
            $hasOtherClothingLora = $activeClothingLoraCount > ($isClothingLora ? 1 : 0);
            $hasClothingContext = !empty($context['has_outfit']) || $hasOtherClothingLora || trim((string) ($context['clothing_override_key'] ?? '')) !== '';
            $label = trim((string) ($lora['alias'] ?? $lora['name'] ?? ''));
            if ($variantKey !== '') {
                $label .= '/' . $variantKey;
                $policies[] = $label . ':' . $policy . ($detected !== '' ? ' [' . $detected . ']' : '');
            }

            $effectiveTags = $variantTags;
            if ($variantTags !== '' && $policy === 'incidental' && $stripWhenActive && $hasClothingContext) {
                $filtered = $classifier->filter($variantTags, $explicitClothingTags);
                $effectiveTags = $filtered['kept_tags'];
                if ($filtered['removed_tags'] !== '') {
                    $removed[] = $label . ': ' . $filtered['removed_tags'];
                    $warnings[] = $label . ' incidental clothing tags stripped because outfit/clothing context is active.';
                }
            } elseif ($variantTags !== '' && $policy === 'forbidden') {
                $filtered = $classifier->filter($variantTags, $explicitClothingTags);
                $effectiveTags = $filtered['kept_tags'];
                if ($filtered['removed_tags'] !== '') {
                    $removed[] = $label . ': ' . $filtered['removed_tags'];
                }
                $negative[] = $classifier->negativeFor($variantTags);
            } elseif (in_array($policy, ['required', 'override'], true) && ($detected !== '' || $requiredClothingTags !== '')) {
                $policies[] = $label . ':requires ' . $this->joinSegments([$detected, $requiredClothingTags]);
            }

            $lora['variant_tags_effective'] = $effectiveTags;
            $lora['variant_clothing_policy'] = $policy;
            $lora['variant_clothing_tags'] = $detected;
            $lora['variant_clothing_required_tags'] = $requiredClothingTags;
            $lora['variant_strip_clothing_when_outfit_active'] = $stripWhenActive;
            $normalized[] = $lora;
        }

        return [
            'loras' => $normalized,
            'removed_tags' => $this->joinSegments($removed),
            'warnings' => $this->joinSegments($warnings),
            'negative_tags' => $this->joinSegments($negative),
            'policy_layer' => $this->joinSegments($policies),
        ];
    }

    private function activeClothingLoraCount(array $loras): int
    {
        $count = 0;
        foreach ($loras as $lora) {
            if (!is_array($lora) || (isset($lora['enabled']) && !$lora['enabled'])) {
                continue;
            }
            $category = mb_strtolower(trim((string) ($lora['category'] ?? '')));
            $groups = mb_strtolower((string) ($lora['conflict_groups'] ?? ''));
            if ($category === 'clothing' || str_contains($groups, 'clothing_primary')) {
                $count++;
            }
        }
        return $count;
    }

    private function variantClothingPolicy(string $policy): string
    {
        $policy = mb_strtolower(trim($policy));
        return in_array($policy, ['incidental', 'required', 'override', 'forbidden'], true) ? $policy : 'incidental';
    }

    private function formatLoraConflictNegatives(array $loras): string
    {
        $parts = [];
        foreach ($loras as $lora) {
            if (!is_array($lora) || isset($lora['enabled']) && !$lora['enabled']) {
                continue;
            }
            $conflicts = trim((string) ($lora['conflict_negatives'] ?? ''));
            if ($conflicts !== '') {
                $parts[] = $conflicts;
            }
        }
        return $this->joinSegments($parts);
    }

    private function formatLoraVariantNegatives(array $loras): string
    {
        $parts = [];
        foreach ($loras as $lora) {
            if (!is_array($lora) || isset($lora['enabled']) && !$lora['enabled']) {
                continue;
            }
            $negative = trim((string) ($lora['variant_negative_tags'] ?? ''));
            if ($negative !== '') {
                $parts[] = $negative;
            }
        }
        return $this->joinSegments($parts);
    }

    private function modeConfig(string $mode): array
    {
        $modes = $this->modeDefinitions();
        return $modes[$mode] ?? $modes['standard'] ?? reset($modes);
    }

    private function resolveSemanticQuickTags(array $input): array
    {
        $visual = [];
        $applied = [];
        $ignored = [];
        $map = $this->quickTagMap();
        $director = $this->nsfwDirector();
        $poses = $this->poseLibrary();
        $quickTags = is_array($input['quick_tags'] ?? null)
            ? $input['quick_tags']
            : explode(',', (string) ($input['quick_tags'] ?? ''));

        foreach ($quickTags as $rawTag) {
            $tag = trim((string) $rawTag);
            if ($tag === '') {
                continue;
            }
            $normalized = mb_strtolower($tag);
            $target = $map[$normalized] ?? null;
            if (!is_array($target)) {
                $visual[] = $tag;
                continue;
            }

            $type = (string) ($target['type'] ?? '');
            $key = (string) ($target['key'] ?? '');
            if ($type === '' || $key === '') {
                $ignored[] = "{$tag}: invalid semantic mapping";
                continue;
            }

            $label = "{$tag} -> {$type}:{$key}";
            if ($type === 'act') {
                $currentAct = trim((string) ($input['nsfw_act'] ?? ''));
                if (!isset($director['acts'][$key])) {
                    $ignored[] = "{$label} missing";
                    continue;
                }
                if ($currentAct === '') {
                    $input['nsfw_enabled'] = true;
                    $input['nsfw_act'] = $key;
                    $applied[] = $label;
                    continue;
                }
                if ($currentAct === $key) {
                    $applied[] = "{$label} already active";
                    continue;
                }
                $ignored[] = $this->strictQuickTagIgnore($label, $currentAct, $input);
                continue;
            }

            if ($type === 'pose') {
                $currentPose = trim((string) ($input['pose_key'] ?? ''));
                if (!isset($poses[$key])) {
                    $ignored[] = "{$label} missing";
                    continue;
                }
                $conflict = $this->semanticPoseConflict($poses[$key], $director, $input);
                if ($conflict !== '') {
                    $ignored[] = "{$label} {$conflict}";
                    continue;
                }
                if ($currentPose === '') {
                    $input['pose_enabled'] = true;
                    $input['pose_key'] = $key;
                    $input['pose_intensity'] = (string) ($poses[$key]['intensity'] ?? ($input['pose_intensity'] ?? 'suggestive'));
                    $applied[] = $label;
                    continue;
                }
                if ($currentPose === $key) {
                    $applied[] = "{$label} already active";
                    continue;
                }
                $ignored[] = "{$label} ignored; explicit pose {$currentPose} wins";
                continue;
            }

            $field = match ($type) {
                'expression' => 'nsfw_expression',
                'clothing' => 'nsfw_clothing_state',
                default => '',
            };
            $collection = match ($type) {
                'expression' => $director['expressions'] ?? [],
                'clothing' => $director['clothing_states'] ?? [],
                default => [],
            };
            if ($field === '' || !isset($collection[$key])) {
                $ignored[] = "{$label} missing";
                continue;
            }
            $current = trim((string) ($input[$field] ?? ''));
            if ($current === '') {
                $input['nsfw_enabled'] = true;
                $input[$field] = $key;
                $applied[] = $label;
                continue;
            }
            if ($current === $key) {
                $applied[] = "{$label} already active";
                continue;
            }
            $ignored[] = "{$label} ignored; explicit {$type} {$current} wins";
        }

        $input['quick_tags'] = $visual;
        return [
            'input' => $input,
            'visual_tags' => $visual,
            'applied' => $applied,
            'ignored' => $ignored,
        ];
    }

    private function strictQuickTagIgnore(string $label, string $currentAct, array $input): string
    {
        $strict = !array_key_exists('nsfw_strict', $input) || filter_var($input['nsfw_strict'], FILTER_VALIDATE_BOOLEAN);
        if ($strict) {
            return "{$label} conflicts with Strict Act {$currentAct}";
        }
        return "{$label} ignored; explicit act {$currentAct} wins";
    }

    private function semanticPoseConflict(array $pose, array $director, array $input): string
    {
        $currentAct = trim((string) ($input['nsfw_act'] ?? ''));
        $strict = $currentAct !== '' && (!array_key_exists('nsfw_strict', $input) || filter_var($input['nsfw_strict'], FILTER_VALIDATE_BOOLEAN));
        if (!$strict || $currentAct === '') {
            return '';
        }
        $act = is_array($director['acts'][$currentAct] ?? null) ? $director['acts'][$currentAct] : [];
        $actGroup = mb_strtolower(trim((string) ($act['act_group'] ?? '')));
        $incompatibleActs = $this->tagArray((string) ($pose['incompatible_acts'] ?? ''));
        if (in_array(mb_strtolower($currentAct), $incompatibleActs, true)) {
            return "blocked by Strict Act {$currentAct}";
        }
        $compatibleGroups = $this->tagArray((string) ($pose['compatible_act_groups'] ?? ''));
        if ($compatibleGroups && $actGroup !== '' && !in_array($actGroup, $compatibleGroups, true)) {
            return "incompatible with act group {$actGroup}";
        }
        return '';
    }

    private function quickTagMap(): array
    {
        return [
            'blush' => ['type' => 'expression', 'key' => 'blush'],
            'bedroom_eyes' => ['type' => 'expression', 'key' => 'bedroom_eyes'],
            'spread_legs' => ['type' => 'act', 'key' => 'spread_legs'],
            'legs_open' => ['type' => 'act', 'key' => 'spread_legs'],
            'missionary' => ['type' => 'act', 'key' => 'missionary_position'],
            'doggy_style' => ['type' => 'act', 'key' => 'doggy_style'],
            'cowgirl_position' => ['type' => 'act', 'key' => 'cowgirl_position'],
            'see_through' => ['type' => 'clothing', 'key' => 'see_through'],
            'no_clothes' => ['type' => 'clothing', 'key' => 'nude'],
            'completely_nude' => ['type' => 'clothing', 'key' => 'nude'],
            'exposed_breasts' => ['type' => 'clothing', 'key' => 'topless'],
            'on_all_fours' => ['type' => 'pose', 'key' => 'all_fours_tease'],
            'bdsm' => ['type' => 'act', 'key' => 'bondage_pose'],
            'bondage' => ['type' => 'act', 'key' => 'bondage_pose'],
        ];
    }

    private function nsfwDirectorLayers(array $input, array $modeConfig): array
    {
        $director = $this->nsfwDirector();
        $modeKey = (string) ($modeConfig['key'] ?? 'standard');
        $autoEnabled = in_array($modeKey, ['soft_nsfw', 'hard_nsfw'], true);
        $enabled = $autoEnabled || filter_var($input['nsfw_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $empty = [
            'nsfw_intensity' => '',
            'nsfw_act' => '',
            'nsfw_scene_intent' => '',
            'nsfw_focus' => '',
            'nsfw_contact_lock' => '',
            'nsfw_contact_lock_negative' => '',
            'nsfw_expression' => '',
            'nsfw_clothing_state' => '',
            'nsfw_camera' => '',
            'nsfw_anatomy_lock' => '',
            'nsfw_strict_act' => '',
            'nsfw_effects' => '',
            'nsfw_effects_blocked' => '',
            'nsfw_effects_warning' => '',
            'nsfw_boost' => '',
        ];

        if (!$enabled) {
            return [
                'layers' => $empty,
                'negative_tags' => '',
                'selection' => ['enabled' => false],
            ];
        }

        $intensityKey = (string) ($input['nsfw_intensity'] ?? ($modeKey === 'hard_nsfw' ? 'hard' : 'soft'));
        $actKey = (string) ($input['nsfw_act'] ?? '');
        $act = $this->directorItem($director['acts'] ?? [], $actKey);
        $strict = $act !== null && (!array_key_exists('nsfw_strict', $input) || filter_var($input['nsfw_strict'], FILTER_VALIDATE_BOOLEAN));
        $intensity = $this->directorItem($director['intensities'] ?? [], $intensityKey);
        if ($intensity === null) {
            $intensity = $modeKey === 'hard_nsfw'
                ? $this->directorItem($director['intensities'] ?? [], 'hard')
                : $this->directorItem($director['intensities'] ?? [], 'soft');
        }

        $hasSceneIntentInput = array_key_exists('nsfw_scene_intent', $input);
        $sceneIntentKey = trim((string) ($input['nsfw_scene_intent'] ?? ''));
        if ($sceneIntentKey === '' && !$hasSceneIntentInput && $act !== null) {
            $sceneIntentKey = (string) ($act['scene_intent'] ?? '');
        }
        if ($sceneIntentKey === '' && !$hasSceneIntentInput) {
            $sceneIntentKey = 'implied_pov';
        }
        $sceneIntent = $this->directorItem($director['scene_intents'] ?? [], $sceneIntentKey);

        $focusKey = (string) ($input['nsfw_focus'] ?? '');
        if ($focusKey === '' && $act !== null) {
            $focusKey = (string) ($act['recommended_focus'] ?? '');
        }
        if ($act === null && $this->isActBoundFocus($focusKey)) {
            $focusKey = '';
        }
        $cameraKey = (string) ($input['nsfw_camera'] ?? '');
        if ($cameraKey === '' && $act !== null) {
            $cameraKey = (string) ($act['recommended_camera'] ?? '');
        }
        if ($strict && $act !== null) {
            [$focusKey, $cameraKey] = $this->strictActSelectionOverrides((string) ($act['key'] ?? ''), $focusKey, $cameraKey);
        }

        $focus = $this->directorItem($director['focuses'] ?? [], $focusKey);
        $expression = $this->directorItem($director['expressions'] ?? [], (string) ($input['nsfw_expression'] ?? ''));
        $clothingState = $this->directorItem($director['clothing_states'] ?? [], (string) ($input['nsfw_clothing_state'] ?? ''));
        $camera = $this->directorItem($director['cameras'] ?? [], $cameraKey);
        $contactLock = $this->contactLockLayer($input, $act, $strict);
        $effectLayer = $this->nsfwEffectsLayer($input, $director, [
            'act' => (string) ($act['key'] ?? ''),
            'act_group' => (string) ($act['act_group'] ?? ''),
            'scene_intent' => (string) ($sceneIntent['key'] ?? ''),
            'strict' => $strict,
        ]);

        $isHard = in_array($modeKey, ['hard_nsfw'], true) || in_array((string) ($intensity['key'] ?? ''), ['explicit', 'hard'], true);
        $isTeaseContactLock = ($contactLock['key'] ?? '') === 'tease';
        $actTags = $act !== null ? $this->joinSegments([
            $isTeaseContactLock ? $this->teaseContactActTags($act) : (string) ($act['positive_tags'] ?? ''),
            $isHard && !$isTeaseContactLock ? (string) ($act['hard_tags'] ?? '') : '',
        ]) : '';
        if ($isTeaseContactLock) {
            $actTags = $this->stripInsertionTags($actTags);
            if ($focusKey === 'penetration_focus') {
                $focus = null;
            }
        }
        $boostTags = !empty($input['nsfw_boost_enabled'])
            ? $this->nsfwBoostTags($act !== null, (string) ($act['act_group'] ?? ''))
            : '';
        $intensityTags = (string) ($intensity['tags'] ?? '');
        if ($act === null && ($modeKey === 'hard_nsfw' || in_array((string) ($intensity['key'] ?? ''), ['explicit', 'hard'], true))) {
            $intensityTags = $this->actlessHardIntensityTags();
        }

        return [
            'layers' => [
                'nsfw_intensity' => $this->joinSegments([
                    $intensityTags,
                    $isHard && $act !== null && !$strict ? (string) ($intensity['hard_tags'] ?? '') : '',
                ]),
                'nsfw_act' => $actTags,
                'nsfw_scene_intent' => (string) ($sceneIntent['tags'] ?? ''),
                'nsfw_focus' => (string) ($focus['tags'] ?? ''),
                'nsfw_contact_lock' => $contactLock['tags'],
                'nsfw_contact_lock_negative' => $contactLock['negative_tags'],
                'nsfw_expression' => (string) ($expression['tags'] ?? ''),
                'nsfw_clothing_state' => (string) ($clothingState['tags'] ?? ''),
                'nsfw_camera' => (string) ($camera['tags'] ?? ''),
                'nsfw_anatomy_lock' => $act !== null ? (string) ($act['anatomy_tags'] ?? '') : '',
                'nsfw_strict_act' => $strict ? $this->joinSegments([
                    (string) ($act['strict_tags'] ?? ''),
                    $this->strictActExtraPositive($act),
                ]) : '',
                'nsfw_effects' => $effectLayer['tags'],
                'nsfw_effects_blocked' => $effectLayer['blocked'],
                'nsfw_effects_warning' => $effectLayer['warnings'],
                'nsfw_boost' => $boostTags,
            ],
            'negative_tags' => $this->joinSegments([
                (string) ($act['negative_disambiguation'] ?? ''),
                (string) ($sceneIntent['negative_tags'] ?? ''),
                $contactLock['negative_tags'],
                $strict ? (string) ($act['conflict_negatives'] ?? '') : '',
                $strict && $act !== null && !$this->allowsCowgirlBias($act) ? $this->antiCowgirlBiasNegatives() : '',
                $strict && $act !== null ? $this->strictActExtraNegatives($act) : '',
            ]),
            'effect_negative_tags' => $effectLayer['negative_tags'],
            'selection' => [
                'enabled' => true,
                'strict' => $strict,
                'intensity' => (string) ($intensity['key'] ?? ''),
                'act' => (string) ($act['key'] ?? ''),
                'scene_intent' => (string) ($sceneIntent['key'] ?? ''),
                'focus' => (string) ($focus['key'] ?? ''),
                'contact_lock' => $contactLock['key'],
                'expression' => (string) ($expression['key'] ?? ''),
                'clothing_state' => (string) ($clothingState['key'] ?? ''),
                'camera' => (string) ($camera['key'] ?? ''),
                'act_group' => (string) ($act['act_group'] ?? ''),
                'effects' => $effectLayer['selected'],
                'boost' => !empty($input['nsfw_boost_enabled']),
            ],
        ];
    }

    private function nsfwEffectsLayer(array $input, array $director, array $selection): array
    {
        $requested = is_array($input['nsfw_effects'] ?? null) ? $input['nsfw_effects'] : [];
        $effects = is_array($director['effects'] ?? null) ? $director['effects'] : [];
        $act = mb_strtolower(trim((string) ($selection['act'] ?? '')));
        $actGroup = mb_strtolower(trim((string) ($selection['act_group'] ?? '')));
        $sceneIntent = mb_strtolower(trim((string) ($selection['scene_intent'] ?? '')));
        $strict = !empty($selection['strict']);
        $tags = [];
        $negative = [];
        $blocked = [];
        $warnings = [];
        $selected = [];

        foreach ($requested as $rawKey) {
            $key = trim((string) $rawKey);
            if ($key === '' || !isset($effects[$key]) || !is_array($effects[$key])) {
                continue;
            }
            $effect = $effects[$key];
            $label = (string) ($effect['label'] ?? $key);
            $incompatibleActs = $this->tagArray((string) ($effect['incompatible_acts'] ?? ''));
            $compatibleGroups = $this->tagArray((string) ($effect['compatible_act_groups'] ?? ''));
            $requiredSceneIntents = $this->tagArray((string) ($effect['requires_scene_intent'] ?? ''));
            if ($strict && $act !== '' && in_array($act, $incompatibleActs, true)) {
                $blocked[] = "{$label} blocked by Strict Act {$act}";
                continue;
            }
            if ($strict && $actGroup !== '' && $compatibleGroups && !in_array($actGroup, $compatibleGroups, true)) {
                $blocked[] = "{$label} incompatible with act group {$actGroup}";
                continue;
            }
            if ($act === '' && $compatibleGroups && !array_intersect($compatibleGroups, ['solo', 'tease', 'body_detail', 'exposure'])) {
                $blocked[] = "{$label} requires an active Act";
                continue;
            }
            if ($requiredSceneIntents && $sceneIntent !== '' && !in_array($sceneIntent, $requiredSceneIntents, true)) {
                $warnings[] = "{$label} expects scene intent " . implode('/', $requiredSceneIntents) . " but current is {$sceneIntent}";
            }
            $tags[] = (string) ($effect['tags'] ?? '');
            $negative[] = (string) ($effect['negative_tags'] ?? '');
            $selected[] = $key;
        }

        return [
            'tags' => $this->joinSegments($tags),
            'negative_tags' => $this->joinSegments($negative),
            'blocked' => $this->joinSegments($blocked),
            'warnings' => $this->joinSegments($warnings),
            'selected' => $selected,
        ];
    }

    private function contactLockLayer(array $input, ?array $act, bool $strict): array
    {
        $empty = ['key' => '', 'tags' => '', 'negative_tags' => ''];
        if ($act === null || !$this->isContactLockAct($act)) {
            return $empty;
        }

        $requested = trim((string) ($input['nsfw_contact_lock'] ?? ''));
        $allowed = ['tease', 'contact', 'inserted', 'deep_inserted'];
        $key = in_array($requested, $allowed, true) ? $requested : $this->defaultContactLockForAct($act);
        if ($key === '') {
            return $empty;
        }

        $actKey = (string) ($act['key'] ?? '');
        $actGroup = (string) ($act['act_group'] ?? '');
        $baseNegative = 'floating penis, disconnected bodies, gap between hips, unclear contact point, misplaced genitals';

        if ($key === 'tease') {
            return [
                'key' => $key,
                'tags' => 'teasing outside contact, member outside body, no insertion, near-contact erotic tease, readable non-insertion pose',
                'negative_tags' => 'inserted penis, vaginal penetration, anal penetration, toy insertion, penis inside body, deep penetration',
            ];
        }

        if ($key === 'contact') {
            return [
                'key' => $key,
                'tags' => $this->joinSegments([
                    'clear body contact, visible contact point, pelvis contact, hips aligned, no gap between bodies',
                    $this->contactLockActSpecificTags($actKey, $actGroup, 'contact'),
                ]),
                'negative_tags' => $baseNegative,
            ];
        }

        $insertedTags = $this->joinSegments([
            $this->contactLockActSpecificTags($actKey, $actGroup, 'inserted'),
            'visible insertion point, pelvis-to-pelvis contact, hips aligned, no gap between bodies, clear contact point',
            $key === 'deep_inserted' ? $this->contactLockActSpecificTags($actKey, $actGroup, 'deep_inserted') : '',
        ]);
        $insertedNegative = $this->joinSegments([
            $baseNegative,
            'penis outside body, penis beside pussy, no penetration, near pussy only, hovering above penis, sitting beside penis, dry pose, teasing only',
            $actKey === 'cowgirl_position' ? 'non-contact cowgirl, penis below but not inserted, hovering cowgirl' : '',
        ]);

        return [
            'key' => $key,
            'tags' => $insertedTags,
            'negative_tags' => $strict ? $insertedNegative : $baseNegative,
        ];
    }

    private function isContactLockAct(array $act): bool
    {
        $key = (string) ($act['key'] ?? '');
        $group = (string) ($act['act_group'] ?? '');
        return in_array($group, ['penetration', 'anal'], true)
            || in_array($key, ['toy_insertion', 'vibrator_play', 'sex_toys', 'double_penetration'], true);
    }

    private function defaultContactLockForAct(array $act): string
    {
        $key = (string) ($act['key'] ?? '');
        $group = (string) ($act['act_group'] ?? '');
        if (in_array($key, ['vibrator_play', 'sex_toys'], true)) {
            return 'contact';
        }
        if (in_array($group, ['penetration', 'anal'], true) || in_array($key, ['toy_insertion', 'double_penetration'], true)) {
            return 'inserted';
        }
        return '';
    }

    private function contactLockActSpecificTags(string $actKey, string $actGroup, string $lockKey): string
    {
        if ($lockKey === 'contact') {
            return match ($actKey) {
                'cowgirl_position' => 'woman on top with pelvis contact, straddling contact, hips lowered onto partner',
                'reverse_cowgirl' => 'reverse cowgirl pelvis contact, hips lowered onto partner, rear view contact point',
                'doggy_style', 'from_behind', 'standing_doggy' => 'from-behind contact point, hips pressed from behind, rear contact alignment',
                'missionary_position', 'mating_press' => 'partner above with pelvis contact, legs spread around partner, frontal contact point',
                'toy_insertion', 'vibrator_play', 'sex_toys' => 'toy touching entrance, clear toy contact point, toy aligned with body',
                default => $actGroup === 'anal' ? 'rear contact point, ass contact alignment' : 'genital contact alignment',
            };
        }

        if ($lockKey === 'deep_inserted') {
            return match ($actGroup) {
                'anal' => '(deep anal penetration:1.55), (fully inserted:1.35), penis deep inside ass, deep rear insertion, close locked hips, no visible gap',
                'penetration' => '(deep vaginal penetration:1.55), (fully inserted:1.35), penis deep inside pussy, deep insertion, close locked hips, no visible gap',
                default => '(deep insertion:1.45), fully inserted contact point, close locked contact, no visible gap',
            };
        }

        return match ($actKey) {
            'cowgirl_position' => '(penis inserted:1.5), (vaginal penetration:1.5), woman on top with inserted penis, straddling with vaginal penetration, hips lowered onto partner, penis inside pussy, pelvis locked together',
            'reverse_cowgirl' => '(penis inserted:1.45), reverse cowgirl vaginal penetration, woman on top facing away, penis inside pussy, hips lowered onto partner',
            'doggy_style', 'from_behind', 'standing_doggy' => '(vaginal penetration from behind:1.45), penis inserted from behind, penis inside pussy, hips pressed from behind',
            'missionary_position', 'mating_press' => '(vaginal penetration:1.45), partner above with inserted penis, penis inside pussy, legs around partner',
            'anal_sex' => '(anal penetration:1.45), penis inside ass, visible anal insertion point, rear insertion',
            'toy_insertion' => '(toy inserted:1.35), dildo inserted, toy inside body, visible toy insertion point',
            'double_penetration' => '(double penetration:1.35), two inserted contact points, vaginal and anal insertion, clear multi-contact composition',
            default => $actGroup === 'anal'
                ? '(anal penetration:1.35), penis inside ass, visible insertion point'
                : '(penis inserted:1.35), vaginal penetration, penis inside pussy, visible insertion point',
        };
    }

    private function teaseContactActTags(array $act): string
    {
        return match ((string) ($act['key'] ?? '')) {
            'cowgirl_position' => 'non-penetrative cowgirl tease, woman on top tease, straddling above partner, hovering hips, outside contact only',
            'reverse_cowgirl' => 'non-penetrative reverse cowgirl tease, woman on top facing away, hovering hips, outside contact only',
            'doggy_style', 'from_behind', 'standing_doggy' => 'from-behind tease, hips presented, outside contact only, non-penetrative rear tease',
            'missionary_position', 'mating_press' => 'missionary tease, close body contact, outside contact only, non-penetrative intimate pose',
            'anal_sex' => 'rear tease, outside contact only, non-penetrative anal tease, close hips',
            'toy_insertion', 'vibrator_play', 'sex_toys' => 'toy tease, toy near body, outside contact only, no insertion',
            default => 'non-penetrative tease, outside contact only, close body contact, readable teasing pose',
        };
    }

    private function nsfwBoostTags(bool $hasAct, string $actGroup): string
    {
        $neutral = '(nsfw:1.25), uncensored, explicit adult mood, intense erotic focus';
        if (!$hasAct) {
            return $neutral;
        }
        $actGroup = mb_strtolower(trim($actGroup));
        $groupTags = match ($actGroup) {
            'breast_sex' => 'clear breast contact, breast-focused explicit detail, visible contact point',
            'oral' => 'clear oral contact, saliva detail, mouth contact focus',
            'penetration' => 'clear penetration contact, hips aligned, visible contact point',
            'anal' => 'clear rear contact point, ass-focused explicit detail',
            'toy' => 'clear toy contact, toy-focused explicit detail',
            'solo', 'tease' => 'explicit solo body detail, seductive body focus',
            default => 'clear contact point, explicit scene clarity',
        };
        return $this->joinSegments([$neutral, $groupTags]);
    }

    private function focusedNsfwModeTags(string $modeKey, string $intensityKey): string
    {
        if ($modeKey === 'hard_nsfw' || in_array($intensityKey, ['explicit', 'hard'], true)) {
            return $this->hardNsfwNeutralTags();
        }

        return '(nsfw:1.35), (sensual:1.2), (erotic:1.15), intimate mood, adult nude scene';
    }

    private function actlessHardIntensityTags(): string
    {
        return '(nsfw:1.45), (explicit:1.22), uncensored, intense erotic adult mood, provocative adult framing';
    }

    private function directorItem(array $items, string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        return isset($items[$key]) && is_array($items[$key]) ? $items[$key] : null;
    }

    private function filterPolishAgainstNsfwConflicts(string $polish, array $input): string
    {
        $actKey = trim((string) ($input['nsfw_act'] ?? ''));
        if ($actKey === '' || (array_key_exists('nsfw_strict', $input) && !filter_var($input['nsfw_strict'], FILTER_VALIDATE_BOOLEAN))) {
            return $polish;
        }
        $act = $this->directorItem($this->nsfwDirector()['acts'] ?? [], $actKey);
        if ($act === null) {
            return $polish;
        }
        $blocked = $this->tagArray($this->joinSegments([
            (string) ($act['conflict_negatives'] ?? ''),
            $this->strictActExtraNegatives($act),
        ]));
        if (!$blocked) {
            return $polish;
        }
        $clean = [];
        foreach (explode(',', $polish) as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $normalized = mb_strtolower(trim(preg_replace('/^\((.*?)(?::[\d.]+)?\)$/', '$1', $tag) ?? $tag));
            $conflicts = false;
            foreach ($blocked as $bad) {
                if ($normalized === $bad || str_contains($normalized, $bad)) {
                    $conflicts = true;
                    break;
                }
            }
            if (!$conflicts) {
                $clean[] = $tag;
            }
        }
        return $this->joinSegments($clean);
    }

    private function poseLayer(array $input, array $nsfwSelection): array
    {
        $enabled = !array_key_exists('pose_enabled', $input) || filter_var($input['pose_enabled'], FILTER_VALIDATE_BOOLEAN);
        $poseKey = trim((string) ($input['pose_key'] ?? ''));
        $poses = $this->poseLibrary();
        $empty = ['tags' => '', 'negative_tags' => '', 'blocked' => '', 'selection' => ['enabled' => false]];
        if (!$enabled || $poseKey === '' || !isset($poses[$poseKey]) || !is_array($poses[$poseKey])) {
            return $empty;
        }

        $pose = $poses[$poseKey];
        $act = mb_strtolower(trim((string) ($nsfwSelection['act'] ?? '')));
        $actGroup = mb_strtolower(trim((string) ($nsfwSelection['act_group'] ?? '')));
        $strict = !empty($nsfwSelection['strict']);
        $incompatibleActs = $this->tagArray((string) ($pose['incompatible_acts'] ?? ''));
        $compatibleGroups = $this->tagArray((string) ($pose['compatible_act_groups'] ?? ''));

        if ($strict && $act !== '') {
            if (in_array($act, $incompatibleActs, true)) {
                return [
                    'tags' => '',
                    'negative_tags' => '',
                    'blocked' => sprintf('%s blocked by Strict Act (%s)', (string) ($pose['label'] ?? $poseKey), $act),
                    'selection' => ['enabled' => true, 'key' => $poseKey, 'blocked' => true],
                ];
            }
            if ($compatibleGroups && $actGroup !== '' && !in_array($actGroup, $compatibleGroups, true)) {
                return [
                    'tags' => '',
                    'negative_tags' => '',
                    'blocked' => sprintf('%s incompatible with act group %s', (string) ($pose['label'] ?? $poseKey), $actGroup),
                    'selection' => ['enabled' => true, 'key' => $poseKey, 'blocked' => true],
                ];
            }
        }

        return [
            'tags' => (string) ($pose['tags'] ?? ''),
            'negative_tags' => (string) ($pose['negative_tags'] ?? ''),
            'blocked' => '',
            'selection' => [
                'enabled' => true,
                'key' => $poseKey,
                'intensity' => (string) ($pose['intensity'] ?? 'suggestive'),
                'blocked' => false,
            ],
        ];
    }

    private function loraVariantCameraLock(array $loras): array
    {
        $detected = '';
        foreach ($loras as $lora) {
            if (!is_array($lora) || (array_key_exists('enabled', $lora) && !$lora['enabled'])) {
                continue;
            }
            $tags = $this->tagArray($this->joinSegments([
                (string) ($lora['variant_trigger_words'] ?? ''),
                (string) ($lora['variant_tags_effective'] ?? $lora['variant_tags'] ?? $lora['variant_positive_tags'] ?? ''),
            ]));
            if (in_array('pov', $tags, true)) {
                $detected = 'pov';
                break;
            }
            if ($detected === '' && (in_array('side view', $tags, true) || in_array('from side', $tags, true))) {
                $detected = 'side_view';
            }
            if ($detected === '' && in_array('full body', $tags, true)) {
                $detected = 'full_body';
            }
        }

        return match ($detected) {
            'pov' => [
                'key' => 'pov',
                'positive_tags' => 'pov, first-person view, intimate close framing, close body contact',
                'negative_tags' => 'full body long shot, distant subject, head-to-toe framing, feet focus, wide shot',
                'remove_tags' => 'full body, full-length body, head-to-toe framing, entire body in frame, entire body visible, feet in frame, standing full body, wide shot, full body long shot, full body composition, full body in frame, head-to-toe body clarity, full body readable, readable full body act, full body tit fuck only',
            ],
            'side_view' => [
                'key' => 'side_view',
                'positive_tags' => 'side view, side angle, readable side framing',
                'negative_tags' => 'front view, back view, from below, low angle, pov, first-person view',
                'remove_tags' => 'front view, back view, from below, low angle, pov, first-person view, first-person framing, intimate camera angle',
            ],
            'full_body' => [
                'key' => 'full_body',
                'positive_tags' => '',
                'negative_tags' => '',
                'remove_tags' => '',
            ],
            default => ['key' => '', 'positive_tags' => '', 'negative_tags' => '', 'remove_tags' => ''],
        };
    }

    private function filterVariantCameraLockText(string $text, array $lock): array
    {
        $blocked = $this->tagArray((string) ($lock['remove_tags'] ?? ''));
        if ($text === '' || $blocked === []) {
            return ['text' => $text, 'removed' => ''];
        }

        $kept = [];
        $removed = [];
        foreach (explode(',', $text) as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }
            if (in_array(mb_strtolower($tag), $blocked, true)) {
                $removed[] = $tag;
                continue;
            }
            $kept[] = $tag;
        }

        return [
            'text' => $this->joinSegments($kept),
            'removed' => $this->joinSegments($removed),
        ];
    }

    private function filterPositiveLayersForVariantCameraLock(array $layers, array $lock): array
    {
        if (trim((string) ($lock['remove_tags'] ?? '')) === '') {
            return $layers;
        }

        $skip = [
            'extra_loras',
            'lora_variants',
            'lora_variant_character_templates',
            'lora_variant_camera_lock',
            'lora_variant_camera_removed',
            'lora_variant_camera_negative',
        ];

        foreach ($layers as $key => $value) {
            if (!is_string($key) || in_array($key, $skip, true) || str_contains($key, 'negative') || str_contains($key, 'removed') || str_contains($key, 'warning') || str_contains($key, 'blocked') || !is_string($value)) {
                continue;
            }
            $layers[$key] = $this->filterVariantCameraLockText($value, $lock)['text'];
        }

        return $layers;
    }

    private function tagArray(string $tags): array
    {
        return array_values(array_filter(array_map(static fn (string $tag): string => mb_strtolower(trim($tag)), explode(',', $tags)), static fn (string $tag): bool => $tag !== ''));
    }

    private function allowsCowgirlBias(array $act): bool
    {
        $key = mb_strtolower((string) ($act['key'] ?? ''));
        $positive = mb_strtolower($this->joinSegments([
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

    private function strictActSelectionOverrides(string $actKey, string $focusKey, string $cameraKey): array
    {
        if ($actKey === 'tit_fuck') {
            if (in_array($focusKey, ['genital_focus', 'penetration_focus', 'ass_focus', 'oral_focus'], true)) {
                $focusKey = 'breast_focus';
            }
            if (in_array($cameraKey, ['full_body', 'low_angle', 'back_view', 'bedside'], true)) {
                $cameraKey = 'close_up';
            }
        }
        return [$focusKey, $cameraKey];
    }

    private function isActBoundFocus(string $focusKey): bool
    {
        return in_array($focusKey, ['penetration_focus', 'oral_focus'], true);
    }

    private function stripInsertionTags(string $tags): string
    {
        $blocked = [
            'explicit penetration',
            'vaginal penetration',
            'anal penetration',
            'double penetration',
            'deep penetration',
            'penetration focus',
            'vaginal sex',
            'anal sex',
            'penis in pussy',
            'penis inside pussy',
            'inserted penis',
            'toy insertion',
            'genital contact',
        ];
        $items = array_filter(array_map('trim', explode(',', $tags)), static function (string $tag) use ($blocked): bool {
            if ($tag === '') {
                return false;
            }
            $normalized = mb_strtolower(trim($tag, " \t\n\r\0\x0B()"));
            foreach ($blocked as $needle) {
                if (str_contains($normalized, $needle)) {
                    return false;
                }
            }
            return true;
        });

        return $this->joinSegments($items);
    }

    private function strictActExtraPositive(array $act): string
    {
        return match ((string) ($act['key'] ?? '')) {
            'tit_fuck' => '(breast focus:1.35), (upper body framing:1.25), cleavage centered, penis horizontal between breasts, breasts squeezing penis, hips cropped out, no lower body sex',
            'tit_fuck_full_body' => '(breast focus:1.25), full body tit fuck, full body in frame, head-to-toe framing, readable breast contact, penis horizontal between breasts, breasts squeezing penis, no mouth contact, no lower body sex',
            default => '',
        };
    }

    private function strictActExtraNegatives(array $act): string
    {
        return match ((string) ($act['key'] ?? '')) {
            'tit_fuck' => 'genital focus, pussy focus, detailed pussy, crotch focus, lower body focus, hip focus, partner below, penis from below, vertical penetration angle, penis in pussy, vaginal sex, anal sex, riding sex, straddling sex, lap sitting, sitting sex, grinding on lap',
            'tit_fuck_full_body' => 'close-up crop, upper body crop, cropped torso, face-only crop, hips cropped out, genital focus, pussy focus, detailed pussy, crotch focus, partner below, penis from below, vertical penetration angle, penis in pussy, vaginal sex, anal sex, riding sex, straddling sex, lap sitting, sitting sex, grinding on lap',
            default => '',
        };
    }

    private function filterStrictStyleTags(string $styleTags, array $selection): string
    {
        if (empty($selection['strict']) || ($selection['act'] ?? '') !== 'tit_fuck') {
            return $styleTags;
        }

        $blocked = ['full body', 'half body', 'from below', 'low angle', 'back view'];
        $tags = array_filter(array_map('trim', explode(',', $styleTags)), static function (string $tag) use ($blocked): bool {
            if ($tag === '') {
                return false;
            }
            return !in_array(mb_strtolower($tag), $blocked, true);
        });

        return $this->joinSegments($tags);
    }

    private function compositionDirective(string $styleTags): array
    {
        $tags = $this->tagArray($styleTags);
        $key = '';
        foreach (['full body', 'half body', 'upper body', 'portrait', 'close-up'] as $candidate) {
            if (in_array($candidate, $tags, true)) {
                $key = $candidate;
                break;
            }
        }

        return match ($key) {
            'full body' => [
                'key' => $key,
                'positive_tags' => 'full-length body, head-to-toe framing, entire body in frame, feet in frame, standing full body',
                'negative_tags' => 'portrait crop, upper body, close-up, closeup, cropped body, cropped legs, cropped feet, cut off feet, out of frame',
            ],
            'half body' => [
                'key' => $key,
                'positive_tags' => 'half body framing, waist-up composition',
                'negative_tags' => 'close-up crop, face-only crop, full body long shot, cut off head',
            ],
            'upper body' => [
                'key' => $key,
                'positive_tags' => 'upper body framing, torso portrait',
                'negative_tags' => 'full body long shot, feet focus, cropped face',
            ],
            'portrait' => [
                'key' => $key,
                'positive_tags' => 'portrait framing, face focus, shoulders visible',
                'negative_tags' => 'full body long shot, tiny face, feet focus',
            ],
            'close-up' => [
                'key' => $key,
                'positive_tags' => 'close-up framing, detailed face, tight crop',
                'negative_tags' => 'full body long shot, distant subject, tiny face',
            ],
            default => ['key' => '', 'positive_tags' => '', 'negative_tags' => ''],
        };
    }

    private function filterCompositionConflicts(string $prompt, string $composition): string
    {
        if ($prompt === '' || $composition === '') {
            return $prompt;
        }

        $conflicts = match ($composition) {
            'full body' => ['portrait', 'upper body', 'half body', 'close-up', 'close up', 'bust shot', 'waist up', 'waist-up'],
            'half body' => ['full body', 'full-length body', 'head-to-toe framing', 'close-up', 'close up'],
            'upper body' => ['full body', 'full-length body', 'head-to-toe framing', 'close-up', 'close up'],
            'portrait', 'close-up' => ['full body', 'full-length body', 'head-to-toe framing', 'feet visible', 'feet in frame'],
            default => [],
        };

        if (!$conflicts) {
            return $prompt;
        }

        $tags = array_filter(array_map('trim', explode(',', $prompt)), static function (string $tag) use ($conflicts): bool {
            if ($tag === '') {
                return false;
            }
            return !in_array(mb_strtolower($tag), $conflicts, true);
        });

        return $this->joinSegments($tags);
    }

    private function expandCompositionStyleTags(string $styleTags, string $positiveTags): string
    {
        if ($positiveTags === '') {
            return $styleTags;
        }

        $segments = array_merge(explode(',', $styleTags), explode(',', $positiveTags));
        $seen = [];
        $clean = [];
        foreach ($segments as $segment) {
            $tag = trim((string) $segment);
            if ($tag === '') {
                continue;
            }
            $key = mb_strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = $tag;
        }

        return $this->joinSegments($clean);
    }

    private function modeDefinitions(): array
    {
        if ($this->store) {
            return $this->store->getPromptLibrary()['modes'];
        }
        $negativeClothing = 'clothes, clothing, dress, shirt, skirt, pants, shorts, underwear, bra, panties, bikini, swimsuit, armor, uniform, robe, coat, jacket, socks, stockings, tights, garters, gloves, headwear, hat, mask, scarf, necklace, bracelet, ring, earrings, goggles, glasses, contact lenses, headphones, earpieces';
        return [
            'standard' => [
                'key' => 'standard',
                'label' => 'Standard',
                'tags' => '',
                'negative_tags' => '',
            ],
            'soft_nsfw' => [
                'key' => 'soft_nsfw',
                'label' => 'Soft NSFW',
                'tags' => '(nsfw:1.4), (nude:1.3), (no clothes:1.3), (naked:1.4), (exposed:1.2), (sensual:1.2), (erotic:1.2)',
                'negative_tags' => $negativeClothing,
            ],
            'hard_nsfw' => [
                'key' => 'hard_nsfw',
                'label' => 'Hard NSFW',
                'tags' => $this->hardNsfwNeutralTags(),
                'negative_tags' => $negativeClothing,
            ],
            'bikini' => [
                'key' => 'bikini',
                'label' => 'Bikini',
                'tags' => 'bikini, swimsuit, beach, (minimal clothing:1.2), (revealing:1.1)',
                'negative_tags' => '',
            ],
            'lingerie' => [
                'key' => 'lingerie',
                'label' => 'Lingerie',
                'tags' => 'lingerie, lace, underwear, (seductive:1.2), (see-through:1.2), (transparent:1.1)',
                'negative_tags' => '',
            ],
        ];
    }

    private function quickTags(): array
    {
        if ($this->store) {
            return $this->store->getPromptLibrary()['quick_tags'];
        }
        return [
            'open_mouth',
            'sweat',
            'wet',
            'no_bra',
            'fishnets',
            'choker',
            'thighhighs',
            'tongue_out',
        ];
    }

    private function hardNsfwNeutralTags(): string
    {
        return '(nsfw:1.5), (explicit:1.25), (uncensored:1.3), mature erotic scene, intense sensual mood, adult-only presentation';
    }

    private function poseLibrary(): array
    {
        if ($this->store) {
            return $this->store->getPromptLibrary()['pose_library'] ?? [];
        }
        $store = new Store();
        return $store->getPromptLibrary()['pose_library'] ?? [];
    }

    private function nsfwDirector(): array
    {
        if ($this->store) {
            return $this->store->getPromptLibrary()['nsfw_director'];
        }
        $store = new Store();
        return $store->getPromptLibrary()['nsfw_director'];
    }

    private function loraDirectorGuardLayers(array $input, array $nsfwSelection): array
    {
        $store = $this->store ?? new Store();
        return (new LoraDirectorGuard($store))->layers($input, $nsfwSelection);
    }

    private function clothingOverrideKey(string $selectedKey, array $input): string
    {
        $selectedKey = trim($selectedKey);
        if ($selectedKey !== '') {
            return $selectedKey;
        }

        $haystack = mb_strtolower($this->joinSegments([
            $this->formatTags($input['quick_tags'] ?? []),
            $this->formatTags($input['custom_tags'] ?? ''),
            (string) ($input['manual_prompt'] ?? ''),
        ]));

        if ($this->containsAny($haystack, ['completely nude', 'no clothes', 'fully nude', 'naked'])) {
            return 'nude';
        }
        if ($this->containsAny($haystack, ['topless', 'exposed breasts', 'bare breasts', 'no bra'])) {
            return 'topless';
        }
        if ($this->containsAny($haystack, ['bottomless', 'no panties', 'exposed pussy'])) {
            return 'bottomless';
        }
        if ($this->containsAny($haystack, ['open clothes', 'clothes pulled aside'])) {
            return 'open_clothes';
        }
        if ($this->containsAny($haystack, ['shirt lift', 'lifted shirt'])) {
            return 'shirt_lift';
        }

        return '';
    }

    private function clothingOverride(string $key, string $outfitTags): array
    {
        $key = trim($key);
        $profiles = $this->clothingOverrideProfiles();
        if ($key === '' || !isset($profiles[$key])) {
            return [
                'active' => false,
                'key' => '',
                'outfit_tags' => $outfitTags,
                'positive_tags' => '',
                'negative_tags' => '',
                'removed_tags' => '',
            ];
        }

        $profile = $profiles[$key];
        [$kept, $removed] = $this->filterOutfitTags($outfitTags, $profile['remove']);

        return [
            'active' => true,
            'key' => $key,
            'outfit_tags' => $this->joinSegments($kept),
            'positive_tags' => (string) ($profile['positive'] ?? ''),
            'negative_tags' => (string) ($profile['negative'] ?? ''),
            'removed_tags' => $this->joinSegments($removed),
        ];
    }

    private function clothingOverrideProfiles(): array
    {
        $upper = ['collared shirt', 'long sleeves', 'sleeves past wrists', 'sleeves past fingers', 'sleeves', 'shirt', 'sweater', 'coat', 'jacket', 'vest', 'corset', 'collar', 'necktie', 'bra', 'dress', 'uniform', 'covered breasts', 'clothed chest'];
        $lower = ['skirt', 'pants', 'shorts', 'panties', 'underwear', 'pantyhose', 'tights', 'leggings'];
        $main = array_values(array_unique(array_merge($upper, $lower, ['clothes', 'clothing', 'robe', 'armor', 'swimsuit', 'bikini', 'socks', 'stockings', 'boots', 'footwear', 'gloves'])));
        $regular = array_values(array_unique(array_merge($upper, $lower, ['robe', 'armor', 'uniform', 'coat', 'jacket'])));

        return [
            'topless' => [
                'remove' => $upper,
                'positive' => '(topless:1.35), (bare breasts:1.3), exposed breasts, nipples, no bra, uncovered chest',
                'negative' => 'shirt, sweater, coat, jacket, bra, covered breasts, clothed chest, fully clothed',
            ],
            'bottomless' => [
                'remove' => $lower,
                'positive' => '(bottomless:1.3), exposed lower body, no panties',
                'negative' => 'pants, skirt, shorts, panties, covered crotch, fully clothed',
            ],
            'nude' => [
                'remove' => $main,
                'positive' => '(nude:1.45), (no clothes:1.45), naked, fully nude, exposed body',
                'negative' => 'clothes, clothing, shirt, dress, skirt, pants, underwear, bra, panties, uniform, coat, jacket',
            ],
            'collar_only' => [
                'remove' => $main,
                'positive' => '(nude:1.4), collar only, naked with collar, no clothes',
                'negative' => 'clothes, clothing, shirt, dress, skirt, pants, underwear, bra, panties, uniform, coat, jacket',
            ],
            'open_clothes' => [
                'remove' => ['closed clothes', 'buttoned shirt', 'fully clothed', 'covered breasts'],
                'positive' => 'open clothes, clothes pulled aside, exposed body under clothes',
                'negative' => 'closed clothes, fully clothed, covered breasts, clothed chest',
            ],
            'shirt_lift' => [
                'remove' => ['sweater', 'coat', 'jacket', 'vest', 'dress', 'uniform', 'closed shirt', 'covered breasts'],
                'positive' => 'shirt lift, lifted shirt, exposed breasts, underboob, no bra',
                'negative' => 'shirt down, closed shirt, sweater, coat, covered breasts, fully clothed',
            ],
            'clothes_lifted' => [
                'remove' => ['coat', 'jacket', 'vest', 'closed clothes', 'covered breasts'],
                'positive' => 'clothes lifted, outfit lifted, exposed body under clothes',
                'negative' => 'clothes down, closed clothes, covered body, fully clothed',
            ],
            'open_shirt' => [
                'remove' => ['sweater', 'coat', 'jacket', 'vest', 'buttoned shirt', 'closed shirt', 'covered breasts'],
                'positive' => 'open shirt, unbuttoned shirt, exposed chest, loose clothing',
                'negative' => 'buttoned shirt, closed shirt, covered breasts, fully clothed',
            ],
            'lingerie' => [
                'remove' => $regular,
                'positive' => 'lingerie, lace underwear, seductive lingerie',
                'negative' => 'shirt, sweater, coat, jacket, dress, uniform, pants, skirt, fully clothed',
            ],
            'micro_bikini' => [
                'remove' => $regular,
                'positive' => 'micro bikini, tiny bikini, barely covered, revealing swimwear',
                'negative' => 'shirt, sweater, coat, jacket, dress, uniform, pants, skirt, fully clothed',
            ],
            'crotchless' => [
                'remove' => ['pants', 'shorts', 'skirt', 'pantyhose', 'tights', 'leggings', 'covered crotch'],
                'positive' => 'crotchless panties, exposed pussy, lingerie pulled open',
                'negative' => 'covered crotch, normal panties, pants, shorts, fully clothed',
            ],
            'panties_aside' => [
                'remove' => ['pants', 'shorts', 'pantyhose', 'tights', 'leggings', 'covered crotch'],
                'positive' => 'panties pulled aside, underwear moved aside, exposed pussy',
                'negative' => 'panties covering crotch, covered crotch, pants, shorts, fully clothed',
            ],
            'torn_clothes' => [
                'remove' => ['fully clothed', 'pristine clothes', 'covered breasts'],
                'positive' => 'torn clothes, ripped outfit, exposed skin, disheveled clothing',
                'negative' => 'pristine clothes, fully clothed, covered body',
            ],
            'wet_clothes' => [
                'remove' => ['dry clothes', 'opaque fabric'],
                'positive' => 'wet clothes, soaked fabric, clinging clothes, water droplets',
                'negative' => 'dry clothes, opaque fabric, loose dry clothing',
            ],
            'see_through' => [
                'remove' => ['opaque fabric', 'covered breasts', 'covered crotch'],
                'positive' => 'see-through clothing, transparent fabric, visible body through clothes',
                'negative' => 'opaque fabric, covered breasts, covered crotch, fully opaque clothing',
            ],
            'naked_apron' => [
                'remove' => $main,
                'positive' => 'naked apron, apron only, bare skin, covered front',
                'negative' => 'clothes, clothing, shirt, dress, skirt, pants, underwear, bra, panties, uniform',
            ],
        ];
    }

    private function filterOutfitTags(string $outfitTags, array $blocked): array
    {
        $kept = [];
        $removed = [];
        foreach (explode(',', $outfitTags) as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            if ($this->tagMatchesAny($tag, $blocked)) {
                $removed[] = $tag;
                continue;
            }
            $kept[] = $tag;
        }
        return [$kept, $removed];
    }

    private function tagMatchesAny(string $tag, array $blocked): bool
    {
        $normalized = mb_strtolower(trim($tag));
        foreach ($blocked as $block) {
            $block = mb_strtolower(trim((string) $block));
            if ($block === '') {
                continue;
            }
            if (str_contains($block, ' ')) {
                if (str_contains($normalized, $block)) {
                    return true;
                }
                continue;
            }
            if (preg_match('/(^|[^a-z0-9])' . preg_quote($block, '/') . '([^a-z0-9]|$)/u', $normalized)) {
                return true;
            }
        }
        return false;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function formatTags(array|string $tags): string
    {
        $items = is_array($tags) ? $tags : explode(',', $tags);
        return $this->joinSegments($items);
    }

    private function formatWeight(float $weight): string
    {
        $formatted = rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.');
        return $formatted === '' ? '1' : $formatted;
    }

    private function findByName(array $items, string $name): ?array
    {
        $target = mb_strtolower(trim($name));
        foreach ($items as $item) {
            if (mb_strtolower((string) $item['name']) === $target) {
                return $item;
            }
        }
        return null;
    }

    private function normalizeFinalPrompt(array $segments, bool $semanticDedup): string
    {
        $tags = [];
        foreach ($segments as $segment) {
            foreach (explode(',', (string) $segment) as $tag) {
                $tag = trim($tag);
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        $seenExact = [];
        $seenSemantic = [];
        $clean = [];
        foreach ($tags as $tag) {
            $exactKey = mb_strtolower($tag);
            if (isset($seenExact[$exactKey])) {
                continue;
            }

            $semanticKey = $semanticDedup ? $this->semanticTagGroup($tag) : null;
            if ($semanticKey !== null && isset($seenSemantic[$semanticKey])) {
                continue;
            }

            $seenExact[$exactKey] = true;
            if ($semanticKey !== null) {
                $seenSemantic[$semanticKey] = true;
            }
            $clean[] = $tag;
        }

        return implode(', ', $clean);
    }

    private function semanticTagGroup(string $tag): ?string
    {
        $normalized = mb_strtolower(trim($tag));
        if ($normalized === '' || preg_match('/^\(.+:\d+(?:\.\d+)?\)$/', $normalized)) {
            return null;
        }

        $groups = [
            'full_body' => ['full body', 'entire body visible', 'entire body in frame', 'head-to-toe framing', 'full-length body', 'standing full body'],
            'solo' => ['solo', 'solo focus', 'no partner visible'],
            'close_up' => ['close-up', 'closeup', 'close-up framing', 'tight framing'],
            'upper_body' => ['upper body', 'upper body framing', 'torso portrait'],
        ];

        foreach ($groups as $group => $tags) {
            if (in_array($normalized, $tags, true)) {
                return $group;
            }
        }

        return null;
    }

    private function joinSegments(array $segments): string
    {
        $segments = array_values(array_filter(array_map(static fn ($segment): string => trim((string) $segment), $segments), static fn (string $segment): bool => $segment !== ''));
        return implode(', ', $segments);
    }
}
