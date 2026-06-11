<?php

declare(strict_types=1);

final class ClothingTagClassifier
{
    private const CLOTHING_TERMS = [
        'apron',
        'armor',
        'bikini',
        'bodysuit',
        'boots',
        'bra',
        'bunny suit',
        'cape',
        'coat',
        'collar',
        'collared shirt',
        'corset',
        'dress',
        'garter',
        'garterbelt',
        'gloves',
        'hat',
        'hood',
        'jacket',
        'kimono',
        'leotard',
        'lingerie',
        'long sleeves',
        'necktie',
        'panties',
        'pantyhose',
        'pants',
        'robe',
        'school uniform',
        'shirt',
        'shoes',
        'shorts',
        'skirt',
        'socks',
        'stockings',
        'suit',
        'sweater',
        'swimsuit',
        'thighhighs',
        'tights',
        'underwear',
        'uniform',
        'vest',
        'yukata',
    ];

    private const CLOTHING_INTERACTIONS = [
        'belt bra',
        'clothes lift',
        'clothes lifted',
        'clothes pull',
        'clothes pulled aside',
        'clothed',
        'crotchless',
        'open clothes',
        'open jacket',
        'open shirt',
        'panties aside',
        'panties pulled aside',
        'pantyhose pull',
        'shirt lift',
        'skirt lift',
        'torn clothes',
        'wet clothes',
    ];

    public function clothingTerms(): array
    {
        return array_values(array_unique(array_merge(self::CLOTHING_TERMS, self::CLOTHING_INTERACTIONS)));
    }

    public function detect(string $tags): array
    {
        $matches = [];
        foreach ($this->splitTags($tags) as $tag) {
            if ($this->isClothingTag($tag)) {
                $matches[] = $tag;
            }
        }
        return array_values(array_unique($matches));
    }

    public function filter(string $tags, string $explicitClothingTags = ''): array
    {
        $blocked = array_values(array_unique(array_merge($this->clothingTerms(), $this->splitTags($explicitClothingTags))));
        $kept = [];
        $removed = [];

        foreach ($this->splitTags($tags) as $tag) {
            if ($this->tagMatchesAny($tag, $blocked)) {
                $removed[] = $tag;
                continue;
            }
            $kept[] = $tag;
        }

        return [
            'kept' => $kept,
            'removed' => $removed,
            'kept_tags' => implode(', ', $kept),
            'removed_tags' => implode(', ', $removed),
        ];
    }

    public function negativeFor(string $tags = ''): string
    {
        $detected = $this->detect($tags);
        $base = ['clothes', 'clothing', 'fully clothed', 'covered body'];
        return implode(', ', array_values(array_unique(array_merge($base, $detected))));
    }

    private function isClothingTag(string $tag): bool
    {
        return $this->tagMatchesAny($tag, $this->clothingTerms());
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

    private function splitTags(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags)), static fn (string $tag): bool => $tag !== ''));
    }
}
