<?php

declare(strict_types=1);

final class LoraDirectorGuard
{
    public function __construct(private Store $store)
    {
    }

    public function evaluate(array $input, array $nsfwSelection = []): array
    {
        $issues = [];
        $blocked = [];
        $warnings = [];
        $activeLoraCount = 0;
        $activeAct = mb_strtolower(trim((string) ($nsfwSelection['act'] ?? $input['nsfw_act'] ?? '')));
        $actGroup = mb_strtolower(trim((string) ($nsfwSelection['act_group'] ?? $this->actGroupFor($activeAct))));
        $sceneIntent = mb_strtolower(trim((string) ($nsfwSelection['scene_intent'] ?? $input['nsfw_scene_intent'] ?? '')));
        $strict = !array_key_exists('nsfw_strict', $input) || filter_var($input['nsfw_strict'], FILTER_VALIDATE_BOOLEAN);
        $outfit = mb_strtolower(trim((string) ($input['outfit'] ?? '')));
        $hasOutfit = $outfit !== '' && !in_array($outfit, ['ninguno', 'none'], true);
        $clothingState = mb_strtolower(trim((string) ($nsfwSelection['clothing_state'] ?? $input['nsfw_clothing_state'] ?? '')));
        $poseKey = mb_strtolower(trim((string) ($input['pose_key'] ?? '')));
        $secondaryCount = $this->secondaryCharacterCount($input);
        $selectionContext = $this->selectionContext($input);
        $activeGroups = [];
        $activeClothingLoraCount = 0;
        foreach (($input['loras'] ?? []) as $candidate) {
            if (!is_array($candidate) || (array_key_exists('enabled', $candidate) && !$candidate['enabled'])) {
                continue;
            }
            $candidateCategory = mb_strtolower(trim((string) ($candidate['category'] ?? '')));
            $candidateGroups = $this->tagList((string) ($candidate['conflict_groups'] ?? ''));
            if ($candidateCategory === 'clothing' || in_array('clothing_primary', $candidateGroups, true) || !empty($candidate['requires_outfit_none'])) {
                $activeClothingLoraCount++;
            }
        }

        foreach (($input['loras'] ?? []) as $lora) {
            if (!is_array($lora) || (array_key_exists('enabled', $lora) && !$lora['enabled'])) {
                continue;
            }
            $alias = trim((string) ($lora['alias'] ?? $lora['name'] ?? ''));
            if ($alias === '') {
                continue;
            }
            $activeLoraCount++;
            $category = mb_strtolower(trim((string) ($lora['category'] ?? '')));
            $groups = $this->tagList((string) ($lora['conflict_groups'] ?? ''));
            if ($category === 'clothing' && !in_array('clothing_primary', $groups, true)) {
                $groups[] = 'clothing_primary';
            }
            if ($category === 'character' && !in_array('character_identity', $groups, true)) {
                $groups[] = 'character_identity';
            }
            $isClothingLora = $category === 'clothing' || in_array('clothing_primary', $groups, true) || !empty($lora['requires_outfit_none']);
            $otherClothingLoraActive = $activeClothingLoraCount > ($isClothingLora ? 1 : 0);
            $variantLabel = trim((string) ($lora['variant_label'] ?? $lora['variant_key'] ?? ''));
            $label = $variantLabel !== '' ? "{$alias} / {$variantLabel}" : $alias;
            $aliasKey = mb_strtolower($alias);

            $compatibleSeries = $this->tagList((string) ($lora['compatible_series'] ?? ''));
            if ($compatibleSeries && !$this->hasAnyToken($selectionContext['series_tokens'], $compatibleSeries)) {
                $this->addIssue($issues, $blocked, 'lora_director.compatible_series_mismatch', 'error', "{$label} is limited to series " . implode(', ', $compatibleSeries) . '.', 'Use a LoRA compatible with the selected character series.');
            }

            $compatibleCharacters = $this->tagList((string) ($lora['compatible_characters'] ?? ''));
            if ($compatibleCharacters && !$this->hasAnyToken($selectionContext['character_tokens'], $compatibleCharacters)) {
                $this->addIssue($issues, $blocked, 'lora_director.compatible_character_mismatch', 'error', "{$label} is limited to character(s) " . implode(', ', $compatibleCharacters) . '.', 'Use the matching character or remove this character-specific LoRA.');
            }

            if (in_array('character_identity', $groups, true) && $selectionContext['base_loras'] !== [] && !in_array($aliasKey, $selectionContext['base_loras'], true)) {
                $this->addIssue($issues, $blocked, 'lora_director.character_identity_conflict', 'error', "{$label} is a character LoRA that conflicts with the selected character base LoRA.", 'Select the matching character profile or use Ensemble for extra character identities.');
            }

            if ($hasOutfit && ($category === 'clothing' || in_array('clothing_primary', $groups, true) || !empty($lora['requires_outfit_none']))) {
                $this->addIssue($issues, $blocked, 'lora_director.outfit_conflict', 'error', "{$label} requires Outfit Ninguno or conflicts with selected outfit '{$outfit}'.", 'Use outfit Ninguno or remove the clothing LoRA/variant.');
            }

            $policy = $this->variantClothingPolicy((string) ($lora['variant_clothing_policy'] ?? $lora['clothing_policy'] ?? 'incidental'));
            $variantTags = trim((string) ($lora['variant_tags'] ?? $lora['variant_positive_tags'] ?? ''));
            $classifier = new ClothingTagClassifier();
            $variantClothingTags = trim((string) ($lora['variant_clothing_tags'] ?? $lora['clothing_tags'] ?? ''));
            if ($variantClothingTags === '') {
                $variantClothingTags = implode(', ', $classifier->detect($variantTags));
            }
            $requiredClothingTags = trim((string) ($lora['variant_clothing_required_tags'] ?? $lora['clothing_required_tags'] ?? ''));
            $hasVariantClothing = $variantClothingTags !== '' || $requiredClothingTags !== '';
            $variantClothingSummary = $this->uniqueTagText([$variantClothingTags, $requiredClothingTags]);
            if ($policy === 'incidental' && $hasVariantClothing && ($hasOutfit || $clothingState !== '' || $otherClothingLoraActive)) {
                $this->addIssue($issues, $warnings, 'lora_director.variant_clothing_stripped', 'warning', "{$label} has incidental clothing tags that will be stripped in the active outfit/clothing context.", 'Set variant policy to required if those clothing tags are part of the pose.');
            }
            if ($policy === 'required' && $hasVariantClothing) {
                if ($clothingState !== '' || $otherClothingLoraActive) {
                    $this->addIssue($issues, $blocked, 'lora_director.variant_clothing_required_conflict', 'error', "{$label} requires clothing tags ({$variantClothingSummary}) but another clothing state/LoRA is active.", 'Use a compatible clothing setup or change the variant policy to incidental.');
                } elseif ($hasOutfit) {
                    $this->addIssue($issues, $warnings, 'lora_director.variant_clothing_required_with_outfit', 'warning', "{$label} requires clothing tags ({$variantClothingSummary}) while an outfit is active.", 'Confirm the outfit contains the required clothing.');
                }
            }
            if ($policy === 'override' && ($hasOutfit || $otherClothingLoraActive)) {
                $this->addIssue($issues, $blocked, 'lora_director.variant_clothing_override_conflict', 'error', "{$label} overrides clothing and needs Outfit Ninguno without another clothing LoRA.", 'Set Outfit to Ninguno or remove the conflicting clothing LoRA.');
            }
            if ($policy === 'forbidden' && ($hasOutfit || $otherClothingLoraActive)) {
                $this->addIssue($issues, $blocked, 'lora_director.variant_clothing_forbidden_conflict', 'error', "{$label} forbids clothing but outfit/clothing LoRA is active.", 'Use Outfit Ninguno and remove clothing LoRAs.');
            }

            foreach ($groups as $group) {
                if (isset($activeGroups[$group])) {
                    $this->addIssue($issues, $blocked, 'lora_director.conflict_group', 'error', "{$label} conflicts with {$activeGroups[$group]} in group {$group}.", 'Remove one of the conflicting LoRAs.');
                } else {
                    $activeGroups[$group] = $label;
                }
            }

            $incompatibleActs = array_unique(array_merge(
                $this->tagList((string) ($lora['incompatible_acts'] ?? '')),
                $this->tagList((string) ($lora['variant_incompatible_acts'] ?? ''))
            ));
            $activeActAliases = $this->equivalentActs($activeAct);
            if ($strict && $activeAct !== '' && array_intersect($activeActAliases, $incompatibleActs) !== []) {
                $this->addIssue($issues, $blocked, 'lora_director.strict_act_incompatible', 'error', "{$label} is incompatible with Strict Act {$activeAct}.", 'Pick a compatible variant or turn off Strict Act.');
            }

            $compatibleActs = array_unique(array_merge(
                $this->tagList((string) ($lora['compatible_acts'] ?? '')),
                $this->tagList((string) ($lora['variant_compatible_acts'] ?? ''))
            ));
            if ($activeAct !== '' && $compatibleActs && array_intersect($activeActAliases, $compatibleActs) === []) {
                $this->addIssue($issues, $blocked, 'lora_director.compatible_act_mismatch', 'error', "{$label} is tuned for " . implode(', ', $compatibleActs) . " but active act is {$activeAct}.", 'Use a variant compatible with the active Director act.');
            }

            $loraActGroups = array_unique(array_merge(
                $this->tagList((string) ($lora['act_groups'] ?? '')),
                $this->tagList((string) ($lora['variant_act_groups'] ?? ''))
            ));
            if ($activeAct !== '' && $actGroup !== '' && $loraActGroups && !in_array($actGroup, $loraActGroups, true)) {
                $this->addIssue($issues, $warnings, 'lora_director.act_group_mismatch', 'warning', "{$label} act group (" . implode(', ', $loraActGroups) . ") differs from Director group {$actGroup}.", 'This may steer the image toward a different act.');
            }
            if ($poseKey !== '' && $category === 'pose' && $loraActGroups) {
                $poseGroups = $this->poseCompatibleGroups($poseKey);
                if ($poseGroups && array_intersect($poseGroups, $loraActGroups) === []) {
                    $this->addIssue($issues, $warnings, 'lora_director.pose_lora_mismatch', 'warning', "{$label} act group (" . implode(', ', $loraActGroups) . ") differs from Pose Direction {$poseKey}.", 'Use a pose LoRA that matches the selected Pose Direction, or clear one of them.');
                }
            }
            $sceneIntentHints = $this->tagList((string) ($lora['scene_intent_hint'] ?? ''));
            if ($sceneIntent !== '' && $sceneIntentHints && !in_array($sceneIntent, $sceneIntentHints, true)) {
                $this->addIssue($issues, $warnings, 'lora_director.scene_intent_hint_mismatch', 'warning', "{$label} expects scene intent " . implode(', ', $sceneIntentHints) . " but current is {$sceneIntent}.", 'Switch Scene Intent or use a compatible LoRA/variant.');
            }

            $requiresSecondary = !empty($lora['requires_secondary_characters']) || !empty($lora['variant_requires_secondary_characters']);
            $minSecondary = max((int) ($lora['min_secondary_characters'] ?? 0), (int) ($lora['variant_min_secondary_characters'] ?? 0));
            $maxSecondary = max((int) ($lora['max_secondary_characters'] ?? 0), (int) ($lora['variant_max_secondary_characters'] ?? 0));
            if ($requiresSecondary && $secondaryCount < max(1, $minSecondary)) {
                $this->addIssue($issues, $warnings, 'lora_director.ensemble_missing_characters', 'warning', "{$label} expects at least {$minSecondary} secondary character(s); current: {$secondaryCount}.", 'Fill the Ensemble slots for this multi-character LoRA.');
            }
            $templateRequiredSecondaries = $this->requiredSecondaryCountForCharacterTemplates([
                (string) ($lora['variant_trigger_words'] ?? ''),
                (string) ($lora['variant_tags'] ?? $lora['variant_positive_tags'] ?? ''),
            ]);
            if ($templateRequiredSecondaries > 0 && $secondaryCount < $templateRequiredSecondaries) {
                $this->addIssue($issues, $blocked, 'lora_director.character_template_missing', 'error', "{$label} needs {$templateRequiredSecondaries} Ensemble character(s) for its character template; current: {$secondaryCount}.", 'Add the required extra character(s) before using this variant.');
            }
            if ($maxSecondary > 0 && $secondaryCount > $maxSecondary) {
                $this->addIssue($issues, $warnings, 'lora_director.ensemble_too_many_characters', 'warning', "{$label} expects at most {$maxSecondary} secondary character(s); current: {$secondaryCount}.", 'Remove extra Ensemble slots or use a variant designed for more characters.');
            }
            if ($requiresSecondary && $sceneIntent === 'solo') {
                $this->addIssue($issues, $blocked, 'lora_director.ensemble_scene_intent_solo', 'error', "{$label} is a multi-character LoRA but Scene Intent is solo.", 'Use visible_partner or close_contact.');
            }
            $partnerTags = $this->uniqueTagText([
                (string) ($lora['anonymous_partner_tags'] ?? ''),
                (string) ($lora['variant_anonymous_partner_tags'] ?? ''),
            ]);
            if ($sceneIntent === 'solo' && $partnerTags !== '') {
                $this->addIssue($issues, $blocked, 'lora_director.partner_scene_intent_solo', 'error', "{$label} implies an anonymous partner but Scene Intent is solo.", 'Use visible_partner, close_contact, or implied_pov.');
            }

            $variantHasTags = trim((string) ($lora['variant_trigger_words'] ?? '')) !== '' || trim((string) ($lora['variant_tags'] ?? $lora['variant_positive_tags'] ?? '')) !== '';
            if (trim((string) ($lora['variant_key'] ?? '')) !== '' && !$variantHasTags) {
                $this->addIssue($issues, $warnings, 'lora_director.variant_empty', 'warning', "{$label} has no variant trigger/tags.", 'Add variant tags so the picker describes what it inserts.');
            }

            $haystack = mb_strtolower($this->joinSegments([
                (string) ($lora['trigger_words'] ?? ''),
                (string) ($lora['variant_trigger_words'] ?? ''),
                (string) ($lora['variant_tags'] ?? $lora['variant_positive_tags'] ?? ''),
                (string) ($lora['notes'] ?? ''),
            ]));
            if (!$loraActGroups && $this->looksSexualText($haystack)) {
                $this->addIssue($issues, $warnings, 'lora_director.missing_act_groups', 'warning', "{$label} looks sexual but has no act_groups metadata.", 'Add act_groups to improve Director compatibility checks.');
            }
            if (!empty($lora['needs_trigger']) || trim((string) ($lora['trigger_words'] ?? '')) === '') {
                $this->addIssue($issues, $warnings, 'lora_director.needs_trigger', 'warning', "{$label} needs confirmed trigger words.", 'Confirm the LoRA trigger in Settings before relying on one-click insertion.');
            }
            if (($category === 'effect' || $this->looksEffectText($haystack)) && trim((string) ($lora['nsfw_effect_groups'] ?? '')) === '') {
                $this->addIssue($issues, $warnings, 'lora_director.missing_effect_groups', 'warning', "{$label} looks like an effect LoRA but has no nsfw_effect_groups metadata.", 'Add effect groups so Planner can rank and audit it.');
            }
            if ($poseKey !== '' && $category === 'pose' && $activeAct !== '' && !$loraActGroups) {
                $this->addIssue($issues, $warnings, 'lora_director.duplicate_pose_steering', 'warning', "{$label} is a pose LoRA while Pose Direction and Director act are also active.", 'Check that all three are steering the same body position.');
            }
            if ($sceneIntent === 'solo' && $this->containsAny($haystack, ['partner', '2boys', '1boy', 'visible partner', 'pov sex', 'penis', 'cock'])) {
                $this->addIssue($issues, $warnings, 'lora_director.scene_intent_suspicious', 'warning', "{$label} may imply a visible partner while Scene Intent is solo.", 'Use implied POV/visible partner or a solo-compatible variant.');
            }
        }

        return [
            'ok' => count(array_filter($issues, static fn (array $issue): bool => ($issue['severity'] ?? '') === 'error')) === 0,
            'issues' => $issues,
            'blocked' => $blocked,
            'warnings' => $warnings,
            'guard' => $activeLoraCount === 0 ? '' : (count($blocked) ? 'blocked' : (count($warnings) ? 'warning' : 'compatible')),
        ];
    }

    private function secondaryCharacterCount(array $input): int
    {
        $count = 0;
        foreach (($input['secondary_characters'] ?? []) as $slot) {
            if (is_array($slot) && (int) ($slot['character_id'] ?? 0) > 0) {
                $count++;
            }
        }
        return $count;
    }

    private function selectionContext(array $input): array
    {
        $seriesTokens = [];
        $characterTokens = [];
        $baseLoras = [];
        $primary = null;
        $primaryId = (int) ($input['character_id'] ?? 0);
        if ($primaryId > 0) {
            $primary = $this->store->getCharacter($primaryId);
        }

        if (is_array($primary)) {
            $this->addCharacterContext($primary, $seriesTokens, $characterTokens, $baseLoras);
        } else {
            $this->addToken($characterTokens, (string) ($input['character_name'] ?? $input['uma'] ?? ''));
            $this->addToken($seriesTokens, (string) ($input['series_name'] ?? ''));
            if ((int) ($input['series_id'] ?? 0) > 0) {
                $seriesTokens[] = (string) ((int) $input['series_id']);
            }
        }

        foreach (($input['secondary_characters'] ?? []) as $slot) {
            if (!is_array($slot) || (array_key_exists('include_base_lora', $slot) && !$slot['include_base_lora'])) {
                continue;
            }
            $characterId = (int) ($slot['character_id'] ?? 0);
            if ($characterId <= 0) {
                continue;
            }
            $character = $this->store->getCharacter($characterId);
            if (is_array($character)) {
                $this->addCharacterContext($character, $seriesTokens, $characterTokens, $baseLoras);
            }
        }

        return [
            'series_tokens' => array_values(array_unique(array_filter($seriesTokens))),
            'character_tokens' => array_values(array_unique(array_filter($characterTokens))),
            'base_loras' => array_values(array_unique(array_filter($baseLoras))),
        ];
    }

    private function addCharacterContext(array $character, array &$seriesTokens, array &$characterTokens, array &$baseLoras): void
    {
        foreach ([
            (string) ($character['series_key'] ?? ''),
            (string) ($character['series_name'] ?? ''),
            (string) ($character['series_id'] ?? ''),
        ] as $token) {
            $this->addToken($seriesTokens, $token);
        }
        foreach ([
            (string) ($character['key'] ?? ''),
            (string) ($character['name'] ?? ''),
            (string) ($character['display_name'] ?? ''),
            (string) ($character['full_name'] ?? ''),
            (string) ($character['id'] ?? ''),
        ] as $token) {
            $this->addToken($characterTokens, $token);
        }
        $baseLora = mb_strtolower(trim((string) ($character['base_lora_alias'] ?? '')));
        if ($baseLora !== '') {
            $baseLoras[] = $baseLora;
        }
    }

    private function addToken(array &$tokens, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $tokens[] = mb_strtolower($value);
        $tokens[] = $this->slugToken($value);
    }

    private function slugToken(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?: '';
        return trim($value, '_');
    }

    private function hasAnyToken(array $actualTokens, array $requiredTokens): bool
    {
        $actual = array_fill_keys(array_filter($actualTokens), true);
        foreach ($requiredTokens as $token) {
            $normalized = $this->slugToken($token);
            if (isset($actual[$token]) || ($normalized !== '' && isset($actual[$normalized]))) {
                return true;
            }
        }
        return false;
    }

    private function poseCompatibleGroups(string $poseKey): array
    {
        if ($poseKey === '') {
            return [];
        }
        $library = $this->store->getPromptLibrary()['pose_library'] ?? [];
        $pose = is_array($library[$poseKey] ?? null) ? $library[$poseKey] : [];
        return $this->tagList((string) ($pose['compatible_act_groups'] ?? ''));
    }

    private function requiredSecondaryCountForCharacterTemplates(array $segments): int
    {
        $required = 0;
        foreach ($segments as $segment) {
            if (!preg_match_all('/\{\{character_(\d+)\}\}/u', $segment, $matches)) {
                continue;
            }
            foreach ($matches[1] as $number) {
                $slot = (int) $number;
                if ($slot > 1) {
                    $required = max($required, $slot - 1);
                }
            }
        }
        return $required;
    }

    public function layers(array $input, array $nsfwSelection = []): array
    {
        $result = $this->evaluate($input, $nsfwSelection);
        return [
            'lora_director_guard' => $result['guard'],
            'lora_director_warnings' => implode(', ', $result['warnings']),
            'lora_director_blocked' => implode(', ', $result['blocked']),
        ];
    }

    private function actGroupFor(string $actKey): string
    {
        if ($actKey === '') {
            return '';
        }
        $director = $this->store->getPromptLibrary()['nsfw_director'] ?? [];
        $act = is_array($director['acts'][$actKey] ?? null) ? $director['acts'][$actKey] : [];
        return mb_strtolower(trim((string) ($act['act_group'] ?? '')));
    }

    private function equivalentActs(string $actKey): array
    {
        return match ($actKey) {
            'tit_fuck', 'tit_fuck_full_body' => ['tit_fuck', 'tit_fuck_full_body'],
            default => $actKey === '' ? [] : [$actKey],
        };
    }

    private function addIssue(array &$issues, array &$messages, string $code, string $severity, string $message, string $suggestion = ''): void
    {
        $issue = ['code' => $code, 'severity' => $severity, 'message' => $message];
        if ($suggestion !== '') {
            $issue['suggestion'] = $suggestion;
        }
        $issues[] = $issue;
        $messages[] = $message;
    }

    private function tagList(string $tags): array
    {
        return array_values(array_filter(array_map(static fn (string $tag): string => mb_strtolower(trim($tag)), explode(',', $tags)), static fn (string $tag): bool => $tag !== ''));
    }

    private function variantClothingPolicy(string $policy): string
    {
        $policy = mb_strtolower(trim($policy));
        return in_array($policy, ['incidental', 'required', 'override', 'forbidden'], true) ? $policy : 'incidental';
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

    private function looksSexualText(string $text): bool
    {
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', mb_strtolower($text)) ?: '';
        $tokens = array_values(array_filter(explode(' ', $normalized), static fn (string $token): bool => $token !== ''));
        $exact = array_fill_keys($tokens, true);
        foreach (['sex', 'oral', 'fellatio', 'cunnilingus', 'blowjob', 'deepthroat', 'handjob', 'facesitting', 'paizuri', 'penetration', 'anal', 'dildo', 'vibrator', 'hardcore', 'cowgirl', 'doggy'] as $keyword) {
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

    private function looksEffectText(string $text): bool
    {
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', mb_strtolower($text)) ?: '';
        $tokens = array_values(array_filter(explode(' ', $normalized), static fn (string $token): bool => $token !== ''));
        $exact = array_fill_keys($tokens, true);
        foreach (['creampie', 'cum', 'facial', 'fluid', 'swapping', 'bukkake'] as $keyword) {
            if (isset($exact[$keyword])) {
                return true;
            }
        }
        return false;
    }

    private function joinSegments(array $segments): string
    {
        return implode(', ', array_values(array_filter(array_map('trim', $segments), static fn (string $segment): bool => $segment !== '')));
    }

    private function uniqueTagText(array $segments): string
    {
        $seen = [];
        $clean = [];
        foreach (explode(',', $this->joinSegments($segments)) as $tag) {
            $tag = trim($tag);
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
}
