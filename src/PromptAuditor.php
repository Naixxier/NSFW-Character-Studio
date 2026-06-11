<?php

declare(strict_types=1);

final class PromptAuditor
{
    public function __construct(private PromptComposer $composer, private Store $store)
    {
    }

    public function audit(array $input): array
    {
        $issues = [];
        $composed = $this->composer->compose($input);
        $prompt = mb_strtolower((string) ($composed['prompt'] ?? ''));
        $negative = mb_strtolower((string) ($composed['negative_prompt'] ?? ''));
        $layers = is_array($composed['layers'] ?? null) ? $composed['layers'] : [];

        $this->auditComposition($prompt, $negative, $layers, $issues);
        $this->auditGlobalPreset((string) ($layers['global'] ?? ''), $issues);
        $this->auditHiresQualityRiskTags($prompt, $layers, $issues);
        $this->auditLoras($input, $issues, is_array($composed['nsfw_director'] ?? null) ? $composed['nsfw_director'] : []);
        $this->auditClothingOverride($layers, $issues);
        $this->auditLoraVariantClothing($layers, $issues);
        $this->auditLoraVariantCameraLock($layers, $issues);
        $this->auditSemanticDuplicates($layers, $issues);

        if (trim((string) ($layers['pose_blocked'] ?? '')) !== '') {
            $issues[] = [
                'code' => 'pose.blocked_by_strict_act',
                'severity' => 'warning',
                'message' => (string) $layers['pose_blocked'],
            ];
        }
        if (trim((string) ($layers['nsfw_effects_blocked'] ?? '')) !== '') {
            $issues[] = [
                'code' => 'nsfw_effect.blocked_by_strict_act',
                'severity' => 'error',
                'message' => (string) $layers['nsfw_effects_blocked'],
                'suggestion' => 'Remove the incompatible effect or choose a matching Director act.',
            ];
        }
        if (trim((string) ($layers['nsfw_effects_warning'] ?? '')) !== '') {
            $issues[] = [
                'code' => 'nsfw_effect.scene_intent_warning',
                'severity' => 'warning',
                'message' => (string) $layers['nsfw_effects_warning'],
            ];
        }

        return [
            'ok' => count(array_filter($issues, static fn (array $issue): bool => ($issue['severity'] ?? '') === 'error')) === 0,
            'summary' => count($issues) === 0 ? 'Prompt audit passed.' : 'Prompt audit found ' . count($issues) . ' issue(s).',
            'issues' => $issues,
            'layers' => $layers,
        ];
    }

    private function auditComposition(string $prompt, string $negative, array $layers, array &$issues): void
    {
        $hasFullBody = $this->containsAny($prompt, ['full body', 'full-length body', 'head-to-toe framing']);
        if (!$hasFullBody) {
            return;
        }

        foreach (['upper body', 'portrait crop', 'close-up', 'closeup', 'cropped body', 'cropped legs', 'cropped feet'] as $tag) {
            if (str_contains($prompt, $tag)) {
                $mitigated = str_contains($negative, $tag);
                $sources = $this->layerSourcesForTag($layers, $tag);
                $sourceText = $sources !== [] ? ' Source layer(s): ' . implode(', ', $sources) . '.' : '';
                $strictActSource = in_array('nsfw_strict_act', $sources, true) || in_array('nsfw_act', $sources, true);
                $issues[] = [
                    'code' => 'composition.conflict',
                    'severity' => 'warning',
                    'message' => "Full body prompt also contains '{$tag}'" . ($mitigated ? ' and the negative guard is trying to compensate.' : '.') . $sourceText,
                    'suggestion' => $strictActSource
                        ? "This comes from the selected Strict Act. Use a closer composition for that act, disable Strict Act, or choose an act/pose that supports Full Body."
                        : "Remove '{$tag}' from the listed layer when Full Body is selected.",
                ];
            }
        }
    }

    private function layerSourcesForTag(array $layers, string $tag): array
    {
        $sources = [];
        foreach ($layers as $name => $value) {
            if (!is_string($name) || str_contains($name, 'negative')) {
                continue;
            }
            $text = mb_strtolower($this->layerText($value));
            if ($text !== '' && str_contains($text, $tag)) {
                $sources[] = $name;
            }
        }
        return array_values(array_unique($sources));
    }

    private function layerText(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => $this->layerText($item), $value));
        }
        return '';
    }

    private function auditGlobalPreset(string $globalPrompt, array &$issues): void
    {
        $global = mb_strtolower($globalPrompt);
        foreach (['cowgirl pose', 'squat', 'squatting', 'feet visible in foreground', 'from below'] as $tag) {
            if (str_contains($global, $tag)) {
                $issues[] = [
                    'code' => 'global.pose_bias',
                    'severity' => 'warning',
                    'message' => "Global preset contains pose-bias tag '{$tag}'.",
                    'suggestion' => 'Move pose/camera directives to Pose Direction or Composition chips.',
                ];
            }
        }
    }

    private function auditHiresQualityRiskTags(string $prompt, array $layers, array &$issues): void
    {
        $riskTags = ['motion blur', 'motion lines', 'speed lines', 'action lines', 'clean lineart', 'lineart', 'anime screencap'];
        foreach ($riskTags as $tag) {
            if (!str_contains($prompt, $tag)) {
                continue;
            }
            $sources = $this->layerSourcesForTag($layers, $tag);
            $sourceText = $sources !== [] ? ' Source layer(s): ' . implode(', ', $sources) . '.' : '';
            $issues[] = [
                'code' => 'hires.quality_risk_tag',
                'severity' => 'warning',
                'message' => "Prompt contains '{$tag}', which can create motion strokes or heavy line texture in Hires.fix." . $sourceText,
                'suggestion' => 'Remove it from the listed layer or keep Fine Detail active so the second pass strips/negativizes it.',
            ];
        }
    }

    private function auditLoras(array $input, array &$issues, array $nsfwSelection): void
    {
        $result = (new LoraDirectorGuard($this->store))->evaluate($input, $nsfwSelection);
        foreach ($result['issues'] as $issue) {
            $issues[] = $issue;
        }
    }

    private function auditClothingOverride(array $layers, array &$issues): void
    {
        $removed = trim((string) ($layers['clothing_override_removed'] ?? ''));
        if ($removed === '') {
            return;
        }

        $issues[] = [
            'code' => 'clothing.override_applied',
            'severity' => 'warning',
            'message' => 'Clothing override removed outfit tags: ' . $removed,
            'suggestion' => 'This helps the selected clothing state dominate the outfit instead of fighting it.',
        ];
    }

    private function auditLoraVariantClothing(array $layers, array &$issues): void
    {
        $warning = trim((string) ($layers['lora_variant_clothing_warning'] ?? ''));
        if ($warning !== '') {
            $issues[] = [
                'code' => 'lora.variant_clothing_policy',
                'severity' => 'warning',
                'message' => $warning,
                'suggestion' => 'Use required/override policy only when the clothing is part of the pose. Keep incidental for reference-only clothing.',
            ];
        }
    }

    private function auditLoraVariantCameraLock(array $layers, array &$issues): void
    {
        $removed = trim((string) ($layers['lora_variant_camera_removed'] ?? ''));
        if ($removed === '') {
            return;
        }

        $lock = mb_strtolower(trim((string) ($layers['lora_variant_camera_lock'] ?? 'variant camera')));
        $label = str_contains($lock, 'pov') ? 'POV' : (str_contains($lock, 'side view') ? 'side view' : 'variant camera');
        $issues[] = [
            'code' => 'lora.variant_camera_lock',
            'severity' => 'warning',
            'message' => "LoRA variant {$label} lock removed conflicting camera/composition tags: {$removed}",
            'suggestion' => 'The selected variant has a stronger camera direction than the Composition chips, so the prompt was auto-corrected.',
        ];
    }

    private function auditSemanticDuplicates(array $layers, array &$issues): void
    {
        $counts = [];
        foreach ($layers as $key => $value) {
            if (in_array($key, ['composition_negative_guard', 'clothing_override_negative', 'lora_conflict_negatives'], true)) {
                continue;
            }
            foreach ($this->splitTags((string) $value) as $tag) {
                $group = $this->semanticTagGroup($tag);
                if ($group === null) {
                    continue;
                }
                $counts[$group] ??= [];
                $counts[$group][] = $tag;
            }
        }

        foreach ($counts as $group => $tags) {
            if (count($tags) <= 2) {
                continue;
            }
            $issues[] = [
                'code' => 'prompt.semantic_duplicate',
                'severity' => 'warning',
                'message' => "Prompt repeats {$group} intent " . count($tags) . ' times.',
                'suggestion' => 'Final prompt is compacted automatically, but consider removing duplicate direction chips.',
            ];
        }
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

    private function splitTags(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags)), static fn (string $tag): bool => $tag !== ''));
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
}
