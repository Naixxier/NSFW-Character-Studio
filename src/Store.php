<?php

declare(strict_types=1);

final class Store
{
    private const CHARACTER_STUDIO_SEED_VERSION = 2;
    private const ADULT_FRAMING_TAGS = '';
    private const OLD_ANATOMY_POSITIVE = 'anatomically correct hands, five fingers, detailed hands, detailed feet, correct toes, natural navel, single navel, coherent body anatomy';
    private const OLD_ANATOMY_NEGATIVE = 'bad hands, malformed hands, extra fingers, missing fingers, fused fingers, bad feet, malformed feet, extra toes, missing toes, fused toes, extra navel, double navel, misplaced navel, duplicate belly button, extra limbs, fused limbs, deformed anatomy, mutated hands, broken fingers';
    private const V2_ANATOMY_POSITIVE = 'anatomically coherent body, single centered navel, natural abdomen, normal feet, five toes per foot, correct toe count, detailed feet, detailed hands, five fingers per hand';
    private const V2_ANATOMY_NEGATIVE = 'double navel, duplicate navel, multiple navels, extra belly button, misplaced navel, navel on chest, duplicated abdomen detail, extra toes, six toes, more than five toes, missing toes, fused toes, duplicated toes, malformed toes, bad toenails, extra feet, bad feet, malformed feet, extra fingers, missing fingers, fused fingers, extra limbs, deformed anatomy';
    private const ABDOMEN_GUARD = 'single centered navel, natural abdomen';
    private const HANDS_GUARD = 'detailed hands, five fingers per hand';
    private const FEET_GUARD = 'normal feet, detailed feet';
    private const FEET_SAFE_CROP = 'feet out of focus, feet partially out of frame';
    private const ANATOMY_POSITIVE = 'single centered navel, natural abdomen, detailed hands, five fingers per hand, normal feet, detailed feet';
    private const ANATOMY_NEGATIVE = 'double navel, duplicate navel, multiple navels, extra belly button, misplaced navel, navel on chest, duplicated abdomen detail, extra toes, six toes, seven toes, too many toes, more than five toes, missing toes, fused toes, duplicated toes, malformed toes, bad toenails, extra feet, bad feet, malformed feet, detailed toes, toe focus, foot close-up, extra fingers, missing fingers, fused fingers, extra limbs, deformed anatomy';
    private const HARD_NSFW_NEUTRAL_TAGS = '(nsfw:1.5), (explicit:1.25), (uncensored:1.3), mature erotic scene, intense sensual mood, adult-only presentation';
    private const CLEAN_HIRES_STYLE_POSITIVE = 'smooth anime rendering, polished anime shading, soft detailed shading, smooth gradients, clean color fill, detailed skin texture, detailed fingernails, delicate fingers, natural highlights';
    private const CLEAN_HIRES_STYLE_NEGATIVE = 'motion lines, speed lines, action lines, sketch lines, rough lineart, thick outlines, scratchy lines, noisy outlines, smeared details, oversharpened edges, cel shading artifacts, blocky shading, unfinished hands, undetailed fingernails';
    private const CLEAN_HIRES_STRIP_TAGS = 'clean lineart, lineart, anime screencap, screencap, motion blur, motion lines, speed lines, action lines, dynamic motion, fine motion';
    private const PREMIUM_ANIME_XL_CLEAN = 'masterpiece, best quality, newest, highres, absurdres, detailed eyes, cinematic lighting';

    private PDO $pdo;
    private string $driver;

    public function __construct()
    {
        if (!is_dir(Config::dataDir())) {
            mkdir(Config::dataDir(), 0777, true);
        }
        if (!is_dir($this->generatedDir())) {
            mkdir($this->generatedDir(), 0777, true);
        }

        $this->driver = Config::dbDriver();
        if ($this->driver === 'sqlite') {
            $this->pdo = new PDO('sqlite:' . Config::sqlitePath());
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->init();
            return;
        }

        if ($this->driver !== 'pgsql') {
            throw new RuntimeException('Unsupported DB_DRIVER=' . $this->driver);
        }

        $this->pdo = $this->pgPdoFromUrl(Config::databaseUrl());
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->afterSchemaReady(false);
    }

    public function generatedDir(): string
    {
        return Config::dataDir() . '/generated';
    }

    public function loraRefsDir(): string
    {
        return Config::dataDir() . '/lora_refs';
    }

    public function characterRefsDir(): string
    {
        return Config::dataDir() . '/character_refs';
    }

    public function getSettings(): array
    {
        $settings = $this->defaultSettings();
        $rows = $this->pdo->query('SELECT key, value_json FROM settings')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $value = json_decode((string) $row['value_json'], true);
            if (is_array($value) && isset($settings[$row['key']]) && is_array($settings[$row['key']])) {
                $settings[$row['key']] = array_replace_recursive($settings[$row['key']], $value);
            } elseif ($value !== null) {
                $settings[$row['key']] = $value;
            }
        }
        $settings = $this->upgradeHiresAnatomyDefaults($settings);
        unset($settings['sd']['a' . 'detailer']);

        return $settings;
    }

    public function saveSettings(array $settings): array
    {
        $current = $this->getSettings();
        $allowed = array_intersect_key($settings, $current);
        $merged = array_replace_recursive($current, $allowed);
        if (isset($allowed['sd']['hires']['profiles']) && is_array($allowed['sd']['hires']['profiles'])) {
            $merged['sd']['hires']['profiles'] = $allowed['sd']['hires']['profiles'];
        }
        unset($merged['sd']['a' . 'detailer']);

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value_json, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
        );
        foreach ($merged as $key => $value) {
            $stmt->execute([
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_SLASHES),
                'updated_at' => gmdate('c'),
            ]);
        }
        return $this->getSettings();
    }

    public function getPromptLibrary(): array
    {
        $stmt = $this->pdo->prepare('SELECT value_json FROM settings WHERE key = :key');
        $stmt->execute(['key' => 'prompt_library']);
        $raw = $stmt->fetchColumn();
        $stored = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($stored)) {
            return $this->normalizePromptLibrary($this->defaultPromptLibrary());
        }
        return $this->normalizePromptLibrary($stored);
    }

    public function savePromptLibrary(array $library): array
    {
        $normalized = $this->normalizePromptLibrary($library);
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value_json, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'key' => 'prompt_library',
            'value' => json_encode($normalized, JSON_UNESCAPED_SLASHES),
            'updated_at' => gmdate('c'),
        ]);
        return $this->getPromptLibrary();
    }

    public function resetNsfwDirector(): array
    {
        $library = $this->getPromptLibrary();
        $library['nsfw_director'] = $this->normalizePromptLibrary($this->defaultPromptLibrary())['nsfw_director'];
        return $this->savePromptLibrary($library);
    }

    public function resetPoseLibrary(): array
    {
        $library = $this->getPromptLibrary();
        $library['pose_library'] = $this->defaultPoseLibrary();
        return $this->savePromptLibrary($library);
    }

    public function getLoraTriggers(): array
    {
        $rows = $this->pdo->query('SELECT alias, trigger_words, updated_at FROM lora_library ORDER BY LOWER(alias)')->fetchAll(PDO::FETCH_ASSOC);
        $triggers = [];
        foreach ($rows as $row) {
            $triggers[$row['alias']] = [
                'alias' => $row['alias'],
                'trigger_words' => (string) $row['trigger_words'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return $triggers;
    }

    public function getLoraLibrary(): array
    {
        $rows = $this->pdo->query('SELECT * FROM lora_library ORDER BY category, favorite DESC, LOWER(alias)')->fetchAll(PDO::FETCH_ASSOC);
        $variantCounts = $this->loraVariantCounts();
        $referenceCounts = $this->loraReferenceCounts();
        $items = [];
        foreach ($rows as $row) {
            $item = $this->normalizeLoraLibraryRow($row);
            $alias = (string) $row['alias'];
            $item['variant_count'] = (int) ($variantCounts[$alias] ?? 0);
            $item['reference_count'] = (int) ($referenceCounts[$alias] ?? 0);
            $items[$alias] = $item;
        }
        return $items;
    }

    public function getLoraPack(string $alias): array
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new InvalidArgumentException('LoRA alias is required.');
        }
        $library = $this->getLoraLibrary();
        $meta = $library[$alias] ?? [
            'alias' => $alias,
            'name' => $alias,
            'trigger_words' => '',
            'category' => 'uncategorized',
            'default_weight' => 0.8,
            'enabled' => true,
            'favorite' => false,
            'variant_count' => 0,
            'reference_count' => 0,
        ];

        return [
            'lora' => $meta,
            'variants' => $this->getLoraVariants($alias),
            'references' => $this->getLoraReferences($alias),
        ];
    }

    public function getLoraVariants(string $alias): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lora_variants WHERE lora_alias = :alias ORDER BY sort_order, LOWER(label), variant_key');
        $stmt->execute(['alias' => trim($alias)]);
        return array_map([$this, 'decodeLoraVariant'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveLoraVariant(string $alias, array $variant): array
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new InvalidArgumentException('LoRA alias is required.');
        }
        $key = $this->slug((string) ($variant['variant_key'] ?? $variant['key'] ?? $variant['label'] ?? 'default'));
        $label = trim((string) ($variant['label'] ?? $key));
        if ($key === '' || $label === '') {
            throw new InvalidArgumentException('Variant key and label are required.');
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO lora_variants (
                lora_alias, variant_key, label, trigger_words, positive_tags, negative_tags,
                weight_override, compatible_acts, incompatible_acts, act_groups, notes,
                clothing_policy, clothing_tags, clothing_required_tags, strip_clothing_when_outfit_active,
                anonymous_partner_tags, ensemble_tags, requires_secondary_characters,
                min_secondary_characters, max_secondary_characters,
                enabled, sort_order, created_at, updated_at
             ) VALUES (
                :lora_alias, :variant_key, :label, :trigger_words, :positive_tags, :negative_tags,
                :weight_override, :compatible_acts, :incompatible_acts, :act_groups, :notes,
                :clothing_policy, :clothing_tags, :clothing_required_tags, :strip_clothing_when_outfit_active,
                :anonymous_partner_tags, :ensemble_tags, :requires_secondary_characters,
                :min_secondary_characters, :max_secondary_characters,
                :enabled, :sort_order, :created_at, :updated_at
             )
             ON CONFLICT(lora_alias, variant_key) DO UPDATE SET
                label = excluded.label,
                trigger_words = excluded.trigger_words,
                positive_tags = excluded.positive_tags,
                negative_tags = excluded.negative_tags,
                weight_override = excluded.weight_override,
                compatible_acts = excluded.compatible_acts,
                incompatible_acts = excluded.incompatible_acts,
                act_groups = excluded.act_groups,
                notes = excluded.notes,
                clothing_policy = excluded.clothing_policy,
                clothing_tags = excluded.clothing_tags,
                clothing_required_tags = excluded.clothing_required_tags,
                strip_clothing_when_outfit_active = excluded.strip_clothing_when_outfit_active,
                anonymous_partner_tags = excluded.anonymous_partner_tags,
                ensemble_tags = excluded.ensemble_tags,
                requires_secondary_characters = excluded.requires_secondary_characters,
                min_secondary_characters = excluded.min_secondary_characters,
                max_secondary_characters = excluded.max_secondary_characters,
                enabled = excluded.enabled,
                sort_order = excluded.sort_order,
                updated_at = excluded.updated_at'
        );
        $weight = trim((string) ($variant['weight_override'] ?? ''));
        $clothingPolicy = $this->normalizeVariantClothingPolicy((string) ($variant['clothing_policy'] ?? 'incidental'));
        $stmt->execute([
            'lora_alias' => $alias,
            'variant_key' => $key,
            'label' => $label,
            'trigger_words' => trim((string) ($variant['trigger_words'] ?? '')),
            'positive_tags' => trim((string) ($variant['positive_tags'] ?? '')),
            'negative_tags' => trim((string) ($variant['negative_tags'] ?? '')),
            'weight_override' => $weight === '' ? null : $this->normalizeLoraWeight($weight),
            'compatible_acts' => trim((string) ($variant['compatible_acts'] ?? '')),
            'incompatible_acts' => trim((string) ($variant['incompatible_acts'] ?? '')),
            'act_groups' => trim((string) ($variant['act_groups'] ?? '')),
            'notes' => trim((string) ($variant['notes'] ?? '')),
            'clothing_policy' => $clothingPolicy,
            'clothing_tags' => trim((string) ($variant['clothing_tags'] ?? '')),
            'clothing_required_tags' => trim((string) ($variant['clothing_required_tags'] ?? '')),
            'strip_clothing_when_outfit_active' => array_key_exists('strip_clothing_when_outfit_active', $variant) ? (!empty($variant['strip_clothing_when_outfit_active']) ? 1 : 0) : 1,
            'anonymous_partner_tags' => trim((string) ($variant['anonymous_partner_tags'] ?? '')),
            'ensemble_tags' => trim((string) ($variant['ensemble_tags'] ?? '')),
            'requires_secondary_characters' => !empty($variant['requires_secondary_characters']) ? 1 : 0,
            'min_secondary_characters' => max(0, (int) ($variant['min_secondary_characters'] ?? 0)),
            'max_secondary_characters' => max(0, (int) ($variant['max_secondary_characters'] ?? 0)),
            'enabled' => array_key_exists('enabled', $variant) ? (!empty($variant['enabled']) ? 1 : 0) : 1,
            'sort_order' => (int) ($variant['sort_order'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->getLoraPack($alias);
    }

    public function deleteLoraVariant(string $alias, string $variantKey): array
    {
        $alias = trim($alias);
        $variantKey = $this->slug($variantKey);
        if ($alias === '' || $variantKey === '') {
            throw new InvalidArgumentException('LoRA alias and variant key are required.');
        }
        foreach ($this->getLoraReferences($alias, $variantKey) as $reference) {
            $this->deleteLoraReference((int) $reference['id']);
        }
        $stmt = $this->pdo->prepare('DELETE FROM lora_variants WHERE lora_alias = :alias AND variant_key = :variant_key');
        $stmt->execute(['alias' => $alias, 'variant_key' => $variantKey]);
        return $this->getLoraPack($alias);
    }

    public function saveLoraReferenceUpload(string $alias, array $file, string $variantKey = '', string $caption = ''): array
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new InvalidArgumentException('LoRA alias is required.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Reference image upload failed.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Invalid upload source.');
        }
        $mime = (string) (mime_content_type($tmp) ?: '');
        $extensions = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($extensions[$mime])) {
            throw new InvalidArgumentException('Only PNG, JPG, WEBP, and GIF reference images are supported.');
        }
        $variantKey = $variantKey !== '' ? $this->slug($variantKey) : 'default';
        $safeAlias = $this->safePathSegment($alias);
        $targetDir = $this->loraRefsDir() . '/' . $safeAlias . '/' . $variantKey;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Could not create LoRA reference directory.');
        }
        $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $target = $targetDir . '/' . $fileName;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Could not save reference image.');
        }
        [$width, $height] = getimagesize($target) ?: [0, 0];
        $relativePath = 'lora_refs/' . $safeAlias . '/' . $variantKey . '/' . $fileName;
        $stmt = $this->pdo->prepare(
            'INSERT INTO lora_reference_images (
                lora_alias, variant_key, file_path, original_name, mime_type, width, height,
                caption, sort_order, created_at, updated_at
             ) VALUES (
                :lora_alias, :variant_key, :file_path, :original_name, :mime_type, :width, :height,
                :caption, :sort_order, :created_at, :updated_at
             )'
        );
        $stmt->execute([
            'lora_alias' => $alias,
            'variant_key' => $variantKey,
            'file_path' => $relativePath,
            'original_name' => basename((string) ($file['name'] ?? 'reference')),
            'mime_type' => $mime,
            'width' => (int) $width,
            'height' => (int) $height,
            'caption' => trim($caption),
            'sort_order' => 0,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ]);
        return $this->getLoraPack($alias);
    }

    public function deleteLoraReference(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lora_reference_images WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $target = realpath(Config::dataDir() . '/' . (string) $row['file_path']);
        $base = realpath($this->loraRefsDir());
        if ($target && $base && str_starts_with($target, $base) && is_file($target)) {
            @unlink($target);
        }
        $delete = $this->pdo->prepare('DELETE FROM lora_reference_images WHERE id = :id');
        $delete->execute(['id' => $id]);
        return true;
    }

    public function getSeries(): array
    {
        $rows = $this->pdo->query('SELECT * FROM series ORDER BY LOWER(name)')->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'decodeSeries'], $rows);
    }

    public function saveSeries(array $series): array
    {
        $key = $this->slug((string) ($series['key'] ?? $series['name'] ?? ''));
        $name = trim((string) ($series['name'] ?? $key));
        if ($key === '' || $name === '') {
            throw new InvalidArgumentException('Series key and name are required.');
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO series (
                key, name, source_type, description, base_lora_alias, base_lora_weight,
                default_negative, nsfw_default, created_at, updated_at
             ) VALUES (
                :key, :name, :source_type, :description, :base_lora_alias, :base_lora_weight,
                :default_negative, :nsfw_default, :created_at, :updated_at
             )
             ON CONFLICT(key) DO UPDATE SET
                name = excluded.name,
                source_type = excluded.source_type,
                description = excluded.description,
                base_lora_alias = excluded.base_lora_alias,
                base_lora_weight = excluded.base_lora_weight,
                default_negative = excluded.default_negative,
                nsfw_default = excluded.nsfw_default,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'key' => $key,
            'name' => $name,
            'source_type' => trim((string) ($series['source_type'] ?? 'manual')),
            'description' => trim((string) ($series['description'] ?? '')),
            'base_lora_alias' => trim((string) ($series['base_lora_alias'] ?? '')),
            'base_lora_weight' => $this->normalizeLoraWeight($series['base_lora_weight'] ?? 1),
            'default_negative' => trim((string) ($series['default_negative'] ?? '')),
            'nsfw_default' => !empty($series['nsfw_default']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $saved = $this->getSeriesByKey($key);
        return $saved ?? [];
    }

    public function getCharacters(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['series_id'])) {
            $where[] = 'c.series_id = :series_id';
            $params['series_id'] = (int) $filters['series_id'];
        }
        if (!empty($filters['series_key'])) {
            $where[] = 's.key = :series_key';
            $params['series_key'] = (string) $filters['series_key'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(LOWER(c.display_name) LIKE :q OR LOWER(c.full_name) LIKE :q OR LOWER(' . $this->jsonText('c.aliases_json') . ') LIKE :q OR LOWER(s.name) LIKE :q)';
            $params['q'] = '%' . mb_strtolower((string) $filters['q']) . '%';
        }

        $sql = 'SELECT c.*, s.key AS series_key, s.name AS series_name, s.base_lora_alias AS series_base_lora_alias, s.base_lora_weight AS series_base_lora_weight
                FROM characters c
                JOIN series s ON s.id = c.series_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY LOWER(s.name), LOWER(c.display_name)';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return array_map([$this, 'decodeCharacterRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getCharacter(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, s.key AS series_key, s.name AS series_name, s.base_lora_alias AS series_base_lora_alias, s.base_lora_weight AS series_base_lora_weight
             FROM characters c
             JOIN series s ON s.id = c.series_id
             WHERE c.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decodeCharacterRow($row) : null;
    }

    public function saveCharacter(array $character): array
    {
        $seriesId = (int) ($character['series_id'] ?? 0);
        if ($seriesId <= 0 && !empty($character['series_key'])) {
            $series = $this->getSeriesByKey((string) $character['series_key']);
            $seriesId = (int) ($series['id'] ?? 0);
        }
        if ($seriesId <= 0) {
            throw new InvalidArgumentException('A valid series_id or series_key is required.');
        }

        $displayName = trim((string) ($character['display_name'] ?? $character['name'] ?? $character['full_name'] ?? ''));
        $key = $this->slug((string) ($character['key'] ?? $displayName));
        if ($key === '' || $displayName === '') {
            throw new InvalidArgumentException('Character key and display name are required.');
        }

        $existing = $this->findCharacterBySeriesKey($seriesId, $key);
        $previewImage = array_key_exists('preview_image', $character)
            ? trim((string) $character['preview_image'])
            : (string) ($existing['preview_image'] ?? '');

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO characters (
                series_id, key, display_name, full_name, aliases_json, feature_tags,
                adult_framing_tags, base_lora_alias, base_lora_weight, default_negative,
                preview_image, source_type, notes, nsfw_profile_json, created_at, updated_at
             ) VALUES (
                :series_id, :key, :display_name, :full_name, :aliases_json, :feature_tags,
                :adult_framing_tags, :base_lora_alias, :base_lora_weight, :default_negative,
                :preview_image, :source_type, :notes, :nsfw_profile_json, :created_at, :updated_at
             )
             ON CONFLICT(series_id, key) DO UPDATE SET
                display_name = excluded.display_name,
                full_name = excluded.full_name,
                aliases_json = excluded.aliases_json,
                feature_tags = excluded.feature_tags,
                adult_framing_tags = excluded.adult_framing_tags,
                base_lora_alias = excluded.base_lora_alias,
                base_lora_weight = excluded.base_lora_weight,
                default_negative = excluded.default_negative,
                preview_image = excluded.preview_image,
                source_type = excluded.source_type,
                notes = excluded.notes,
                nsfw_profile_json = excluded.nsfw_profile_json,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'series_id' => $seriesId,
            'key' => $key,
            'display_name' => $displayName,
            'full_name' => trim((string) ($character['full_name'] ?? $displayName)),
            'aliases_json' => json_encode($this->normalizeStringList($character['aliases'] ?? []), JSON_UNESCAPED_SLASHES),
            'feature_tags' => trim((string) ($character['feature_tags'] ?? '')),
            'adult_framing_tags' => trim((string) ($character['adult_framing_tags'] ?? self::ADULT_FRAMING_TAGS)),
            'base_lora_alias' => trim((string) ($character['base_lora_alias'] ?? '')),
            'base_lora_weight' => $this->normalizeLoraWeight($character['base_lora_weight'] ?? 1),
            'default_negative' => trim((string) ($character['default_negative'] ?? '')),
            'preview_image' => $previewImage,
            'source_type' => trim((string) ($character['source_type'] ?? 'manual')),
            'notes' => trim((string) ($character['notes'] ?? '')),
            'nsfw_profile_json' => json_encode(is_array($character['nsfw_profile'] ?? null) ? $character['nsfw_profile'] : [], JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $saved = $this->findCharacterBySeriesKey($seriesId, $key);
        if (isset($character['outfits']) && is_array($character['outfits']) && $saved) {
            $this->replaceCharacterOutfits((int) $saved['id'], $character['outfits']);
            $saved = $this->getCharacter((int) $saved['id']);
        }
        if (isset($character['appearances']) && is_array($character['appearances']) && $saved) {
            $this->replaceCharacterAppearances((int) $saved['id'], $character['appearances']);
            $saved = $this->getCharacter((int) $saved['id']);
        }
        return $saved ?? [];
    }

    public function saveCharacterPreviewUpload(int $characterId, array $file): array
    {
        $character = $this->getCharacter($characterId);
        if (!$character) {
            throw new InvalidArgumentException('Character not found.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Invalid upload source.');
        }
        $mime = (string) (mime_content_type($tmp) ?: '');
        $extensions = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($extensions[$mime])) {
            throw new InvalidArgumentException('Only PNG, JPG, WEBP, and GIF character images are supported.');
        }

        $seriesKey = $this->safePathSegment((string) ($character['series_key'] ?? 'series'));
        $characterKey = $this->safePathSegment((string) ($character['key'] ?? $character['name'] ?? 'character'));
        $targetDir = $this->characterRefsDir() . '/' . $seriesKey . '/' . $characterKey;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Could not create character reference directory.');
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $target = $targetDir . '/' . $fileName;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Could not save character preview image.');
        }

        $oldPath = (string) ($character['preview_image'] ?? '');
        if ($oldPath !== '' && str_starts_with($oldPath, 'character_refs/')) {
            $oldTarget = realpath(Config::dataDir() . '/' . $oldPath);
            $base = realpath($this->characterRefsDir());
            if ($oldTarget && $base && str_starts_with($oldTarget, $base) && is_file($oldTarget)) {
                @unlink($oldTarget);
            }
        }

        $relativePath = 'character_refs/' . $seriesKey . '/' . $characterKey . '/' . $fileName;
        $stmt = $this->pdo->prepare('UPDATE characters SET preview_image = :preview_image, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'preview_image' => $relativePath,
            'updated_at' => gmdate('c'),
            'id' => $characterId,
        ]);

        return [
            'item' => $this->getCharacter($characterId),
            'items' => $this->getCharacters(),
        ];
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Character preview image is too large for the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'Character preview image upload was incomplete.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded preview image.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the preview image upload.',
            UPLOAD_ERR_NO_FILE => 'Character preview image is required.',
            default => 'Character preview image upload failed.',
        };
    }

    public function seedDefaultCharacters(): array
    {
        $this->seedCharacterStudioDefaults();
        return ['series' => $this->getSeries(), 'characters' => $this->getCharacters()];
    }

    public function syncUmaCatalog(array $catalog): void
    {
        $series = $this->saveSeries([
            'key' => 'umamusume',
            'name' => 'Umamusume',
            'source_type' => 'ultima',
            'description' => 'ULTIMA Umamusume catalog.',
            'base_lora_alias' => Config::umaBaseLoraAlias(),
            'base_lora_weight' => Config::UMA_BASE_LORA_WEIGHT,
            'nsfw_default' => true,
        ]);
        $seriesId = (int) ($series['id'] ?? 0);
        if ($seriesId <= 0) {
            return;
        }
        foreach (($catalog['characters'] ?? []) as $uma) {
            if (!is_array($uma) || trim((string) ($uma['name'] ?? '')) === '') {
                continue;
            }
            $saved = $this->saveCharacter([
                'series_id' => $seriesId,
                'key' => (string) $uma['name'],
                'display_name' => (string) $uma['name'],
                'full_name' => (string) $uma['name'],
                'feature_tags' => $this->sanitizeUmaFeatureTags((string) $uma['name'], (string) ($uma['feature_tags'] ?? '')),
                'adult_framing_tags' => self::ADULT_FRAMING_TAGS,
                'base_lora_alias' => Config::umaBaseLoraAlias(),
                'base_lora_weight' => Config::UMA_BASE_LORA_WEIGHT,
                'source_type' => 'ultima',
                'notes' => 'Imported from ULTIMA README.',
            ]);
            $this->replaceCharacterOutfits((int) ($saved['id'] ?? 0), is_array($uma['outfits'] ?? null) ? $uma['outfits'] : []);
        }
    }

    public function saveLoraLibrary(array $payload): array
    {
        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [$payload];
        $stmt = $this->pdo->prepare(
            'INSERT INTO lora_library (
                alias, name, trigger_words, category, default_weight, conflict_groups,
                conflict_negatives, compatible_series, compatible_characters, compatible_acts,
                incompatible_acts, act_groups, requires_outfit_none, scene_intent_hint,
                nsfw_effect_groups, needs_trigger, requires_secondary_characters,
                min_secondary_characters, max_secondary_characters, anonymous_partner_tags,
                ensemble_tags, enabled, favorite, notes,
                created_at, updated_at
             ) VALUES (
                :alias, :name, :trigger_words, :category, :default_weight, :conflict_groups,
                :conflict_negatives, :compatible_series, :compatible_characters, :compatible_acts,
                :incompatible_acts, :act_groups, :requires_outfit_none, :scene_intent_hint,
                :nsfw_effect_groups, :needs_trigger, :requires_secondary_characters,
                :min_secondary_characters, :max_secondary_characters, :anonymous_partner_tags,
                :ensemble_tags, :enabled, :favorite, :notes,
                :created_at, :updated_at
             )
             ON CONFLICT(alias) DO UPDATE SET
                name = excluded.name,
                trigger_words = excluded.trigger_words,
                category = excluded.category,
                default_weight = excluded.default_weight,
                conflict_groups = excluded.conflict_groups,
                conflict_negatives = excluded.conflict_negatives,
                compatible_series = excluded.compatible_series,
                compatible_characters = excluded.compatible_characters,
                compatible_acts = excluded.compatible_acts,
                incompatible_acts = excluded.incompatible_acts,
                act_groups = excluded.act_groups,
                requires_outfit_none = excluded.requires_outfit_none,
                scene_intent_hint = excluded.scene_intent_hint,
                nsfw_effect_groups = excluded.nsfw_effect_groups,
                needs_trigger = excluded.needs_trigger,
                requires_secondary_characters = excluded.requires_secondary_characters,
                min_secondary_characters = excluded.min_secondary_characters,
                max_secondary_characters = excluded.max_secondary_characters,
                anonymous_partner_tags = excluded.anonymous_partner_tags,
                ensemble_tags = excluded.ensemble_tags,
                enabled = excluded.enabled,
                favorite = excluded.favorite,
                notes = excluded.notes,
                updated_at = excluded.updated_at'
        );

        $now = gmdate('c');
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $alias = trim((string) ($item['alias'] ?? $item['name'] ?? ''));
            if ($alias === '') {
                continue;
            }
            $stmt->execute([
                'alias' => $alias,
                'name' => trim((string) ($item['name'] ?? $alias)),
                'trigger_words' => trim((string) ($item['trigger_words'] ?? '')),
                'category' => $this->normalizeLoraCategory((string) ($item['category'] ?? 'uncategorized')),
                'default_weight' => $this->normalizeLoraWeight($item['default_weight'] ?? $item['weight'] ?? 0.8),
                'conflict_groups' => trim((string) ($item['conflict_groups'] ?? '')),
                'conflict_negatives' => trim((string) ($item['conflict_negatives'] ?? '')),
                'compatible_series' => trim((string) ($item['compatible_series'] ?? '')),
                'compatible_characters' => trim((string) ($item['compatible_characters'] ?? '')),
                'compatible_acts' => trim((string) ($item['compatible_acts'] ?? '')),
                'incompatible_acts' => trim((string) ($item['incompatible_acts'] ?? '')),
                'act_groups' => trim((string) ($item['act_groups'] ?? '')),
                'requires_outfit_none' => !empty($item['requires_outfit_none']) ? 1 : 0,
                'scene_intent_hint' => trim((string) ($item['scene_intent_hint'] ?? '')),
                'nsfw_effect_groups' => trim((string) ($item['nsfw_effect_groups'] ?? '')),
                'needs_trigger' => !empty($item['needs_trigger']) ? 1 : 0,
                'requires_secondary_characters' => !empty($item['requires_secondary_characters']) ? 1 : 0,
                'min_secondary_characters' => max(0, (int) ($item['min_secondary_characters'] ?? 0)),
                'max_secondary_characters' => max(0, (int) ($item['max_secondary_characters'] ?? 0)),
                'anonymous_partner_tags' => trim((string) ($item['anonymous_partner_tags'] ?? '')),
                'ensemble_tags' => trim((string) ($item['ensemble_tags'] ?? '')),
                'enabled' => array_key_exists('enabled', $item) ? (!empty($item['enabled']) ? 1 : 0) : 1,
                'favorite' => !empty($item['favorite']) ? 1 : 0,
                'notes' => trim((string) ($item['notes'] ?? '')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->getLoraLibrary();
    }

    public function saveLoraTriggers(array $payload): array
    {
        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [$payload];
        $stmt = $this->pdo->prepare(
            'INSERT INTO lora_triggers (alias, trigger_words, updated_at) VALUES (:alias, :trigger_words, :updated_at)
             ON CONFLICT(alias) DO UPDATE SET trigger_words = excluded.trigger_words, updated_at = excluded.updated_at'
        );
        $libraryStmt = $this->pdo->prepare(
            'INSERT INTO lora_library (alias, name, trigger_words, category, default_weight, conflict_groups, conflict_negatives, enabled, favorite, notes, created_at, updated_at)
             VALUES (:alias, :name, :trigger_words, \'uncategorized\', 0.8, \'\', \'\', 1, 0, \'\', :created_at, :updated_at)
             ON CONFLICT(alias) DO UPDATE SET trigger_words = excluded.trigger_words, updated_at = excluded.updated_at'
        );

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $alias = trim((string) ($item['alias'] ?? ''));
            if ($alias === '') {
                continue;
            }
            $triggerWords = trim((string) ($item['trigger_words'] ?? ''));
            $now = gmdate('c');
            $stmt->execute([
                'alias' => $alias,
                'trigger_words' => $triggerWords,
                'updated_at' => $now,
            ]);
            $libraryStmt->execute([
                'alias' => $alias,
                'name' => trim((string) ($item['name'] ?? $alias)),
                'trigger_words' => $triggerWords,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->getLoraTriggers();
    }

    public function getPresets(?string $type = null): array
    {
        if ($type !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM presets WHERE type = :type ORDER BY LOWER(name)');
            $stmt->execute(['type' => $type]);
        } else {
            $stmt = $this->pdo->query('SELECT * FROM presets ORDER BY type, LOWER(name)');
        }

        return array_map([$this, 'decodePreset'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function savePreset(array $preset): array
    {
        $type = trim((string) ($preset['type'] ?? ''));
        $name = trim((string) ($preset['name'] ?? ''));
        $content = trim((string) ($preset['content'] ?? ''));
        $meta = $preset['meta'] ?? [];

        if ($type === '' || $name === '') {
            throw new InvalidArgumentException('Preset type and name are required.');
        }

        $now = gmdate('c');
        $id = isset($preset['id']) ? (int) $preset['id'] : 0;
        if ($id > 0) {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM presets WHERE id = :id');
            $exists->execute(['id' => $id]);
            if ((int) $exists->fetchColumn() === 0) {
                throw new RuntimeException('Preset not found.');
            }

            $stmt = $this->pdo->prepare(
                'UPDATE presets SET type = :type, name = :name, content = :content, meta_json = :meta, updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'type' => $type,
                'name' => $name,
                'content' => $content,
                'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO presets (type, name, content, meta_json, created_at, updated_at) VALUES (:type, :name, :content, :meta, :created_at, :updated_at)'
            );
            $stmt->execute([
                'type' => $type,
                'name' => $name,
                'content' => $content,
                'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = $this->lastInsertId('presets');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM presets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $this->decodePreset($stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function deletePreset(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Valid preset id is required.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM presets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Preset not found.');
        }
    }

    public function addHistory(array $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO history (prompt, negative_prompt, payload_json, result_json, created_at) VALUES (:prompt, :negative_prompt, :payload, :result, :created_at)'
        );
        $stmt->execute([
            'prompt' => (string) ($record['prompt'] ?? ''),
            'negative_prompt' => (string) ($record['negative_prompt'] ?? ''),
            'payload' => json_encode($record['payload'] ?? [], JSON_UNESCAPED_SLASHES),
            'result' => json_encode($record['result'] ?? [], JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('c'),
        ]);
    }

    public function recentHistory(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM history ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            $row['payload'] = json_decode((string) $row['payload_json'], true) ?: [];
            $row['result'] = json_decode((string) $row['result_json'], true) ?: [];
            unset($row['payload_json'], $row['result_json']);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function addGalleryItem(array $record): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO gallery (
                uma, outfit, prompt, negative_prompt, payload_json, image_paths_json, seed,
                actual_seed, width, height, parent_gallery_id, operation, series_id, series_name,
                character_id, character_name, base_lora, source_type, created_at
             )
             VALUES (
                :uma, :outfit, :prompt, :negative_prompt, :payload, :paths, :seed,
                :actual_seed, :width, :height, :parent_gallery_id, :operation, :series_id, :series_name,
                :character_id, :character_name, :base_lora, :source_type, :created_at
             )'
        );
        $actualSeed = (int) ($record['actual_seed'] ?? $record['seed'] ?? -1);
        $stmt->execute([
            'uma' => (string) ($record['uma'] ?? ''),
            'outfit' => (string) ($record['outfit'] ?? ''),
            'prompt' => (string) ($record['prompt'] ?? ''),
            'negative_prompt' => (string) ($record['negative_prompt'] ?? ''),
            'payload' => json_encode($record['payload'] ?? [], JSON_UNESCAPED_SLASHES),
            'paths' => json_encode($record['image_paths'] ?? [], JSON_UNESCAPED_SLASHES),
            'seed' => (int) ($record['seed'] ?? -1),
            'actual_seed' => $actualSeed,
            'width' => (int) ($record['width'] ?? 0),
            'height' => (int) ($record['height'] ?? 0),
            'parent_gallery_id' => isset($record['parent_gallery_id']) ? (int) $record['parent_gallery_id'] : null,
            'operation' => (string) ($record['operation'] ?? 'generate'),
            'series_id' => (int) ($record['series_id'] ?? 0),
            'series_name' => (string) ($record['series_name'] ?? ''),
            'character_id' => (int) ($record['character_id'] ?? 0),
            'character_name' => (string) ($record['character_name'] ?? ($record['uma'] ?? '')),
            'base_lora' => (string) ($record['base_lora'] ?? ''),
            'source_type' => (string) ($record['source_type'] ?? ''),
            'created_at' => gmdate('c'),
        ]);
        return $this->getGalleryItem($this->lastInsertId('gallery')) ?? [];
    }

    public function getGallery(array $filters = [], int $limit = 80): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['uma'])) {
            $where[] = '(uma = :uma OR character_name = :uma)';
            $params['uma'] = (string) $filters['uma'];
        }
        if (!empty($filters['character_id'])) {
            $where[] = 'character_id = :character_id';
            $params['character_id'] = (int) $filters['character_id'];
        }
        if (!empty($filters['series_id'])) {
            $where[] = 'series_id = :series_id';
            $params['series_id'] = (int) $filters['series_id'];
        }
        if (!empty($filters['operation'])) {
            $where[] = 'operation = :operation';
            $params['operation'] = (string) $filters['operation'];
        }
        $sql = 'SELECT * FROM gallery' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'decodeGalleryItem'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getGalleryItem(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gallery WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decodeGalleryItem($row) : null;
    }

    public function createJob(array $record): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO generation_jobs (type, status, payload_json, progress, error, result_json, created_at, started_at, finished_at)
             VALUES (:type, :status, :payload, :progress, :error, :result, :created_at, :started_at, :finished_at)'
        );
        $stmt->execute([
            'type' => (string) ($record['type'] ?? 'generate'),
            'status' => (string) ($record['status'] ?? 'queued'),
            'payload' => json_encode($record['payload'] ?? [], JSON_UNESCAPED_SLASHES),
            'progress' => (float) ($record['progress'] ?? 0),
            'error' => (string) ($record['error'] ?? ''),
            'result' => json_encode($record['result'] ?? [], JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('c'),
            'started_at' => $record['started_at'] ?? null,
            'finished_at' => $record['finished_at'] ?? null,
        ]);
        return $this->getJob($this->lastInsertId('generation_jobs')) ?? [];
    }

    public function updateJob(int $id, array $changes): array
    {
        if ($id <= 0) {
            return [];
        }

        $allowed = [
            'status' => 'status',
            'payload' => 'payload_json',
            'progress' => 'progress',
            'error' => 'error',
            'result' => 'result_json',
            'started_at' => 'started_at',
            'finished_at' => 'finished_at',
        ];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $inputKey => $column) {
            if (!array_key_exists($inputKey, $changes)) {
                continue;
            }
            $sets[] = $column . ' = :' . $inputKey;
            $value = $changes[$inputKey];
            if (in_array($inputKey, ['payload', 'result'], true)) {
                $value = json_encode($value ?? [], JSON_UNESCAPED_SLASHES);
            }
            $params[$inputKey] = $value;
        }

        if ($sets) {
            $stmt = $this->pdo->prepare('UPDATE generation_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->execute($params);
        }

        return $this->getJob($id) ?? [];
    }

    public function getJobs(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM generation_jobs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'decodeJob'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getJob(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM generation_jobs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decodeJob($row) : null;
    }

    public function cancelJob(int $id): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE generation_jobs SET status = 'failed', error = :error, finished_at = :finished_at WHERE id = :id AND status IN ('queued', 'running')"
        );
        $stmt->execute([
            'id' => $id,
            'error' => 'Cancelled by user.',
            'finished_at' => gmdate('c'),
        ]);
        return $this->getJob($id) ?? [];
    }

    private function pgPdoFromUrl(string $url): PDO
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'pgsql') {
            throw new RuntimeException('DATABASE_URL must use pgsql:// for DB_DRIVER=pgsql.');
        }

        $host = $parts['host'] ?? 'localhost';
        $port = (int) ($parts['port'] ?? 5432);
        $db = ltrim((string) ($parts['path'] ?? ''), '/');
        $user = rawurldecode((string) ($parts['user'] ?? ''));
        $pass = rawurldecode((string) ($parts['pass'] ?? ''));
        if ($db === '') {
            throw new RuntimeException('DATABASE_URL must include a database name.');
        }

        return new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass);
    }

    private function lastInsertId(string $table): int
    {
        if ($this->driver === 'pgsql') {
            return (int) $this->pdo->lastInsertId($table . '_id_seq');
        }
        return (int) $this->pdo->lastInsertId();
    }

    private function jsonText(string $column): string
    {
        return $this->driver === 'pgsql' ? $column . '::text' : $column;
    }

    private function init(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS presets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                content TEXT NOT NULL DEFAULT "",
                meta_json TEXT NOT NULL DEFAULT "{}",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                prompt TEXT NOT NULL,
                negative_prompt TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                result_json TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value_json TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS lora_triggers (
                alias TEXT PRIMARY KEY,
                trigger_words TEXT NOT NULL DEFAULT "",
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS lora_library (
                alias TEXT PRIMARY KEY,
                name TEXT NOT NULL DEFAULT "",
                trigger_words TEXT NOT NULL DEFAULT "",
                category TEXT NOT NULL DEFAULT "uncategorized",
                default_weight REAL NOT NULL DEFAULT 0.8,
                conflict_groups TEXT NOT NULL DEFAULT "",
                conflict_negatives TEXT NOT NULL DEFAULT "",
                enabled INTEGER NOT NULL DEFAULT 1,
                favorite INTEGER NOT NULL DEFAULT 0,
                notes TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS series (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                source_type TEXT NOT NULL DEFAULT "manual",
                description TEXT NOT NULL DEFAULT "",
                base_lora_alias TEXT NOT NULL DEFAULT "",
                base_lora_weight REAL NOT NULL DEFAULT 1,
                default_negative TEXT NOT NULL DEFAULT "",
                nsfw_default INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS characters (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                series_id INTEGER NOT NULL,
                key TEXT NOT NULL,
                display_name TEXT NOT NULL,
                full_name TEXT NOT NULL DEFAULT "",
                aliases_json TEXT NOT NULL DEFAULT "[]",
                feature_tags TEXT NOT NULL DEFAULT "",
                adult_framing_tags TEXT NOT NULL DEFAULT "",
                base_lora_alias TEXT NOT NULL DEFAULT "",
                base_lora_weight REAL NOT NULL DEFAULT 1,
                default_negative TEXT NOT NULL DEFAULT "",
                preview_image TEXT NOT NULL DEFAULT "",
                source_type TEXT NOT NULL DEFAULT "manual",
                notes TEXT NOT NULL DEFAULT "",
                nsfw_profile_json TEXT NOT NULL DEFAULT "{}",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(series_id, key)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS character_outfits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                character_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                prompt TEXT NOT NULL DEFAULT "",
                negative_tags TEXT NOT NULL DEFAULT "",
                preview_image TEXT NOT NULL DEFAULT "",
                conflict_groups TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(character_id, name)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS character_appearances (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                character_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                prompt TEXT NOT NULL DEFAULT "",
                negative_tags TEXT NOT NULL DEFAULT "",
                preview_image TEXT NOT NULL DEFAULT "",
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(character_id, name)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS character_loras (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                character_id INTEGER NOT NULL,
                alias TEXT NOT NULL,
                weight REAL NOT NULL DEFAULT 1,
                trigger_words TEXT NOT NULL DEFAULT "",
                role TEXT NOT NULL DEFAULT "base",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(character_id, alias, role)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS gallery (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uma TEXT NOT NULL DEFAULT "",
                outfit TEXT NOT NULL DEFAULT "",
                prompt TEXT NOT NULL,
                negative_prompt TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                image_paths_json TEXT NOT NULL,
                seed INTEGER NOT NULL DEFAULT -1,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS generation_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "queued",
                payload_json TEXT NOT NULL DEFAULT "{}",
                progress REAL NOT NULL DEFAULT 0,
                error TEXT NOT NULL DEFAULT "",
                result_json TEXT NOT NULL DEFAULT "{}",
                created_at TEXT NOT NULL,
                started_at TEXT DEFAULT NULL,
                finished_at TEXT DEFAULT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS image_assets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                gallery_id INTEGER DEFAULT NULL,
                parent_asset_id INTEGER DEFAULT NULL,
                path TEXT NOT NULL,
                width INTEGER NOT NULL DEFAULT 0,
                height INTEGER NOT NULL DEFAULT 0,
                seed INTEGER NOT NULL DEFAULT -1,
                hash TEXT NOT NULL DEFAULT "",
                operation TEXT NOT NULL DEFAULT "generate",
                metadata_json TEXT NOT NULL DEFAULT "{}",
                created_at TEXT NOT NULL
            )'
        );
        $this->ensureLoraPackTables();
        $this->afterSchemaReady(true);
    }

    private function afterSchemaReady(bool $runSqliteMigrations): void
    {
        $this->ensureLoraPackTables();
        $this->ensureLoraLibraryColumns();
        if ($runSqliteMigrations) {
            $this->migrateLoraTriggersToLibrary();
            $this->ensureGalleryColumns();
        }
        $this->ensureCharacterAppearanceTable();
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM presets')->fetchColumn();
        if ($count === 0) {
            $this->seedPresets();
        }
        $settingsCount = (int) $this->pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
        if ($settingsCount === 0) {
            $this->saveSettings($this->defaultSettings());
        }
        $this->upgradeHiresQualityDefaults();
        $this->upgradePromptLibraryDefaults();
        $this->seedCharacterStudioDefaultsOnce();
        $this->upgradeKnownMetadataPatches();
    }

    private function ensureLoraPackTables(): void
    {
        if (!is_dir($this->loraRefsDir())) {
            mkdir($this->loraRefsDir(), 0777, true);
        }

        if ($this->driver === 'pgsql') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS lora_variants (
                    lora_alias TEXT NOT NULL,
                    variant_key TEXT NOT NULL,
                    label TEXT NOT NULL DEFAULT \'\',
                    trigger_words TEXT NOT NULL DEFAULT \'\',
                    positive_tags TEXT NOT NULL DEFAULT \'\',
                    negative_tags TEXT NOT NULL DEFAULT \'\',
                    weight_override DOUBLE PRECISION NULL,
                    compatible_acts TEXT NOT NULL DEFAULT \'\',
                    incompatible_acts TEXT NOT NULL DEFAULT \'\',
                    act_groups TEXT NOT NULL DEFAULT \'\',
                    clothing_policy TEXT NOT NULL DEFAULT \'incidental\',
                    clothing_tags TEXT NOT NULL DEFAULT \'\',
                    clothing_required_tags TEXT NOT NULL DEFAULT \'\',
                    strip_clothing_when_outfit_active BOOLEAN NOT NULL DEFAULT TRUE,
                    anonymous_partner_tags TEXT NOT NULL DEFAULT \'\',
                    ensemble_tags TEXT NOT NULL DEFAULT \'\',
                    requires_secondary_characters BOOLEAN NOT NULL DEFAULT FALSE,
                    min_secondary_characters INTEGER NOT NULL DEFAULT 0,
                    max_secondary_characters INTEGER NOT NULL DEFAULT 0,
                    notes TEXT NOT NULL DEFAULT \'\',
                    enabled BOOLEAN NOT NULL DEFAULT TRUE,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    PRIMARY KEY (lora_alias, variant_key)
                )'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS lora_reference_images (
                    id BIGSERIAL PRIMARY KEY,
                    lora_alias TEXT NOT NULL,
                    variant_key TEXT NOT NULL DEFAULT \'default\',
                    file_path TEXT NOT NULL,
                    original_name TEXT NOT NULL DEFAULT \'\',
                    mime_type TEXT NOT NULL DEFAULT \'\',
                    width INTEGER NOT NULL DEFAULT 0,
                    height INTEGER NOT NULL DEFAULT 0,
                    caption TEXT NOT NULL DEFAULT \'\',
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )'
            );
            $this->ensureLoraVariantColumns();
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS lora_variants (
                lora_alias TEXT NOT NULL,
                variant_key TEXT NOT NULL,
                label TEXT NOT NULL DEFAULT "",
                trigger_words TEXT NOT NULL DEFAULT "",
                positive_tags TEXT NOT NULL DEFAULT "",
                negative_tags TEXT NOT NULL DEFAULT "",
                weight_override REAL DEFAULT NULL,
                compatible_acts TEXT NOT NULL DEFAULT "",
                incompatible_acts TEXT NOT NULL DEFAULT "",
                act_groups TEXT NOT NULL DEFAULT "",
                clothing_policy TEXT NOT NULL DEFAULT "incidental",
                clothing_tags TEXT NOT NULL DEFAULT "",
                clothing_required_tags TEXT NOT NULL DEFAULT "",
                strip_clothing_when_outfit_active INTEGER NOT NULL DEFAULT 1,
                anonymous_partner_tags TEXT NOT NULL DEFAULT "",
                ensemble_tags TEXT NOT NULL DEFAULT "",
                requires_secondary_characters INTEGER NOT NULL DEFAULT 0,
                min_secondary_characters INTEGER NOT NULL DEFAULT 0,
                max_secondary_characters INTEGER NOT NULL DEFAULT 0,
                notes TEXT NOT NULL DEFAULT "",
                enabled INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (lora_alias, variant_key)
            )'
        );
        $this->ensureLoraVariantColumns();
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS lora_reference_images (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lora_alias TEXT NOT NULL,
                variant_key TEXT NOT NULL DEFAULT "default",
                file_path TEXT NOT NULL,
                original_name TEXT NOT NULL DEFAULT "",
                mime_type TEXT NOT NULL DEFAULT "",
                width INTEGER NOT NULL DEFAULT 0,
                height INTEGER NOT NULL DEFAULT 0,
                caption TEXT NOT NULL DEFAULT "",
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
    }

    private function ensureLoraVariantColumns(): void
    {
        $columns = [
            'clothing_policy' => $this->driver === 'pgsql' ? "TEXT NOT NULL DEFAULT 'incidental'" : 'TEXT NOT NULL DEFAULT "incidental"',
            'clothing_tags' => $this->driver === 'pgsql' ? "TEXT NOT NULL DEFAULT ''" : 'TEXT NOT NULL DEFAULT ""',
            'clothing_required_tags' => $this->driver === 'pgsql' ? "TEXT NOT NULL DEFAULT ''" : 'TEXT NOT NULL DEFAULT ""',
            'strip_clothing_when_outfit_active' => $this->driver === 'pgsql' ? 'BOOLEAN NOT NULL DEFAULT TRUE' : 'INTEGER NOT NULL DEFAULT 1',
            'anonymous_partner_tags' => $this->driver === 'pgsql' ? "TEXT NOT NULL DEFAULT ''" : 'TEXT NOT NULL DEFAULT ""',
            'ensemble_tags' => $this->driver === 'pgsql' ? "TEXT NOT NULL DEFAULT ''" : 'TEXT NOT NULL DEFAULT ""',
            'requires_secondary_characters' => $this->driver === 'pgsql' ? 'BOOLEAN NOT NULL DEFAULT FALSE' : 'INTEGER NOT NULL DEFAULT 0',
            'min_secondary_characters' => 'INTEGER NOT NULL DEFAULT 0',
            'max_secondary_characters' => 'INTEGER NOT NULL DEFAULT 0',
        ];

        foreach ($columns as $column => $definition) {
            try {
                if ($this->driver === 'pgsql') {
                    $this->pdo->exec('ALTER TABLE lora_variants ADD COLUMN IF NOT EXISTS ' . $column . ' ' . $definition);
                    continue;
                }
                if (!$this->sqliteColumnExists('lora_variants', $column)) {
                    $this->pdo->exec('ALTER TABLE lora_variants ADD COLUMN ' . $column . ' ' . $definition);
                }
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function ensureCharacterAppearanceTable(): void
    {
        if ($this->driver === 'pgsql') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS character_appearances (
                    id BIGSERIAL PRIMARY KEY,
                    character_id BIGINT NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
                    name TEXT NOT NULL,
                    prompt TEXT NOT NULL DEFAULT \'\',
                    negative_tags TEXT NOT NULL DEFAULT \'\',
                    preview_image TEXT NOT NULL DEFAULT \'\',
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    UNIQUE(character_id, name)
                )'
            );
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS character_appearances (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                character_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                prompt TEXT NOT NULL DEFAULT "",
                negative_tags TEXT NOT NULL DEFAULT "",
                preview_image TEXT NOT NULL DEFAULT "",
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(character_id, name)
            )'
        );
    }

    private function sqliteColumnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    private function seedCharacterStudioDefaultsOnce(): void
    {
        if ($this->isCharacterSeedCurrent()) {
            return;
        }

        if ($this->driver === 'pgsql') {
            $this->pdo->exec('SELECT pg_advisory_lock(49731002)');
            try {
                if (!$this->isCharacterSeedCurrent()) {
                    $this->seedCharacterStudioDefaults();
                    $this->markCharacterSeedCurrent();
                }
            } finally {
                $this->pdo->exec('SELECT pg_advisory_unlock(49731002)');
            }
            return;
        }

        $this->seedCharacterStudioDefaults();
        $this->markCharacterSeedCurrent();
    }

    private function isCharacterSeedCurrent(): bool
    {
        $stmt = $this->pdo->prepare('SELECT value_json FROM settings WHERE key = :key');
        $stmt->execute(['key' => 'character_studio_seed_version']);
        $raw = $stmt->fetchColumn();
        $value = is_string($raw) ? json_decode($raw, true) : null;
        return (int) ($value['version'] ?? 0) >= self::CHARACTER_STUDIO_SEED_VERSION;
    }

    private function markCharacterSeedCurrent(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value_json, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'key' => 'character_studio_seed_version',
            'value' => json_encode(['version' => self::CHARACTER_STUDIO_SEED_VERSION], JSON_UNESCAPED_SLASHES),
            'updated_at' => gmdate('c'),
        ]);
    }

    private function seedPack(): array
    {
        static $pack = null;
        if ($pack === null) {
            $pack = (new SeedPackLoader(Config::packsDir()))->load();
        }
        return $pack;
    }

    private function seedPresets(): void
    {
        $defaults = $this->seedPack()['presets'] ?: [
            ['type' => 'global', 'name' => 'Premium anime XL', 'content' => self::PREMIUM_ANIME_XL_CLEAN],
            ['type' => 'global', 'name' => 'Portrait polish', 'content' => 'solo focus, upper body, expressive eyes, soft rim light, depth of field'],
            ['type' => 'negative', 'name' => 'Anime XL clean negative', 'content' => 'lowres, worst quality, low quality, bad anatomy, bad hands, missing fingers, extra fingers, text, watermark, signature, jpeg artifacts, blurry'],
            ['type' => 'negative', 'name' => 'Strict cleanup', 'content' => 'deformed, mutated, extra limbs, poorly drawn face, poorly drawn hands, malformed hands, cropped, out of frame'],
        ];

        foreach ($defaults as $preset) {
            if (!is_array($preset)) {
                continue;
            }
            $this->savePreset([
                'type' => (string) ($preset['type'] ?? 'global'),
                'name' => (string) ($preset['name'] ?? ''),
                'content' => (string) ($preset['content'] ?? ''),
                'meta' => is_array($preset['meta'] ?? null) ? $preset['meta'] : [],
            ]);
        }
    }

    private function migrateLoraTriggersToLibrary(): void
    {
        $rows = $this->pdo->query('SELECT alias, trigger_words, updated_at FROM lora_triggers')->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO lora_library (alias, name, trigger_words, category, default_weight, conflict_groups, conflict_negatives, enabled, favorite, notes, created_at, updated_at)
             VALUES (:alias, :name, :trigger_words, \'uncategorized\', 0.8, \'\', \'\', 1, 0, \'\', :created_at, :updated_at)
             ON CONFLICT(alias) DO NOTHING'
        );
        foreach ($rows as $row) {
            $updated = (string) ($row['updated_at'] ?? gmdate('c'));
            $stmt->execute([
                'alias' => (string) $row['alias'],
                'name' => (string) $row['alias'],
                'trigger_words' => (string) ($row['trigger_words'] ?? ''),
                'created_at' => $updated,
                'updated_at' => $updated,
            ]);
        }
    }

    private function normalizeLoraLibraryRow(array $row): array
    {
        return [
            'alias' => (string) $row['alias'],
            'name' => (string) ($row['name'] ?: $row['alias']),
            'trigger_words' => (string) ($row['trigger_words'] ?? ''),
            'category' => $this->normalizeLoraCategory((string) ($row['category'] ?? 'uncategorized')),
            'default_weight' => $this->normalizeLoraWeight($row['default_weight'] ?? 0.8),
            'conflict_groups' => (string) ($row['conflict_groups'] ?? ''),
            'conflict_negatives' => (string) ($row['conflict_negatives'] ?? ''),
            'compatible_series' => (string) ($row['compatible_series'] ?? ''),
            'compatible_characters' => (string) ($row['compatible_characters'] ?? ''),
            'compatible_acts' => (string) ($row['compatible_acts'] ?? ''),
            'incompatible_acts' => (string) ($row['incompatible_acts'] ?? ''),
            'act_groups' => (string) ($row['act_groups'] ?? ''),
            'requires_outfit_none' => $this->dbBool($row['requires_outfit_none'] ?? false),
            'scene_intent_hint' => (string) ($row['scene_intent_hint'] ?? ''),
            'nsfw_effect_groups' => (string) ($row['nsfw_effect_groups'] ?? ''),
            'needs_trigger' => $this->dbBool($row['needs_trigger'] ?? false),
            'requires_secondary_characters' => $this->dbBool($row['requires_secondary_characters'] ?? false),
            'min_secondary_characters' => (int) ($row['min_secondary_characters'] ?? 0),
            'max_secondary_characters' => (int) ($row['max_secondary_characters'] ?? 0),
            'anonymous_partner_tags' => (string) ($row['anonymous_partner_tags'] ?? ''),
            'ensemble_tags' => (string) ($row['ensemble_tags'] ?? ''),
            'enabled' => $this->dbBool($row['enabled'] ?? true),
            'favorite' => $this->dbBool($row['favorite'] ?? false),
            'notes' => (string) ($row['notes'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function loraVariantCounts(): array
    {
        $counts = [];
        try {
            $rows = $this->pdo->query('SELECT lora_alias, COUNT(*) AS total FROM lora_variants WHERE enabled = ' . ($this->driver === 'pgsql' ? 'TRUE' : '1') . ' GROUP BY lora_alias')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        foreach ($rows as $row) {
            $counts[(string) $row['lora_alias']] = (int) $row['total'];
        }
        return $counts;
    }

    private function loraReferenceCounts(): array
    {
        $counts = [];
        try {
            $rows = $this->pdo->query('SELECT lora_alias, COUNT(*) AS total FROM lora_reference_images GROUP BY lora_alias')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        foreach ($rows as $row) {
            $counts[(string) $row['lora_alias']] = (int) $row['total'];
        }
        return $counts;
    }

    private function getLoraReferences(string $alias, ?string $variantKey = null): array
    {
        $sql = 'SELECT * FROM lora_reference_images WHERE lora_alias = :alias';
        $params = ['alias' => trim($alias)];
        if ($variantKey !== null) {
            $sql .= ' AND variant_key = :variant_key';
            $params['variant_key'] = $this->slug($variantKey);
        }
        $sql .= ' ORDER BY sort_order, id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'decodeLoraReference'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function decodeLoraVariant(array $row): array
    {
        $weight = $row['weight_override'] ?? null;
        return [
            'lora_alias' => (string) $row['lora_alias'],
            'variant_key' => (string) $row['variant_key'],
            'label' => (string) ($row['label'] ?: $row['variant_key']),
            'trigger_words' => (string) ($row['trigger_words'] ?? ''),
            'positive_tags' => (string) ($row['positive_tags'] ?? ''),
            'negative_tags' => (string) ($row['negative_tags'] ?? ''),
            'weight_override' => $weight === null || $weight === '' ? null : $this->normalizeLoraWeight($weight),
            'compatible_acts' => (string) ($row['compatible_acts'] ?? ''),
            'incompatible_acts' => (string) ($row['incompatible_acts'] ?? ''),
            'act_groups' => (string) ($row['act_groups'] ?? ''),
            'clothing_policy' => $this->normalizeVariantClothingPolicy((string) ($row['clothing_policy'] ?? 'incidental')),
            'clothing_tags' => (string) ($row['clothing_tags'] ?? ''),
            'clothing_required_tags' => (string) ($row['clothing_required_tags'] ?? ''),
            'strip_clothing_when_outfit_active' => $this->dbBool($row['strip_clothing_when_outfit_active'] ?? true),
            'anonymous_partner_tags' => (string) ($row['anonymous_partner_tags'] ?? ''),
            'ensemble_tags' => (string) ($row['ensemble_tags'] ?? ''),
            'requires_secondary_characters' => $this->dbBool($row['requires_secondary_characters'] ?? false),
            'min_secondary_characters' => (int) ($row['min_secondary_characters'] ?? 0),
            'max_secondary_characters' => (int) ($row['max_secondary_characters'] ?? 0),
            'notes' => (string) ($row['notes'] ?? ''),
            'enabled' => $this->dbBool($row['enabled'] ?? true),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function decodeLoraReference(array $row): array
    {
        $path = (string) ($row['file_path'] ?? '');
        return [
            'id' => (int) $row['id'],
            'lora_alias' => (string) $row['lora_alias'],
            'variant_key' => (string) ($row['variant_key'] ?? 'default'),
            'file_path' => $path,
            'url' => '/' . ltrim($path, '/'),
            'original_name' => (string) ($row['original_name'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'width' => (int) ($row['width'] ?? 0),
            'height' => (int) ($row['height'] ?? 0),
            'caption' => (string) ($row['caption'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function safePathSegment(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($value)) ?: 'lora';
        return trim($safe, '._') ?: 'lora';
    }

    private function normalizeLoraCategory(string $category): string
    {
        $category = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($category)) ?: 'uncategorized';
        $allowed = ['clothing', 'pose', 'style', 'character', 'expression', 'body', 'background', 'effect', 'uncategorized'];
        return in_array($category, $allowed, true) ? $category : 'uncategorized';
    }

    private function normalizeLoraWeight(mixed $weight): float
    {
        $value = (float) $weight;
        if ($value <= 0 || $value > 2) {
            return 0.8;
        }
        return round($value, 2);
    }

    private function normalizeVariantClothingPolicy(string $policy): string
    {
        $policy = mb_strtolower(trim($policy));
        return in_array($policy, ['incidental', 'required', 'override', 'forbidden'], true) ? $policy : 'incidental';
    }

    private function dbBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        $normalized = mb_strtolower(trim((string) $value));
        return in_array($normalized, ['1', 't', 'true', 'yes', 'on'], true);
    }

    private function ensureLoraLibraryColumns(): void
    {
        $add = $this->driver === 'pgsql'
            ? [
                'compatible_series' => "TEXT NOT NULL DEFAULT ''",
                'compatible_characters' => "TEXT NOT NULL DEFAULT ''",
                'compatible_acts' => "TEXT NOT NULL DEFAULT ''",
                'incompatible_acts' => "TEXT NOT NULL DEFAULT ''",
                'act_groups' => "TEXT NOT NULL DEFAULT ''",
                'requires_outfit_none' => 'BOOLEAN NOT NULL DEFAULT FALSE',
                'scene_intent_hint' => "TEXT NOT NULL DEFAULT ''",
                'nsfw_effect_groups' => "TEXT NOT NULL DEFAULT ''",
                'needs_trigger' => 'BOOLEAN NOT NULL DEFAULT FALSE',
                'requires_secondary_characters' => 'BOOLEAN NOT NULL DEFAULT FALSE',
                'min_secondary_characters' => 'INTEGER NOT NULL DEFAULT 0',
                'max_secondary_characters' => 'INTEGER NOT NULL DEFAULT 0',
                'anonymous_partner_tags' => "TEXT NOT NULL DEFAULT ''",
                'ensemble_tags' => "TEXT NOT NULL DEFAULT ''",
            ]
            : [
                'compatible_series' => 'TEXT NOT NULL DEFAULT ""',
                'compatible_characters' => 'TEXT NOT NULL DEFAULT ""',
                'compatible_acts' => 'TEXT NOT NULL DEFAULT ""',
                'incompatible_acts' => 'TEXT NOT NULL DEFAULT ""',
                'act_groups' => 'TEXT NOT NULL DEFAULT ""',
                'requires_outfit_none' => 'INTEGER NOT NULL DEFAULT 0',
                'scene_intent_hint' => 'TEXT NOT NULL DEFAULT ""',
                'nsfw_effect_groups' => 'TEXT NOT NULL DEFAULT ""',
                'needs_trigger' => 'INTEGER NOT NULL DEFAULT 0',
                'requires_secondary_characters' => 'INTEGER NOT NULL DEFAULT 0',
                'min_secondary_characters' => 'INTEGER NOT NULL DEFAULT 0',
                'max_secondary_characters' => 'INTEGER NOT NULL DEFAULT 0',
                'anonymous_partner_tags' => 'TEXT NOT NULL DEFAULT ""',
                'ensemble_tags' => 'TEXT NOT NULL DEFAULT ""',
            ];

        foreach ($add as $column => $definition) {
            if ($this->driver === 'pgsql') {
                $this->pdo->exec('ALTER TABLE lora_library ADD COLUMN IF NOT EXISTS ' . $column . ' ' . $definition);
                continue;
            }
            if (!$this->sqliteColumnExists('lora_library', $column)) {
                $this->pdo->exec('ALTER TABLE lora_library ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
    }

    private function defaultSettings(): array
    {
        return [
            'sd' => [
                'checkpoint' => '',
                'sampler' => 'Euler a',
                'width' => 832,
                'height' => 1216,
                'steps' => 28,
                'cfg_scale' => 6,
                'batch_size' => 1,
                'hires' => [
                    'enabled_default' => false,
                    'upscaler' => 'SwinIR_4x',
                    'scale' => 2,
                    'steps' => 0,
                    'denoising_strength' => 0.6,
                    'cfg_scale' => 7,
                    'resize_x' => 0,
                    'resize_y' => 0,
                    'active_profile' => 'fine',
                    'profiles' => [
                        'fine' => [
                            'label' => 'Fine',
                            'upscaler' => 'Latent (bicubic antialiased)',
                            'scale' => 2,
                            'steps' => 0,
                            'denoising_strength' => 0.56,
                            'cfg_scale' => 7,
                            'anatomy_guard_enabled' => true,
                            'sampler' => '',
                            'scheduler' => '',
                            'style_positive' => '',
                            'style_negative' => '',
                            'strip_prompt_tags' => '',
                        ],
                        'fine_detail' => [
                            'label' => 'Fine Detail',
                            'upscaler' => 'Latent',
                            'scale' => 2,
                            'steps' => 0,
                            'denoising_strength' => 0.45,
                            'cfg_scale' => 6,
                            'anatomy_guard_enabled' => true,
                            'sampler' => 'DPM++ 2M',
                            'scheduler' => 'Karras',
                            'style_positive' => self::CLEAN_HIRES_STYLE_POSITIVE,
                            'style_negative' => self::CLEAN_HIRES_STYLE_NEGATIVE,
                            'strip_prompt_tags' => self::CLEAN_HIRES_STRIP_TAGS,
                        ],
                        'safe' => [
                            'label' => 'Safe',
                            'upscaler' => 'SwinIR_4x',
                            'scale' => 2,
                            'steps' => 0,
                            'denoising_strength' => 0.30,
                            'cfg_scale' => 7,
                            'anatomy_guard_enabled' => true,
                            'sampler' => '',
                            'scheduler' => '',
                            'style_positive' => '',
                            'style_negative' => '',
                            'strip_prompt_tags' => '',
                        ],
                        'enhance' => [
                            'label' => 'Enhance',
                            'upscaler' => 'SwinIR_4x',
                            'scale' => 2,
                            'steps' => 0,
                            'denoising_strength' => 0.35,
                            'cfg_scale' => 7,
                            'anatomy_guard_enabled' => true,
                            'sampler' => '',
                            'scheduler' => '',
                            'style_positive' => '',
                            'style_negative' => '',
                            'strip_prompt_tags' => '',
                        ],
                        'repair_hiresfix' => [
                            'label' => 'Repair + Hires.fix',
                            'upscaler' => 'Latent',
                            'scale' => 2,
                            'steps' => 0,
                            'denoising_strength' => 0.45,
                            'cfg_scale' => 6,
                            'anatomy_guard_enabled' => true,
                            'sampler' => 'DPM++ 2M',
                            'scheduler' => 'Karras',
                            'style_positive' => self::CLEAN_HIRES_STYLE_POSITIVE,
                            'style_negative' => self::CLEAN_HIRES_STYLE_NEGATIVE,
                            'strip_prompt_tags' => self::CLEAN_HIRES_STRIP_TAGS,
                        ],
                        'repair_preserve' => [
                            'label' => 'Repair Preserve Quality',
                            'upscaler' => 'Latent',
                            'scale' => 1,
                            'steps' => 0,
                            'denoising_strength' => 0.28,
                            'cfg_scale' => 5,
                            'anatomy_guard_enabled' => true,
                            'sampler' => 'DPM++ 2M',
                            'scheduler' => 'Karras',
                            'style_positive' => '',
                            'style_negative' => '',
                            'strip_prompt_tags' => '',
                        ],
                    ],
                    'anatomy_guard_version' => 3,
                    'abdomen_guard' => self::ABDOMEN_GUARD,
                    'hands_guard' => self::HANDS_GUARD,
                    'feet_guard' => self::FEET_GUARD,
                    'feet_safe_crop' => self::FEET_SAFE_CROP,
                    'anatomy_guard_enabled' => true,
                    'anatomy_positive' => self::ANATOMY_POSITIVE,
                    'anatomy_negative' => self::ANATOMY_NEGATIVE,
                    'anatomy_denoising_strength' => 0.5,
                ],
            ],
            'llm' => [
                'api_base' => Config::llmBaseUrl(),
                'model' => Config::llmModel(),
                'api_key' => '',
                'temperature' => 0.45,
                'max_tokens' => 220,
            ],
            'prompt_debug' => [
                'system_template' => 'You are a Stable Diffusion prompt editor. Return only comma-separated positive prompt additions. Do not include negatives. Do not repeat or rewrite locked tags. Do not add prose.',
                'user_template' => "Locked ULTIMA tags that must remain untouched and must not be rewritten:\n{{locked_tags}}\n\nCurrent positive prompt:\n{{prompt}}\n\nSelected style tags:\n{{style_tags}}\n\nAdd concise quality, scene, composition, lighting, camera, and style tags that complement this prompt.",
            ],
        ];
    }

    private function upgradeHiresAnatomyDefaults(array $settings): array
    {
        $hires = $settings['sd']['hires'] ?? null;
        if (!is_array($hires)) {
            return $settings;
        }

        $positive = trim((string) ($hires['anatomy_positive'] ?? ''));
        $negative = trim((string) ($hires['anatomy_negative'] ?? ''));
        $denoise = (float) ($hires['anatomy_denoising_strength'] ?? 0.0);
        $version = (int) ($hires['anatomy_guard_version'] ?? 0);

        $defaults = $this->defaultSettings()['sd']['hires'];
        $settings['sd']['hires']['active_profile'] = (string) ($hires['active_profile'] ?? 'fine');
        $settings['sd']['hires']['profiles'] = array_replace_recursive($defaults['profiles'], is_array($hires['profiles'] ?? null) ? $hires['profiles'] : []);
        $settings['sd']['hires']['anatomy_guard_version'] = 3;
        $settings['sd']['hires']['abdomen_guard'] = trim((string) ($hires['abdomen_guard'] ?? self::ABDOMEN_GUARD)) ?: self::ABDOMEN_GUARD;
        $settings['sd']['hires']['hands_guard'] = trim((string) ($hires['hands_guard'] ?? self::HANDS_GUARD)) ?: self::HANDS_GUARD;
        $settings['sd']['hires']['feet_guard'] = trim((string) ($hires['feet_guard'] ?? self::FEET_GUARD)) ?: self::FEET_GUARD;
        $settings['sd']['hires']['feet_safe_crop'] = trim((string) ($hires['feet_safe_crop'] ?? self::FEET_SAFE_CROP)) ?: self::FEET_SAFE_CROP;

        if ($version < 3 || $positive === '' || $positive === self::OLD_ANATOMY_POSITIVE || $positive === self::V2_ANATOMY_POSITIVE) {
            $settings['sd']['hires']['anatomy_positive'] = self::ANATOMY_POSITIVE;
        }
        if ($version < 3 || $negative === '' || $negative === self::OLD_ANATOMY_NEGATIVE || $negative === self::V2_ANATOMY_NEGATIVE) {
            $settings['sd']['hires']['anatomy_negative'] = self::ANATOMY_NEGATIVE;
        }
        if ($denoise <= 0.0 || abs($denoise - 0.6) < 0.0001) {
            $settings['sd']['hires']['anatomy_denoising_strength'] = 0.5;
        }

        return $settings;
    }

    private function upgradeHiresQualityDefaults(): void
    {
        $stmt = $this->pdo->prepare('SELECT value_json FROM settings WHERE key = :key');
        $stmt->execute(['key' => 'hires_quality_patch_version']);
        $raw = $stmt->fetchColumn();
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if ((int) ($value['version'] ?? 0) >= 2) {
            return;
        }

        $settings = $this->getSettings();
        $profiles = is_array($settings['sd']['hires']['profiles'] ?? null) ? $settings['sd']['hires']['profiles'] : [];
        foreach (['fine_detail', 'repair_hiresfix'] as $key) {
            $profile = is_array($profiles[$key] ?? null) ? $profiles[$key] : [];
            if ($this->shouldUpgradeCleanHiresProfile($profile)) {
                $profiles[$key] = array_replace($profile, $this->cleanHiresProfileDefaults($key));
            }
        }
        $settings['sd']['hires']['profiles'] = $profiles;

        $save = $this->pdo->prepare(
            'INSERT INTO settings (key, value_json, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
        );
        $save->execute([
            'key' => 'sd',
            'value' => json_encode($settings['sd'], JSON_UNESCAPED_SLASHES),
            'updated_at' => gmdate('c'),
        ]);
        $save->execute([
            'key' => 'hires_quality_patch_version',
            'value' => json_encode(['version' => 2], JSON_UNESCAPED_SLASHES),
            'updated_at' => gmdate('c'),
        ]);

        $presetRows = $this->pdo->prepare('SELECT id, content FROM presets WHERE type = :type AND name = :name');
        $presetRows->execute(['type' => 'global', 'name' => 'Premium anime XL']);
        $updatePreset = $this->pdo->prepare('UPDATE presets SET content = :content, updated_at = :updated_at WHERE id = :id');
        foreach ($presetRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $content = mb_strtolower((string) ($row['content'] ?? ''));
            if (str_contains($content, 'screencap') || str_contains($content, 'lineart') || str_contains($content, 'motion lines') || str_contains($content, 'motion blur')) {
                $updatePreset->execute([
                    'id' => (int) $row['id'],
                    'content' => self::PREMIUM_ANIME_XL_CLEAN,
                    'updated_at' => gmdate('c'),
                ]);
            }
        }

        $variantRows = $this->pdo->prepare('SELECT lora_alias, variant_key, positive_tags FROM lora_variants WHERE positive_tags LIKE :tag');
        $variantRows->execute(['tag' => '%motion lines%']);
        $updateVariant = $this->pdo->prepare(
            'UPDATE lora_variants SET positive_tags = :positive_tags, updated_at = :updated_at WHERE lora_alias = :alias AND variant_key = :variant_key'
        );
        foreach ($variantRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clean = implode(', ', array_values(array_filter(
                array_map('trim', explode(',', (string) ($row['positive_tags'] ?? ''))),
                static fn (string $tag): bool => mb_strtolower($tag) !== 'motion lines'
            )));
            $updateVariant->execute([
                'alias' => (string) $row['lora_alias'],
                'variant_key' => (string) $row['variant_key'],
                'positive_tags' => $clean,
                'updated_at' => gmdate('c'),
            ]);
        }
    }

    private function shouldUpgradeCleanHiresProfile(array $profile): bool
    {
        if ($profile === []) {
            return true;
        }
        $positive = mb_strtolower((string) ($profile['style_positive'] ?? ''));
        $negative = mb_strtolower((string) ($profile['style_negative'] ?? ''));
        $strip = mb_strtolower((string) ($profile['strip_prompt_tags'] ?? ''));
        $denoise = (float) ($profile['denoising_strength'] ?? 0);
        return str_contains($positive, 'refined rendering')
            || str_contains($negative, 'rough lineart')
            || (str_contains($strip, 'clean lineart') && !str_contains($strip, 'motion lines'))
            || (str_contains($strip, 'anime screencap') && !str_contains($strip, 'speed lines'))
            || $denoise >= 0.55;
    }

    private function cleanHiresProfileDefaults(string $key): array
    {
        return [
            'label' => $key === 'repair_hiresfix' ? 'Repair + Hires.fix' : 'Fine Detail',
            'upscaler' => 'Latent',
            'scale' => 2,
            'steps' => 0,
            'denoising_strength' => 0.45,
            'cfg_scale' => 6,
            'anatomy_guard_enabled' => true,
            'sampler' => 'DPM++ 2M',
            'scheduler' => 'Karras',
            'style_positive' => self::CLEAN_HIRES_STYLE_POSITIVE,
            'style_negative' => self::CLEAN_HIRES_STYLE_NEGATIVE,
            'strip_prompt_tags' => self::CLEAN_HIRES_STRIP_TAGS,
        ];
    }

    private function defaultPromptLibrary(): array
    {
        $negativeClothing = 'clothes, clothing, dress, shirt, skirt, pants, shorts, underwear, bra, panties, bikini, swimsuit, armor, uniform, robe, coat, jacket, socks, stockings, tights, garters, gloves, headwear, hat, mask, scarf, necklace, bracelet, ring, earrings, goggles, glasses, contact lenses, headphones, earpieces';
        $library = [
            'modes' => [
                'standard' => ['key' => 'standard', 'label' => 'Standard', 'tags' => '', 'negative_tags' => ''],
                'soft_nsfw' => ['key' => 'soft_nsfw', 'label' => 'Soft NSFW', 'tags' => '(nsfw:1.4), (nude:1.3), (no clothes:1.3), (naked:1.4), (exposed:1.2), (sensual:1.2), (erotic:1.2)', 'negative_tags' => $negativeClothing],
                'hard_nsfw' => ['key' => 'hard_nsfw', 'label' => 'Hard NSFW', 'tags' => self::HARD_NSFW_NEUTRAL_TAGS, 'negative_tags' => $negativeClothing],
                'bikini' => ['key' => 'bikini', 'label' => 'Bikini', 'tags' => 'bikini, swimsuit, beach, (minimal clothing:1.2), (revealing:1.1)', 'negative_tags' => ''],
                'lingerie' => ['key' => 'lingerie', 'label' => 'Lingerie', 'tags' => 'lingerie, lace, underwear, (seductive:1.2), (see-through:1.2), (transparent:1.1)', 'negative_tags' => ''],
            ],
            'quick_tags' => [
                'open_mouth', 'sweat', 'wet', 'no_bra', 'fishnets', 'choker', 'thighhighs', 'tongue_out',
            ],
            'pose_library' => $this->defaultPoseLibrary(),
            'nsfw_director' => [
                'version' => 6,
                'intensities' => [
                    'soft' => ['key' => 'soft', 'label' => 'Soft', 'tags' => '(sensual:1.2), (erotic pose:1.15), suggestive, intimate mood', 'hard_tags' => ''],
                    'nude' => ['key' => 'nude', 'label' => 'Nude', 'tags' => '(nude:1.35), (no clothes:1.35), bare skin, exposed breasts, nipples', 'hard_tags' => ''],
                    'explicit' => ['key' => 'explicit', 'label' => 'Explicit', 'tags' => '(explicit:1.35), (nsfw:1.45), exposed genitals, sex act, erotic focus', 'hard_tags' => '(pussy:1.35), (nipples:1.3), (uncensored:1.25)'],
                    'hard' => ['key' => 'hard', 'label' => 'Hard', 'tags' => '(nsfw:1.45), (explicit:1.22), uncensored, intense erotic adult mood, provocative adult framing', 'hard_tags' => '(penetration:1.45), (pussy:1.45), (cock:1.35), (cum:1.25), (orgasm:1.25)'],
                ],
                'acts' => [
                    'nude_pose' => ['key' => 'nude_pose', 'label' => 'Nude pose', 'aliases' => 'naked pose, nude modeling', 'positive_tags' => 'nude pose, naked body, posing nude, exposed breasts, full body nude', 'hard_tags' => 'spread legs, exposed pussy, erotic pose', 'negative_disambiguation' => 'clothed, covered body', 'recommended_focus' => 'body_focus', 'recommended_camera' => 'full_body'],
                    'spread_legs' => ['key' => 'spread_legs', 'label' => 'Spread legs', 'aliases' => 'legs open', 'positive_tags' => 'spread legs, legs open, thighs apart, open hips, inviting pose', 'hard_tags' => 'exposed pussy, genital focus, explicit view', 'negative_disambiguation' => 'closed legs, crossed legs', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'low_angle'],
                    'missionary_position' => ['key' => 'missionary_position', 'label' => 'Missionary position', 'aliases' => 'missionary sex', 'positive_tags' => 'missionary position, sex position, lying on back, partner above, intimate body contact', 'hard_tags' => 'explicit penetration, penis in pussy, vaginal sex, legs spread', 'negative_disambiguation' => 'praying, church, religious, missionary clothes', 'recommended_focus' => 'penetration_focus', 'recommended_camera' => 'bedside'],
                    'cowgirl_position' => ['key' => 'cowgirl_position', 'label' => 'Cowgirl position', 'aliases' => 'woman on top, riding position', 'positive_tags' => 'cowgirl position, woman on top, straddling partner, riding position, sex position, hips over partner', 'hard_tags' => 'explicit penetration, vaginal sex, penis in pussy, grinding, genital contact', 'negative_disambiguation' => 'cowboy hat, western, ranch, rodeo, horse riding, lasso, cowgirl outfit, western outfit, boots, saloon', 'recommended_focus' => 'penetration_focus', 'recommended_camera' => 'pov'],
                    'reverse_cowgirl' => ['key' => 'reverse_cowgirl', 'label' => 'Reverse cowgirl', 'aliases' => 'reverse riding position', 'positive_tags' => 'reverse cowgirl position, woman on top facing away, straddling partner, back view, sex position', 'hard_tags' => 'explicit penetration, vaginal sex, ass focus, genital contact, grinding', 'negative_disambiguation' => 'cowboy hat, western, ranch, rodeo, horse riding, lasso, cowgirl outfit, western outfit', 'recommended_focus' => 'ass_focus', 'recommended_camera' => 'back_view'],
                    'doggy_style' => ['key' => 'doggy_style', 'label' => 'Doggy style', 'aliases' => 'from behind on all fours', 'positive_tags' => 'doggy style sex position, on all fours, from behind sex, arched back, knees on bed', 'hard_tags' => 'explicit penetration, vaginal sex from behind, ass focus, genital contact', 'negative_disambiguation' => 'dog, pet, animal, animal focus, canine, tail wagging, leash pet play', 'recommended_focus' => 'ass_focus', 'recommended_camera' => 'back_view'],
                    'from_behind' => ['key' => 'from_behind', 'label' => 'From behind', 'aliases' => 'rear sex position', 'positive_tags' => 'from behind, rear entry sex position, back view, hips held, bent forward', 'hard_tags' => 'explicit penetration, vaginal sex from behind, ass focus, genital contact', 'negative_disambiguation' => 'standing behind only, photobomb, crowd behind', 'recommended_focus' => 'ass_focus', 'recommended_camera' => 'back_view'],
                    'standing_sex' => ['key' => 'standing_sex', 'label' => 'Standing sex', 'aliases' => 'standing position', 'positive_tags' => 'standing sex position, upright body contact, lifted leg, close embrace', 'hard_tags' => 'explicit penetration, genital contact, pressed bodies', 'negative_disambiguation' => 'standing alone, walking, idle pose', 'recommended_focus' => 'full_body_focus', 'recommended_camera' => 'full_body'],
                    'sitting_sex' => ['key' => 'sitting_sex', 'label' => 'Sitting sex', 'aliases' => 'seated sex position', 'positive_tags' => 'sitting sex position, seated partner, straddling lap, intimate seated pose', 'hard_tags' => 'explicit penetration, genital contact, grinding on lap', 'negative_disambiguation' => 'sitting alone, chair portrait, classroom chair', 'recommended_focus' => 'penetration_focus', 'recommended_camera' => 'medium_shot'],
                    'lap_sitting' => ['key' => 'lap_sitting', 'label' => 'Lap sitting', 'aliases' => 'sitting on lap', 'positive_tags' => 'lap sitting, sitting on partner lap, straddling lap, intimate body contact', 'hard_tags' => 'grinding, explicit lap sex, genital contact', 'negative_disambiguation' => 'child on lap, innocent pose, family photo', 'recommended_focus' => 'body_contact', 'recommended_camera' => 'medium_shot'],
                    'blowjob' => ['key' => 'blowjob', 'label' => 'Blowjob', 'aliases' => 'oral sex, fellatio', 'positive_tags' => 'blowjob, oral sex, fellatio, mouth around penis, kneeling pose', 'hard_tags' => 'deep oral, saliva, cock in mouth, explicit oral sex', 'negative_disambiguation' => 'microphone, flute, food in mouth, whistle', 'recommended_focus' => 'oral_focus', 'recommended_camera' => 'close_up'],
                    'deepthroat' => ['key' => 'deepthroat', 'label' => 'Deepthroat', 'aliases' => 'deep oral', 'positive_tags' => 'deepthroat, deep oral sex, cock in throat, oral sex focus', 'hard_tags' => 'saliva, tears, explicit deepthroat, gagging expression', 'negative_disambiguation' => 'necklace, choker only, sore throat, microphone', 'recommended_focus' => 'oral_focus', 'recommended_camera' => 'close_up'],
                    'tit_fuck' => ['key' => 'tit_fuck', 'label' => 'Tit fuck', 'aliases' => 'paizuri, breast sex', 'positive_tags' => 'tit fuck, paizuri, breast sex, penis between breasts, cleavage focus', 'hard_tags' => 'cum on breasts, explicit breast sex, cock between breasts', 'negative_disambiguation' => 'breastplate, armor chest, necklace focus', 'recommended_focus' => 'breast_focus', 'recommended_camera' => 'close_up'],
                    'tit_fuck_full_body' => ['key' => 'tit_fuck_full_body', 'label' => 'Tit fuck full body', 'aliases' => 'full body paizuri, full body breast sex', 'positive_tags' => 'tit fuck, paizuri, breast sex, penis between breasts, full body composition, standing or kneeling full body', 'hard_tags' => 'cum on breasts, explicit breast sex, cock between breasts, readable full body act', 'negative_disambiguation' => 'breastplate, armor chest, necklace focus, cropped torso, upper body crop', 'recommended_focus' => 'full_body_focus', 'recommended_camera' => 'full_body'],
                    'masturbation' => ['key' => 'masturbation', 'label' => 'Masturbation', 'aliases' => 'self touch', 'positive_tags' => 'masturbation, touching self, self pleasure, hand between legs, erotic solo pose', 'hard_tags' => 'fingering self, exposed pussy, orgasm, explicit self pleasure', 'negative_disambiguation' => 'holding object, waving hand, hand on hip', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'medium_shot'],
                    'fingering' => ['key' => 'fingering', 'label' => 'Fingering', 'aliases' => 'finger penetration', 'positive_tags' => 'fingering, fingers between legs, hand on pussy, intimate touch', 'hard_tags' => 'finger penetration, explicit fingering, wet pussy, orgasm', 'negative_disambiguation' => 'pointing finger, finger gun, hand gesture', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'close_up'],
                    'handjob' => ['key' => 'handjob', 'label' => 'Handjob', 'aliases' => 'manual sex', 'positive_tags' => 'handjob, stroking penis, hand around cock, manual sex', 'hard_tags' => 'cum, explicit handjob, penis focus, precum', 'negative_disambiguation' => 'handshake, waving, holding handle', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'close_up'],
                    'cunnilingus' => ['key' => 'cunnilingus', 'label' => 'Cunnilingus', 'aliases' => 'oral on pussy', 'positive_tags' => 'cunnilingus, oral sex on pussy, tongue between legs, face between thighs', 'hard_tags' => 'licking pussy, explicit oral sex, wet pussy, orgasm', 'negative_disambiguation' => 'kissing cheek, eating food, tongue out only', 'recommended_focus' => 'oral_focus', 'recommended_camera' => 'low_angle'],
                    'bondage_pose' => ['key' => 'bondage_pose', 'label' => 'Bondage pose', 'aliases' => 'bdsm pose, restrained pose', 'positive_tags' => 'bondage pose, consensual bdsm, restrained wrists, rope bondage, tied up erotic pose', 'hard_tags' => 'shibari, spread legs bondage, explicit bdsm scene', 'negative_disambiguation' => 'violence, injury, blood, distressed, crying in fear', 'recommended_focus' => 'body_focus', 'recommended_camera' => 'full_body'],
                    'sex_toys' => ['key' => 'sex_toys', 'label' => 'Sex toys', 'aliases' => 'dildo, vibrator', 'positive_tags' => 'sex toys, dildo, vibrator, toy play, erotic toy focus', 'hard_tags' => 'dildo insertion, vibrator on pussy, explicit toy use, wet pussy', 'negative_disambiguation' => 'children toys, plush toy, toy store, action figure', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'close_up'],
                    'prone_bone' => ['key' => 'prone_bone', 'label' => 'Prone bone', 'aliases' => 'lying face down sex', 'positive_tags' => 'prone bone sex position, lying face down, pinned hips, rear entry sex position, body pressed to bed', 'hard_tags' => 'explicit penetration, vaginal sex from behind, hips pinned down, genital contact', 'negative_disambiguation' => 'sleeping alone, face down pose only, massage table', 'recommended_focus' => 'ass_focus', 'recommended_camera' => 'bedside'],
                    'mating_press' => ['key' => 'mating_press', 'label' => 'Mating press', 'aliases' => 'folded missionary', 'positive_tags' => 'mating press sex position, legs pushed up, folded body, partner above, intense body contact', 'hard_tags' => 'explicit penetration, vaginal sex, deep penetration, legs pressed back', 'negative_disambiguation' => 'exercise stretch, yoga pose, leg press machine', 'recommended_focus' => 'penetration_focus', 'recommended_camera' => 'bedside'],
                    'spooning_sex' => ['key' => 'spooning_sex', 'label' => 'Spooning sex', 'aliases' => 'side lying sex', 'positive_tags' => 'spooning sex position, side lying, from behind side position, intimate close contact', 'hard_tags' => 'explicit penetration, side sex, hips pressed together, genital contact', 'negative_disambiguation' => 'sleeping, cuddling only, spoon, kitchen utensil', 'recommended_focus' => 'body_contact', 'recommended_camera' => 'medium_shot'],
                    'wall_sex' => ['key' => 'wall_sex', 'label' => 'Wall sex', 'aliases' => 'against wall sex', 'positive_tags' => 'against wall sex position, pressed to wall, lifted leg, upright intimate contact', 'hard_tags' => 'explicit penetration, standing sex against wall, genital contact, pinned to wall', 'negative_disambiguation' => 'wallpaper, standing alone by wall, graffiti wall', 'recommended_focus' => 'full_body_focus', 'recommended_camera' => 'full_body'],
                    'table_sex' => ['key' => 'table_sex', 'label' => 'Table sex', 'aliases' => 'on table sex', 'positive_tags' => 'on table sex position, lying on table, hips at table edge, partner standing close', 'hard_tags' => 'explicit penetration, edge of table sex, legs spread, genital contact', 'negative_disambiguation' => 'dining table scene, food, classroom desk, sitting at table', 'recommended_focus' => 'penetration_focus', 'recommended_camera' => 'medium_shot'],
                    'standing_doggy' => ['key' => 'standing_doggy', 'label' => 'Standing doggy', 'aliases' => 'bent over standing', 'positive_tags' => 'standing doggy style, bent over, from behind sex position, hands braced forward', 'hard_tags' => 'explicit penetration, rear entry standing sex, ass focus, genital contact', 'negative_disambiguation' => 'dog, animal, kneeling on all fours, sitting pose', 'recommended_focus' => 'ass_focus', 'recommended_camera' => 'back_view'],
                    'facesitting' => ['key' => 'facesitting', 'label' => 'Facesitting', 'aliases' => 'sitting on face', 'positive_tags' => 'facesitting, sitting on face, thighs around head, intimate oral position', 'hard_tags' => 'explicit facesitting, oral sex focus, pussy near mouth, thighs pressed close', 'negative_disambiguation' => 'sitting on chair, sitting alone, face portrait only', 'recommended_focus' => 'oral_focus', 'recommended_camera' => 'low_angle'],
                    'sixty_nine' => ['key' => 'sixty_nine', 'label' => 'Sixty nine', 'aliases' => '69 position', 'positive_tags' => 'sixty nine sex position, mutual oral sex, bodies opposite directions, oral focus', 'hard_tags' => 'explicit mutual oral sex, mouth to genitals, close body contact', 'negative_disambiguation' => 'number 69 text, sports jersey, graphic numbers', 'recommended_focus' => 'oral_focus', 'recommended_camera' => 'medium_shot'],
                    'footjob' => ['key' => 'footjob', 'label' => 'Footjob', 'aliases' => 'feet sex', 'positive_tags' => 'footjob, feet around penis, soles focus, erotic foot play', 'hard_tags' => 'explicit footjob, penis between feet, precum, foot focus', 'negative_disambiguation' => 'walking, shoes, socks only, kicking', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'close_up'],
                    'thigh_sex' => ['key' => 'thigh_sex', 'label' => 'Thigh sex', 'aliases' => 'intercrural sex', 'positive_tags' => 'thigh sex, intercrural sex, penis between thighs, thighs pressed together', 'hard_tags' => 'explicit thigh sex, genital contact, grinding between thighs, precum', 'negative_disambiguation' => 'thighhighs only, leg portrait, walking pose', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'close_up'],
                    'assjob' => ['key' => 'assjob', 'label' => 'Assjob', 'aliases' => 'butt sex rubbing', 'positive_tags' => 'assjob, penis between butt cheeks, ass focus, grinding against ass', 'hard_tags' => 'explicit assjob, cock between butt cheeks, cum on ass, rear focus', 'negative_disambiguation' => 'anal penetration, doggy style, sitting alone', 'recommended_focus' => 'ass_focus', 'recommended_camera' => 'back_view'],
                    'anal_sex' => ['key' => 'anal_sex', 'label' => 'Anal sex', 'aliases' => 'anal penetration', 'positive_tags' => 'anal sex position, anal penetration, ass focus, rear entry sex act', 'hard_tags' => 'explicit anal sex, penis in ass, anal focus, genital contact', 'negative_disambiguation' => 'vaginal sex, medical exam, diagram, anal beads only', 'recommended_focus' => 'ass_focus', 'recommended_camera' => 'back_view'],
                    'toy_insertion' => ['key' => 'toy_insertion', 'label' => 'Toy insertion', 'aliases' => 'dildo insertion', 'positive_tags' => 'toy insertion, dildo insertion, sex toy contact, solo toy play', 'hard_tags' => 'explicit dildo insertion, wet pussy, toy penetration, genital focus', 'negative_disambiguation' => 'partner penis, oral sex, toy store, plush toy', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'close_up'],
                    'vibrator_play' => ['key' => 'vibrator_play', 'label' => 'Vibrator play', 'aliases' => 'vibrator stimulation', 'positive_tags' => 'vibrator play, vibrator on pussy, sex toy stimulation, solo pleasure', 'hard_tags' => 'explicit vibrator use, wet pussy, orgasm, toy contact point', 'negative_disambiguation' => 'phone, microphone, electric toothbrush, remote control', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'close_up'],
                    'double_penetration' => ['key' => 'double_penetration', 'label' => 'Double penetration', 'aliases' => 'dp', 'positive_tags' => 'double penetration sex act, two contact points, two penises, simultaneous vaginal and anal penetration, explicit multi-partner position', 'hard_tags' => 'explicit double penetration, vaginal and anal penetration, one penis in pussy, one penis in ass, intense sex act', 'negative_disambiguation' => 'single penetration, anal only, only anal penetration, single penis, one penis only, one insertion point, missing vaginal penetration, vaginal not visible, toy only, solo, crowd background', 'recommended_focus' => 'penetration_focus', 'recommended_camera' => 'medium_shot'],
                    'breast_smother' => ['key' => 'breast_smother', 'label' => 'Breast smother', 'aliases' => 'breast press face', 'positive_tags' => 'breast smother, face pressed into breasts, breast focus, intimate breast contact', 'hard_tags' => 'explicit breast smother, cleavage enveloping face, close body contact', 'negative_disambiguation' => 'suffocation, danger, injury, fear, violence', 'recommended_focus' => 'breast_focus', 'recommended_camera' => 'close_up'],
                    'grinding' => ['key' => 'grinding', 'label' => 'Grinding', 'aliases' => 'dry humping', 'positive_tags' => 'grinding, dry humping, hips pressed together, erotic clothed contact', 'hard_tags' => 'explicit grinding, genital rubbing, intense hip contact, arched back', 'negative_disambiguation' => 'coffee grinder, dancing crowd, skate grinding', 'recommended_focus' => 'body_contact', 'recommended_camera' => 'medium_shot'],
                    'straddling_tease' => ['key' => 'straddling_tease', 'label' => 'Straddling tease', 'aliases' => 'teasing straddle', 'positive_tags' => 'straddling tease, sitting astride, teasing pose, hips over partner', 'hard_tags' => 'explicit teasing straddle, genital contact, grinding tease, seductive control', 'negative_disambiguation' => 'horse riding, western theme, chair sitting, standing pose', 'recommended_focus' => 'body_contact', 'recommended_camera' => 'pov'],
                    'panty_pull' => ['key' => 'panty_pull', 'label' => 'Panty pull', 'aliases' => 'panties pulled aside', 'positive_tags' => 'panty pull, panties pulled aside, teasing exposure, hand pulling underwear', 'hard_tags' => 'explicit panty pull, exposed pussy, wet, genital focus', 'negative_disambiguation' => 'fully nude, no panties, pants only, skirt only', 'recommended_focus' => 'genital_focus', 'recommended_camera' => 'close_up'],
                    'clothed_sex' => ['key' => 'clothed_sex', 'label' => 'Clothed sex', 'aliases' => 'partially clothed sex', 'positive_tags' => 'clothed sex, clothes pulled aside, partially clothed, urgent intimate contact', 'hard_tags' => 'explicit clothed sex, penetration through clothes moved aside, genital contact', 'negative_disambiguation' => 'fully nude, fashion pose, no sex act, standing alone', 'recommended_focus' => 'body_contact', 'recommended_camera' => 'medium_shot'],
                    'shower_sex' => ['key' => 'shower_sex', 'label' => 'Shower sex', 'aliases' => 'wet shower sex', 'positive_tags' => 'shower sex, wet bodies, water droplets, intimate shower scene', 'hard_tags' => 'explicit shower sex, wet skin, pressed against shower wall, genital contact', 'negative_disambiguation' => 'bath alone, washing hair, rain outside, swimsuit shower', 'recommended_focus' => 'body_contact', 'recommended_camera' => 'medium_shot'],
                ],
                'focuses' => [
                    'body_focus' => ['key' => 'body_focus', 'label' => 'Body focus', 'tags' => 'body focus, detailed anatomy, skin detail'],
                    'full_body_focus' => ['key' => 'full_body_focus', 'label' => 'Full body', 'tags' => 'full body, entire body visible, pose clarity'],
                    'genital_focus' => ['key' => 'genital_focus', 'label' => 'Genital focus', 'tags' => 'genital focus, explicit anatomical focus, detailed pussy'],
                    'penetration_focus' => ['key' => 'penetration_focus', 'label' => 'Penetration', 'tags' => 'penetration focus, genital contact focus, explicit sex focus'],
                    'ass_focus' => ['key' => 'ass_focus', 'label' => 'Ass focus', 'tags' => 'ass focus, hips focus, from behind composition'],
                    'breast_focus' => ['key' => 'breast_focus', 'label' => 'Breast focus', 'tags' => 'breast focus, cleavage focus, detailed nipples'],
                    'oral_focus' => ['key' => 'oral_focus', 'label' => 'Oral focus', 'tags' => 'oral focus, mouth focus, close mouth detail'],
                    'body_contact' => ['key' => 'body_contact', 'label' => 'Body contact', 'tags' => 'close body contact, intimate contact, pressed bodies'],
                ],
                'expressions' => [
                    'bedroom_eyes' => ['key' => 'bedroom_eyes', 'label' => 'Bedroom eyes', 'tags' => 'bedroom eyes, seductive gaze, half-closed eyes'],
                    'moaning' => ['key' => 'moaning', 'label' => 'Moaning', 'tags' => 'moaning, open mouth, erotic expression'],
                    'blush' => ['key' => 'blush', 'label' => 'Blush', 'tags' => 'blush, flushed face, embarrassed pleasure'],
                    'ahegao' => ['key' => 'ahegao', 'label' => 'Ahegao', 'tags' => 'ahegao, rolling eyes, tongue out, orgasm face'],
                    'confident' => ['key' => 'confident', 'label' => 'Confident', 'tags' => 'confident expression, seductive smile, direct gaze'],
                    'lustful_gaze' => ['key' => 'lustful_gaze', 'label' => 'Lustful gaze', 'tags' => 'lustful gaze, hungry eyes, intense desire, direct eye contact'],
                    'orgasm_face' => ['key' => 'orgasm_face', 'label' => 'Orgasm face', 'tags' => 'orgasm face, flushed expression, trembling lips, pleasure expression'],
                    'drooling' => ['key' => 'drooling', 'label' => 'Drooling', 'tags' => 'drooling, saliva, open mouth, messy pleasure'],
                    'heavy_blush' => ['key' => 'heavy_blush', 'label' => 'Heavy blush', 'tags' => 'heavy blush, flushed cheeks, embarrassed arousal, red face'],
                    'pleasure_tears' => ['key' => 'pleasure_tears', 'label' => 'Pleasure tears', 'tags' => 'pleasure tears, teary eyes, overwhelmed pleasure, flushed face'],
                    'biting_lip' => ['key' => 'biting_lip', 'label' => 'Biting lip', 'tags' => 'biting lip, seductive expression, restrained moan, close mouth detail'],
                    'panting' => ['key' => 'panting', 'label' => 'Panting', 'tags' => 'panting, parted lips, heavy breathing, erotic expression'],
                    'teasing_smile' => ['key' => 'teasing_smile', 'label' => 'Teasing smile', 'tags' => 'teasing smile, playful seductive gaze, confident tease'],
                    'submissive_gaze' => ['key' => 'submissive_gaze', 'label' => 'Submissive gaze', 'tags' => 'submissive gaze, shy arousal, looking up, obedient pose'],
                    'dominant_smile' => ['key' => 'dominant_smile', 'label' => 'Dominant smile', 'tags' => 'dominant smile, confident expression, assertive seductive gaze'],
                    'come_hither_look' => ['key' => 'come_hither_look', 'label' => 'Come-hither look', 'tags' => 'come-hither look, inviting gaze, seductive eye contact'],
                    'bedroom_smirk' => ['key' => 'bedroom_smirk', 'label' => 'Bedroom smirk', 'tags' => 'bedroom smirk, knowing smile, sultry gaze, intimate expression'],
                    'playful_wink' => ['key' => 'playful_wink', 'label' => 'Playful wink', 'tags' => 'playful wink, flirty expression, teasing smile, one eye closed'],
                    'inviting_smile' => ['key' => 'inviting_smile', 'label' => 'Inviting smile', 'tags' => 'inviting smile, warm seductive gaze, welcoming expression'],
                    'teasing_tongue' => ['key' => 'teasing_tongue', 'label' => 'Teasing tongue', 'tags' => 'teasing tongue, tongue slightly out, playful erotic expression'],
                    'ecstasy_face' => ['key' => 'ecstasy_face', 'label' => 'Ecstasy face', 'tags' => 'ecstasy face, flushed face, parted lips, intense pleasure'],
                    'climax_expression' => ['key' => 'climax_expression', 'label' => 'Climax expression', 'tags' => 'climax expression, orgasmic expression, trembling lips, overwhelmed pleasure'],
                    'needy_expression' => ['key' => 'needy_expression', 'label' => 'Needy expression', 'tags' => 'needy expression, pleading eyes, parted lips, desperate desire'],
                    'dazed_pleasure' => ['key' => 'dazed_pleasure', 'label' => 'Dazed pleasure', 'tags' => 'dazed pleasure, unfocused eyes, flushed face, breathless arousal'],
                    'melting_expression' => ['key' => 'melting_expression', 'label' => 'Melting expression', 'tags' => 'melting expression, relaxed pleasure, soft eyes, blissful face'],
                    'saliva_trail' => ['key' => 'saliva_trail', 'label' => 'Saliva trail', 'tags' => 'saliva trail, drooling, glossy lips, open mouth'],
                    'messy_mouth' => ['key' => 'messy_mouth', 'label' => 'Messy mouth', 'tags' => 'messy mouth, saliva, drooling, open mouth, erotic expression'],
                    'tongue_out_heavy' => ['key' => 'tongue_out_heavy', 'label' => 'Tongue out heavy', 'tags' => 'tongue out, heavy breathing, drooling, messy pleasure expression'],
                    'glossy_lips' => ['key' => 'glossy_lips', 'label' => 'Glossy lips', 'tags' => 'glossy lips, wet lips, parted lips, seductive mouth detail'],
                    'open_mouth_panting' => ['key' => 'open_mouth_panting', 'label' => 'Open mouth panting', 'tags' => 'open mouth panting, heavy breathing, parted lips, flushed expression'],
                    'dominant_glare' => ['key' => 'dominant_glare', 'label' => 'Dominant glare', 'tags' => 'dominant glare, confident stare, assertive seductive expression'],
                    'commanding_smile' => ['key' => 'commanding_smile', 'label' => 'Commanding smile', 'tags' => 'commanding smile, confident dominance, controlled seductive gaze'],
                    'submissive_blush' => ['key' => 'submissive_blush', 'label' => 'Submissive blush', 'tags' => 'submissive blush, shy arousal, looking up, flushed cheeks'],
                    'shy_pleading_eyes' => ['key' => 'shy_pleading_eyes', 'label' => 'Shy pleading eyes', 'tags' => 'shy pleading eyes, bashful arousal, looking up, soft blush'],
                    'obedient_expression' => ['key' => 'obedient_expression', 'label' => 'Obedient expression', 'tags' => 'obedient expression, submissive gaze, soft eye contact, flushed cheeks'],
                    'shy_smile' => ['key' => 'shy_smile', 'label' => 'Shy smile', 'tags' => 'shy smile, bashful expression, soft blush, gentle gaze'],
                    'nervous_arousal' => ['key' => 'nervous_arousal', 'label' => 'Nervous arousal', 'tags' => 'nervous arousal, embarrassed smile, flushed face, tense lips'],
                    'soft_blush' => ['key' => 'soft_blush', 'label' => 'Soft blush', 'tags' => 'soft blush, gentle arousal, warm cheeks, tender expression'],
                    'averted_eyes' => ['key' => 'averted_eyes', 'label' => 'Averted eyes', 'tags' => 'averted eyes, bashful gaze, shy expression, flushed cheeks'],
                    'bashful_desire' => ['key' => 'bashful_desire', 'label' => 'Bashful desire', 'tags' => 'bashful desire, shy lust, soft blush, hesitant smile'],
                    'desperate_gaze' => ['key' => 'desperate_gaze', 'label' => 'Desperate gaze', 'tags' => 'desperate gaze, intense desire, pleading eyes, parted lips'],
                    'overwhelmed_expression' => ['key' => 'overwhelmed_expression', 'label' => 'Overwhelmed expression', 'tags' => 'overwhelmed expression, flushed face, teary eyes, breathless pleasure'],
                    'heavy_lidded_eyes' => ['key' => 'heavy_lidded_eyes', 'label' => 'Heavy-lidded eyes', 'tags' => 'heavy-lidded eyes, sultry gaze, sleepy arousal, half-closed eyes'],
                    'tearful_pleasure' => ['key' => 'tearful_pleasure', 'label' => 'Tearful pleasure', 'tags' => 'tearful pleasure, teary eyes, flushed face, trembling lips'],
                    'breathless_expression' => ['key' => 'breathless_expression', 'label' => 'Breathless expression', 'tags' => 'breathless expression, parted lips, panting, flushed cheeks'],
                ],
                'clothing_states' => [
                    'nude' => ['key' => 'nude', 'label' => 'Nude', 'tags' => 'completely nude, no clothes, naked'],
                    'topless' => ['key' => 'topless', 'label' => 'Topless', 'tags' => 'topless, exposed breasts, nipples'],
                    'bottomless' => ['key' => 'bottomless', 'label' => 'Bottomless', 'tags' => 'bottomless, exposed pussy, no panties'],
                    'open_clothes' => ['key' => 'open_clothes', 'label' => 'Open clothes', 'tags' => 'open clothes, clothes pulled aside, wardrobe malfunction'],
                    'lingerie' => ['key' => 'lingerie', 'label' => 'Lingerie', 'tags' => 'lingerie, lace underwear, seductive lingerie'],
                    'micro_bikini' => ['key' => 'micro_bikini', 'label' => 'Micro bikini', 'tags' => 'micro bikini, tiny bikini, barely covered, revealing swimwear'],
                    'crotchless' => ['key' => 'crotchless', 'label' => 'Crotchless', 'tags' => 'crotchless panties, exposed pussy, lingerie pulled open'],
                    'panties_aside' => ['key' => 'panties_aside', 'label' => 'Panties aside', 'tags' => 'panties pulled aside, underwear moved aside, exposed pussy'],
                    'clothes_lifted' => ['key' => 'clothes_lifted', 'label' => 'Clothes lifted', 'tags' => 'clothes lifted, outfit lifted, exposed body under clothes'],
                    'shirt_lift' => ['key' => 'shirt_lift', 'label' => 'Shirt lift', 'tags' => 'shirt lift, lifted shirt, exposed breasts, underboob'],
                    'open_shirt' => ['key' => 'open_shirt', 'label' => 'Open shirt', 'tags' => 'open shirt, unbuttoned shirt, exposed chest, loose clothing'],
                    'torn_clothes' => ['key' => 'torn_clothes', 'label' => 'Torn clothes', 'tags' => 'torn clothes, ripped outfit, exposed skin, disheveled clothing'],
                    'wet_clothes' => ['key' => 'wet_clothes', 'label' => 'Wet clothes', 'tags' => 'wet clothes, soaked fabric, clinging clothes, water droplets'],
                    'see_through' => ['key' => 'see_through', 'label' => 'See-through', 'tags' => 'see-through clothing, transparent fabric, visible body through clothes'],
                    'naked_apron' => ['key' => 'naked_apron', 'label' => 'Naked apron', 'tags' => 'naked apron, apron only, bare skin, covered front'],
                    'collar_only' => ['key' => 'collar_only', 'label' => 'Collar only', 'tags' => 'collar only, naked with collar, no clothes, neck collar'],
                ],
                'cameras' => [
                    'pov' => ['key' => 'pov', 'label' => 'POV', 'tags' => 'pov, first-person view, intimate camera angle'],
                    'close_up' => ['key' => 'close_up', 'label' => 'Close up', 'tags' => 'close-up, tight framing, explicit detail'],
                    'medium_shot' => ['key' => 'medium_shot', 'label' => 'Medium shot', 'tags' => 'medium shot, clear pose, upper body and hips visible'],
                    'full_body' => ['key' => 'full_body', 'label' => 'Full body', 'tags' => 'full body, pose readable, entire body visible'],
                    'low_angle' => ['key' => 'low_angle', 'label' => 'Low angle', 'tags' => 'low angle, legs foreground, intimate perspective'],
                    'back_view' => ['key' => 'back_view', 'label' => 'Back view', 'tags' => 'back view, rear angle, hips and ass visible'],
                    'bedside' => ['key' => 'bedside', 'label' => 'Bedside', 'tags' => 'bedside angle, bedroom scene, intimate framing'],
                ],
                'scene_intents' => [
                    'solo' => ['key' => 'solo', 'label' => 'Solo', 'tags' => 'solo, no partner visible, solo focus', 'negative_tags' => 'second person, partner, couple, multiple people'],
                    'implied_pov' => ['key' => 'implied_pov', 'label' => 'Implied POV', 'tags' => 'pov, implied partner, first-person framing, partner mostly offscreen, clear act focus', 'negative_tags' => 'full second body, crowded scene, multiple partners, unclear contact point'],
                    'visible_partner' => ['key' => 'visible_partner', 'label' => 'Visible partner', 'tags' => 'visible partner, couple scene, two-person composition, clear body contact', 'negative_tags' => 'solo, partner cropped out, empty space'],
                    'close_contact' => ['key' => 'close_contact', 'label' => 'Close contact', 'tags' => 'close body contact, intimate framing, pressed bodies, contact point visible', 'negative_tags' => 'distant bodies, no body contact, disconnected pose'],
                    'toy_only' => ['key' => 'toy_only', 'label' => 'Toy only', 'tags' => 'solo toy play, sex toy focus, no partner visible', 'negative_tags' => 'partner, penis, oral sex, couple scene'],
                ],
                'effects' => [
                    'afterglow' => ['key' => 'afterglow', 'label' => 'Afterglow', 'group' => 'aftermath', 'tags' => 'afterglow, flushed skin, disheveled hair, relaxed erotic expression', 'negative_tags' => '', 'compatible_act_groups' => 'solo, tease, breast_sex, oral, penetration, anal, toy', 'incompatible_acts' => '', 'requires_scene_intent' => 'solo, implied_pov, visible_partner, close_contact, toy_only'],
                    'messy_aftermath' => ['key' => 'messy_aftermath', 'label' => 'Messy aftermath', 'group' => 'aftermath', 'tags' => 'messy aftermath, disheveled body, rumpled sheets, erotic aftermath', 'negative_tags' => 'clean pristine scene', 'compatible_act_groups' => 'breast_sex, oral, penetration, anal, toy', 'incompatible_acts' => 'nude_pose, straddling_tease', 'requires_scene_intent' => 'implied_pov, visible_partner, close_contact, toy_only'],
                    'wet_skin' => ['key' => 'wet_skin', 'label' => 'Wet skin', 'group' => 'body_detail', 'tags' => 'wet skin, glossy skin, shiny body highlights, sweat sheen', 'negative_tags' => 'dry skin, flat shading', 'compatible_act_groups' => 'solo, tease, breast_sex, oral, penetration, anal, toy, bondage', 'incompatible_acts' => '', 'requires_scene_intent' => ''],
                    'body_fluid_detail' => ['key' => 'body_fluid_detail', 'label' => 'Body fluid detail', 'group' => 'fluids', 'tags' => 'body fluids, wet detail, explicit fluid detail', 'negative_tags' => 'dry clean body', 'compatible_act_groups' => 'breast_sex, oral, penetration, anal, toy', 'incompatible_acts' => 'nude_pose, straddling_tease, bondage_pose', 'requires_scene_intent' => 'implied_pov, visible_partner, close_contact, toy_only'],
                    'finish_on_body' => ['key' => 'finish_on_body', 'label' => 'Finish on body', 'group' => 'fluids', 'tags' => 'cum on body, finish on skin, messy body finish', 'negative_tags' => 'clean body, no fluids', 'compatible_act_groups' => 'breast_sex, oral, penetration, anal, toy', 'incompatible_acts' => 'nude_pose, spread_legs, bondage_pose', 'requires_scene_intent' => 'implied_pov, visible_partner, close_contact'],
                    'exposure_emphasis' => ['key' => 'exposure_emphasis', 'label' => 'Exposure emphasis', 'group' => 'exposure', 'tags' => 'explicit exposure, clear exposed anatomy, uncensored detail', 'negative_tags' => 'censored, mosaic censoring, strategically covered', 'compatible_act_groups' => 'solo, tease, breast_sex, oral, penetration, anal, toy', 'incompatible_acts' => '', 'requires_scene_intent' => ''],
                    'contact_point' => ['key' => 'contact_point', 'label' => 'Contact point', 'group' => 'partner_contact', 'tags' => 'clear contact point, visible body contact, readable interaction', 'negative_tags' => 'unclear contact point, disconnected bodies', 'compatible_act_groups' => 'breast_sex, oral, penetration, anal, toy', 'incompatible_acts' => 'nude_pose, masturbation', 'requires_scene_intent' => 'implied_pov, visible_partner, close_contact, toy_only'],
                    'intense_messy' => ['key' => 'intense_messy', 'label' => 'Intense messy', 'group' => 'messy_intense', 'tags' => 'intense erotic mess, heavy breathing, flushed face, chaotic sheets', 'negative_tags' => 'calm clean pose, distant framing', 'compatible_act_groups' => 'breast_sex, oral, penetration, anal, toy', 'incompatible_acts' => 'nude_pose, straddling_tease', 'requires_scene_intent' => 'implied_pov, visible_partner, close_contact, toy_only'],
                ],
            ],
        ];
        $packLibrary = $this->seedPack()['prompt_library'] ?? [];
        return is_array($packLibrary) && $packLibrary !== []
            ? array_replace_recursive($library, $packLibrary)
            : $library;
    }

    private function normalizePromptLibrary(array $library): array
    {
        $modes = [];
        foreach (($library['modes'] ?? []) as $key => $mode) {
            if (!is_array($mode)) {
                continue;
            }
            $modeKey = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim((string) ($mode['key'] ?? $key))) ?: '';
            if ($modeKey === '') {
                continue;
            }
            $modes[$modeKey] = [
                'key' => $modeKey,
                'label' => trim((string) ($mode['label'] ?? $modeKey)),
                'tags' => trim((string) ($mode['tags'] ?? '')),
                'negative_tags' => trim((string) ($mode['negative_tags'] ?? '')),
            ];
        }
        $defaults = $this->defaultPromptLibrary();
        $modes += $defaults['modes'];
        $modes['hard_nsfw']['tags'] = self::HARD_NSFW_NEUTRAL_TAGS;
        $modes['hard_nsfw']['label'] = trim((string) ($modes['hard_nsfw']['label'] ?? 'Hard NSFW')) ?: 'Hard NSFW';

        $quickTags = [];
        $semanticQuickTags = array_fill_keys($this->semanticQuickTagKeys(), true);
        foreach (($library['quick_tags'] ?? []) as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && !isset($semanticQuickTags[mb_strtolower($tag)]) && !in_array($tag, $quickTags, true)) {
                $quickTags[] = $tag;
            }
        }

        return [
            'modes' => $modes ?: $defaults['modes'],
            'quick_tags' => $quickTags ?: $defaults['quick_tags'],
            'pose_library' => $this->normalizePoseLibrary($library['pose_library'] ?? [], $defaults['pose_library']),
            'nsfw_director' => $this->normalizeNsfwDirector($library['nsfw_director'] ?? []),
        ];
    }

    private function upgradePromptLibraryDefaults(): void
    {
        $stmt = $this->pdo->prepare('SELECT value_json FROM settings WHERE key = :key');
        $stmt->execute(['key' => 'prompt_library']);
        $raw = $stmt->fetchColumn();
        $stored = is_string($raw) ? json_decode($raw, true) : null;
        $normalized = $this->normalizePromptLibrary(is_array($stored) ? $stored : $this->defaultPromptLibrary());
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);
        if ($encoded === false || (is_string($raw) && json_decode($raw, true) === $normalized)) {
            return;
        }

        $save = $this->pdo->prepare(
            'INSERT INTO settings (key, value_json, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
        );
        $save->execute([
            'key' => 'prompt_library',
            'value' => $encoded,
            'updated_at' => gmdate('c'),
        ]);
    }

    private function upgradeKnownMetadataPatches(): void
    {
        $pack = $this->seedPack();
        $fingerprint = SeedPackLoader::fingerprint([
            'lora_library' => $pack['lora_library'] ?? [],
            'lora_variants' => $pack['lora_variants'] ?? [],
        ]);

        $stmt = $this->pdo->prepare('SELECT value_json FROM settings WHERE key = :key');
        $stmt->execute(['key' => 'seed_pack_lora_fingerprint']);
        $raw = $stmt->fetchColumn();
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (($value['fingerprint'] ?? '') === $fingerprint) {
            return;
        }

        $this->applySeedPackLoras($pack);

        $save = $this->pdo->prepare(
            'INSERT INTO settings (key, value_json, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
        );
        $save->execute([
            'key' => 'seed_pack_lora_fingerprint',
            'value' => json_encode(['fingerprint' => $fingerprint], JSON_UNESCAPED_SLASHES),
            'updated_at' => gmdate('c'),
        ]);
    }

    private function semanticQuickTagKeys(): array
    {
        return [
            'blush',
            'bedroom_eyes',
            'spread_legs',
            'legs_open',
            'missionary',
            'doggy_style',
            'cowgirl_position',
            'see_through',
            'no_clothes',
            'completely_nude',
            'exposed_breasts',
            'on_all_fours',
            'on_bed',
            'bdsm',
            'bondage',
        ];
    }

    private function defaultPoseLibrary(): array
    {
        return [
            'arched_back' => ['key' => 'arched_back', 'label' => 'Arched back', 'intensity' => 'suggestive', 'category' => 'tease', 'tags' => 'arched back, sensual pose, accentuated curves, elegant body line', 'negative_tags' => 'stiff pose, hunched back', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'tit_fuck, blowjob, deepthroat'],
            'kneeling_pose' => ['key' => 'kneeling_pose', 'label' => 'Kneeling', 'intensity' => 'suggestive', 'category' => 'kneeling', 'tags' => 'kneeling pose, knees together, submissive posture, intimate framing', 'negative_tags' => 'standing, walking', 'compatible_act_groups' => 'solo, tease, oral', 'incompatible_acts' => 'cowgirl_position, reverse_cowgirl, standing_sex'],
            'sitting_on_bed' => ['key' => 'sitting_on_bed', 'label' => 'Sitting on bed', 'intensity' => 'suggestive', 'category' => 'bed', 'tags' => 'sitting on bed, bedroom pose, inviting posture, soft sheets', 'negative_tags' => 'standing outdoors, classroom', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'standing_sex, wall_sex'],
            'side_lying' => ['key' => 'side_lying', 'label' => 'Side lying', 'intensity' => 'suggestive', 'category' => 'lying', 'tags' => 'side lying pose, reclining body, sensual bedroom pose, curves visible', 'negative_tags' => 'standing pose, sitting upright', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'standing_sex, wall_sex, footjob'],
            'leaning_forward' => ['key' => 'leaning_forward', 'label' => 'Leaning forward', 'intensity' => 'suggestive', 'category' => 'standing', 'tags' => 'leaning forward, cleavage emphasis, teasing posture, close camera', 'negative_tags' => 'leaning back, distant camera', 'compatible_act_groups' => 'solo, tease, breast_sex', 'incompatible_acts' => 'reverse_cowgirl, prone_bone'],
            'hands_above_head' => ['key' => 'hands_above_head', 'label' => 'Hands above head', 'intensity' => 'suggestive', 'category' => 'tease', 'tags' => 'hands above head, exposed torso, elongated body, pin-up pose', 'negative_tags' => 'arms down, crossed arms', 'compatible_act_groups' => 'solo, tease, bondage', 'incompatible_acts' => 'handjob, fingering'],
            'over_shoulder_look' => ['key' => 'over_shoulder_look', 'label' => 'Over shoulder', 'intensity' => 'suggestive', 'category' => 'tease', 'tags' => 'looking over shoulder, seductive glance, back view tease, hips turned', 'negative_tags' => 'front-facing only', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'blowjob, deepthroat, tit_fuck'],
            'hip_pop' => ['key' => 'hip_pop', 'label' => 'Hip pop', 'intensity' => 'suggestive', 'category' => 'standing', 'tags' => 'hip pop pose, one hip raised, confident stance, curvy silhouette', 'negative_tags' => 'stiff symmetrical pose', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'missionary_position, prone_bone, mating_press'],
            'shirt_lift_tease' => ['key' => 'shirt_lift_tease', 'label' => 'Shirt lift', 'intensity' => 'suggestive', 'category' => 'tease', 'tags' => 'shirt lift tease, lifted shirt, teasing exposure, underboob hint', 'negative_tags' => 'shirt down, fully covered torso', 'compatible_act_groups' => 'solo, tease, breast_sex', 'incompatible_acts' => 'deepthroat, footjob'],
            'skirt_lift_tease' => ['key' => 'skirt_lift_tease', 'label' => 'Skirt lift', 'intensity' => 'suggestive', 'category' => 'tease', 'tags' => 'skirt lift tease, lifting skirt, teasing thighs, underwear tease', 'negative_tags' => 'pants, long coat, skirt down', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'tit_fuck, blowjob'],
            'wall_lean' => ['key' => 'wall_lean', 'label' => 'Wall lean', 'intensity' => 'suggestive', 'category' => 'standing', 'tags' => 'leaning against wall, seductive wall pose, one leg bent, intimate framing', 'negative_tags' => 'bed pose, lying down', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'missionary_position, prone_bone'],
            'pov_reaching_hand' => ['key' => 'pov_reaching_hand', 'label' => 'POV reach', 'intensity' => 'suggestive', 'category' => 'pov', 'tags' => 'pov reaching hand, reaching toward viewer, inviting gesture, intimate perspective', 'negative_tags' => 'hands hidden, no eye contact', 'compatible_act_groups' => 'solo, tease, oral, breast_sex', 'incompatible_acts' => 'back_view'],
            'standing_contrapposto' => ['key' => 'standing_contrapposto', 'label' => 'Contrapposto', 'intensity' => 'suggestive', 'category' => 'standing', 'tags' => 'standing contrapposto, weight on one leg, elegant hip curve, relaxed seductive stance', 'negative_tags' => 'stiff pose, symmetrical stance, awkward balance', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'missionary_position, prone_bone, mating_press'],
            'hand_on_hip' => ['key' => 'hand_on_hip', 'label' => 'Hand on hip', 'intensity' => 'suggestive', 'category' => 'standing', 'tags' => 'hand on hip, confident pose, hip emphasis, stylish pin-up stance', 'negative_tags' => 'arms hidden, hands behind back', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'handjob, fingering'],
            'arms_crossed_under_breasts' => ['key' => 'arms_crossed_under_breasts', 'label' => 'Underbreast arms', 'intensity' => 'suggestive', 'category' => 'breast_tease', 'tags' => 'arms crossed under breasts, pushed up chest, cleavage emphasis, confident teasing pose', 'negative_tags' => 'arms raised, chest hidden', 'compatible_act_groups' => 'solo, tease, breast_sex', 'incompatible_acts' => 'handjob, fingering, footjob'],
            'hair_pull_pose' => ['key' => 'hair_pull_pose', 'label' => 'Hair pull', 'intensity' => 'suggestive', 'category' => 'tease', 'tags' => 'hand in hair, pulling hair back, exposed neck, sultry pose', 'negative_tags' => 'messy hands, hair covering face', 'compatible_act_groups' => 'solo, tease, oral, breast_sex', 'incompatible_acts' => 'handjob, fingering'],
            'finger_to_lips' => ['key' => 'finger_to_lips', 'label' => 'Finger to lips', 'intensity' => 'suggestive', 'category' => 'expression', 'tags' => 'finger to lips, shushing gesture, teasing mouth focus, seductive eye contact', 'negative_tags' => 'finger in mouth, eating, biting finger', 'compatible_act_groups' => 'solo, tease, oral', 'incompatible_acts' => 'handjob, footjob'],
            'thigh_squeeze' => ['key' => 'thigh_squeeze', 'label' => 'Thigh squeeze', 'intensity' => 'suggestive', 'category' => 'sitting', 'tags' => 'thigh squeeze pose, thighs pressed together, shy sensual posture, seated tease', 'negative_tags' => 'legs wide open, crossed legs', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'spread_legs, cowgirl_position, reverse_cowgirl'],
            'sitting_crossed_legs' => ['key' => 'sitting_crossed_legs', 'label' => 'Crossed legs', 'intensity' => 'suggestive', 'category' => 'sitting', 'tags' => 'sitting crossed legs, elegant seated pose, thigh emphasis, composed seductive posture', 'negative_tags' => 'standing, lying down, legs spread', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'spread_legs, doggy_style, prone_bone'],
            'sitting_spread_tease' => ['key' => 'sitting_spread_tease', 'label' => 'Seated tease', 'intensity' => 'suggestive', 'category' => 'sitting', 'tags' => 'sitting with knees apart, teasing seated pose, open hips, confident body language', 'negative_tags' => 'knees together, crossed legs, standing', 'compatible_act_groups' => 'solo, tease, toy', 'incompatible_acts' => 'tit_fuck, prone_bone'],
            'lying_on_stomach' => ['key' => 'lying_on_stomach', 'label' => 'On stomach', 'intensity' => 'suggestive', 'category' => 'lying', 'tags' => 'lying on stomach, legs bent up, relaxed bedroom pose, playful sensual framing', 'negative_tags' => 'standing, sitting upright, face down hidden', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'cowgirl_position, reverse_cowgirl, standing_sex'],
            'knees_together_shy' => ['key' => 'knees_together_shy', 'label' => 'Knees together', 'intensity' => 'suggestive', 'category' => 'shy', 'tags' => 'knees together, shy seated pose, demure teasing posture, soft body language', 'negative_tags' => 'legs spread, aggressive pose', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'spread_legs, doggy_style, standing_doggy'],
            'one_leg_up' => ['key' => 'one_leg_up', 'label' => 'One leg up', 'intensity' => 'suggestive', 'category' => 'standing', 'tags' => 'one leg raised, foot on chair, thigh emphasis, stylish teasing pose', 'negative_tags' => 'both feet flat, walking pose', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'missionary_position, prone_bone'],
            'back_arch_standing' => ['key' => 'back_arch_standing', 'label' => 'Standing arch', 'intensity' => 'suggestive', 'category' => 'standing', 'tags' => 'standing back arch, chest forward, elegant body curve, sensual silhouette', 'negative_tags' => 'hunched back, stiff posture', 'compatible_act_groups' => 'solo, tease, breast_sex', 'incompatible_acts' => 'prone_bone, doggy_style'],
            'mirror_selfie_pose' => ['key' => 'mirror_selfie_pose', 'label' => 'Mirror pose', 'intensity' => 'suggestive', 'category' => 'mirror', 'tags' => 'mirror selfie pose, posing in mirror, confident bedroom framing, stylish tease', 'negative_tags' => 'no mirror, crowded background, phone covering face', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'visible_partner, double_penetration'],
            'bedroom_pinup' => ['key' => 'bedroom_pinup', 'label' => 'Bedroom pin-up', 'intensity' => 'suggestive', 'category' => 'bed', 'tags' => 'bedroom pin-up pose, soft sheets, playful sensual pose, polished pin-up composition', 'negative_tags' => 'outdoors, classroom, harsh action pose', 'compatible_act_groups' => 'solo, tease, breast_sex', 'incompatible_acts' => 'standing_sex, wall_sex'],
            'covering_breasts_tease' => ['key' => 'covering_breasts_tease', 'label' => 'Covering breasts', 'intensity' => 'suggestive', 'category' => 'breast_tease', 'tags' => 'covering breasts with hands, teasing modesty, bare shoulders, suggestive chest framing', 'negative_tags' => 'hands away from chest, fully clothed chest', 'compatible_act_groups' => 'solo, tease, breast_sex', 'incompatible_acts' => 'handjob, fingering, footjob'],
            'pulling_clothes_tease' => ['key' => 'pulling_clothes_tease', 'label' => 'Pulling clothes', 'intensity' => 'suggestive', 'category' => 'clothing_tease', 'tags' => 'pulling clothes aside, teasing wardrobe adjustment, partial exposure, playful reveal', 'negative_tags' => 'perfectly arranged clothes, fully covered', 'compatible_act_groups' => 'solo, tease, breast_sex', 'incompatible_acts' => 'doggy_style, prone_bone'],
            'looking_back_bed' => ['key' => 'looking_back_bed', 'label' => 'Looking back bed', 'intensity' => 'suggestive', 'category' => 'bed', 'tags' => 'looking back over shoulder on bed, hips turned, bedroom tease, inviting glance', 'negative_tags' => 'front-facing only, standing outdoors', 'compatible_act_groups' => 'solo, tease, penetration, anal', 'incompatible_acts' => 'tit_fuck, blowjob, deepthroat'],
            'reaching_to_viewer' => ['key' => 'reaching_to_viewer', 'label' => 'Reaching viewer', 'intensity' => 'suggestive', 'category' => 'pov', 'tags' => 'reaching toward viewer, hand extended, intimate invitation, eye contact', 'negative_tags' => 'arms down, hands hidden, looking away', 'compatible_act_groups' => 'solo, tease, oral, breast_sex', 'incompatible_acts' => 'back_view'],
            'ass_up' => ['key' => 'ass_up', 'label' => 'Ass up', 'intensity' => 'explicit', 'category' => 'tease', 'tags' => 'ass up, hips raised, back arched, explicit teasing pose, rear emphasis', 'negative_tags' => 'hips down, sitting upright', 'compatible_act_groups' => 'tease, penetration, anal', 'incompatible_acts' => 'tit_fuck, blowjob, deepthroat, handjob'],
            'spread_knees' => ['key' => 'spread_knees', 'label' => 'Spread knees', 'intensity' => 'explicit', 'category' => 'sitting', 'tags' => 'spread knees, thighs apart, explicit seated pose, open hips', 'negative_tags' => 'closed knees, crossed legs', 'compatible_act_groups' => 'solo, tease, toy', 'incompatible_acts' => 'tit_fuck, footjob'],
            'all_fours_tease' => ['key' => 'all_fours_tease', 'label' => 'All fours tease', 'intensity' => 'explicit', 'category' => 'kneeling', 'tags' => 'on all fours tease, arched back, hips raised, explicit pose clarity', 'negative_tags' => 'standing, sitting upright', 'compatible_act_groups' => 'tease, penetration, anal', 'incompatible_acts' => 'tit_fuck, cowgirl_position, reverse_cowgirl'],
            'chair_straddle' => ['key' => 'chair_straddle', 'label' => 'Chair straddle', 'intensity' => 'explicit', 'category' => 'sitting', 'tags' => 'straddling chair, chair straddle, explicit seated tease, thighs apart', 'negative_tags' => 'standing, lying down', 'compatible_act_groups' => 'tease, solo', 'incompatible_acts' => 'missionary_position, doggy_style, tit_fuck'],
            'table_bent_over' => ['key' => 'table_bent_over', 'label' => 'Bent over table', 'intensity' => 'explicit', 'category' => 'standing', 'tags' => 'bent over table, hips back, explicit bent over pose, hands on table', 'negative_tags' => 'standing upright, sitting', 'compatible_act_groups' => 'penetration, anal, tease', 'incompatible_acts' => 'tit_fuck, cowgirl_position, blowjob, deepthroat'],
            'on_bed_inviting' => ['key' => 'on_bed_inviting', 'label' => 'On bed inviting', 'intensity' => 'explicit', 'category' => 'bed', 'tags' => 'on bed inviting pose, legs apart, erotic bedroom framing, seductive body language', 'negative_tags' => 'standing outdoors, distant camera', 'compatible_act_groups' => 'solo, tease, penetration, toy', 'incompatible_acts' => 'wall_sex, standing_sex'],
            'breast_squeeze_pose' => ['key' => 'breast_squeeze_pose', 'label' => 'Breast squeeze', 'intensity' => 'explicit', 'category' => 'tease', 'tags' => 'breast squeeze pose, hands on breasts, cleavage emphasis, explicit breast focus', 'negative_tags' => 'hands away from chest', 'compatible_act_groups' => 'solo, tease, breast_sex', 'incompatible_acts' => 'handjob, footjob, anal_sex'],
            'hand_between_thighs' => ['key' => 'hand_between_thighs', 'label' => 'Hand between thighs', 'intensity' => 'explicit', 'category' => 'solo', 'tags' => 'hand between thighs, self touch tease, intimate hand placement, erotic solo pose', 'negative_tags' => 'hands visible away from body', 'compatible_act_groups' => 'solo, tease, toy', 'incompatible_acts' => 'tit_fuck, blowjob, footjob'],
            'legs_open_tease' => ['key' => 'legs_open_tease', 'label' => 'Legs open tease', 'intensity' => 'explicit', 'category' => 'lying', 'tags' => 'legs open tease, thighs spread, explicit invitation pose, open hips', 'negative_tags' => 'closed legs, crossed legs', 'compatible_act_groups' => 'solo, tease, penetration, toy', 'incompatible_acts' => 'tit_fuck, blowjob'],
            'crawling_pose' => ['key' => 'crawling_pose', 'label' => 'Crawling', 'intensity' => 'explicit', 'category' => 'kneeling', 'tags' => 'crawling pose, on hands and knees, seductive crawl, low camera', 'negative_tags' => 'standing upright, sitting', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'tit_fuck, cowgirl_position'],
            'kneeling_spread_thighs' => ['key' => 'kneeling_spread_thighs', 'label' => 'Kneeling spread', 'intensity' => 'explicit', 'category' => 'kneeling', 'tags' => 'kneeling with thighs apart, open hips, explicit kneeling tease, readable body pose', 'negative_tags' => 'knees together, standing, crossed legs', 'compatible_act_groups' => 'solo, tease, toy, oral', 'incompatible_acts' => 'tit_fuck, cowgirl_position, reverse_cowgirl'],
            'bed_legs_raised' => ['key' => 'bed_legs_raised', 'label' => 'Legs raised bed', 'intensity' => 'explicit', 'category' => 'bed', 'tags' => 'lying on bed with legs raised, open hips, explicit exposure pose, bedroom framing', 'negative_tags' => 'standing, legs down, closed legs', 'compatible_act_groups' => 'solo, tease, toy, penetration', 'incompatible_acts' => 'tit_fuck, blowjob, standing_sex'],
            'on_back_legs_open' => ['key' => 'on_back_legs_open', 'label' => 'On back open', 'intensity' => 'explicit', 'category' => 'lying', 'tags' => 'lying on back, legs open, open hips, explicit invitation pose, readable torso', 'negative_tags' => 'face down, legs closed, standing', 'compatible_act_groups' => 'solo, tease, toy, penetration', 'incompatible_acts' => 'tit_fuck, doggy_style, standing_doggy'],
            'side_lying_legs_open' => ['key' => 'side_lying_legs_open', 'label' => 'Side open', 'intensity' => 'explicit', 'category' => 'lying', 'tags' => 'side lying with one leg raised, open hips, explicit side pose, curved silhouette', 'negative_tags' => 'standing, legs closed, front-only pose', 'compatible_act_groups' => 'solo, tease, toy, penetration', 'incompatible_acts' => 'tit_fuck, blowjob, deepthroat'],
            'hands_bound_overhead_pose' => ['key' => 'hands_bound_overhead_pose', 'label' => 'Hands bound overhead', 'intensity' => 'explicit', 'category' => 'bondage', 'tags' => 'hands bound overhead pose, arms raised, exposed torso, restrained pin-up composition', 'negative_tags' => 'arms down, free hands, violent struggle', 'compatible_act_groups' => 'solo, tease, bondage', 'incompatible_acts' => 'handjob, fingering, footjob'],
            'ass_presenting' => ['key' => 'ass_presenting', 'label' => 'Ass presenting', 'intensity' => 'explicit', 'category' => 'rear_tease', 'tags' => 'ass presenting pose, hips pushed back, rear emphasis, explicit tease, arched lower back', 'negative_tags' => 'front portrait, hips tucked, sitting upright', 'compatible_act_groups' => 'solo, tease, anal, penetration', 'incompatible_acts' => 'tit_fuck, blowjob, deepthroat'],
            'bent_over_bed' => ['key' => 'bent_over_bed', 'label' => 'Bent over bed', 'intensity' => 'explicit', 'category' => 'bed', 'tags' => 'bent over bed, hips back, hands on mattress, explicit rear tease', 'negative_tags' => 'standing upright, sitting on chair', 'compatible_act_groups' => 'tease, penetration, anal', 'incompatible_acts' => 'tit_fuck, blowjob, deepthroat, cowgirl_position'],
            'bent_over_chair' => ['key' => 'bent_over_chair', 'label' => 'Bent over chair', 'intensity' => 'explicit', 'category' => 'standing', 'tags' => 'bent over chair, hands braced on chair, hips back, explicit standing tease', 'negative_tags' => 'sitting normally, lying down', 'compatible_act_groups' => 'tease, penetration, anal', 'incompatible_acts' => 'tit_fuck, missionary_position, cowgirl_position'],
            'floor_crawl' => ['key' => 'floor_crawl', 'label' => 'Floor crawl', 'intensity' => 'explicit', 'category' => 'kneeling', 'tags' => 'crawling on floor, low angle, seductive crawl, explicit body line', 'negative_tags' => 'standing upright, chair sitting', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'tit_fuck, cowgirl_position'],
            'squatting_tease' => ['key' => 'squatting_tease', 'label' => 'Squatting tease', 'intensity' => 'explicit', 'category' => 'squat', 'tags' => 'squatting tease, thighs apart, low pose, explicit exposure framing', 'negative_tags' => 'standing tall, lying down, knees together', 'compatible_act_groups' => 'solo, tease, toy', 'incompatible_acts' => 'tit_fuck, missionary_position, prone_bone'],
            'one_knee_up_explicit' => ['key' => 'one_knee_up_explicit', 'label' => 'One knee up', 'intensity' => 'explicit', 'category' => 'sitting', 'tags' => 'one knee raised, open hip angle, explicit seated tease, thigh emphasis', 'negative_tags' => 'both knees down, standing, legs hidden', 'compatible_act_groups' => 'solo, tease, toy', 'incompatible_acts' => 'tit_fuck, prone_bone'],
            'panty_aside_pose' => ['key' => 'panty_aside_pose', 'label' => 'Panty aside pose', 'intensity' => 'explicit', 'category' => 'clothing_tease', 'tags' => 'panties pulled aside pose, teasing exposure, hand at waistband, explicit clothing tease', 'negative_tags' => 'panties centered, fully covered, no hand at waistband', 'compatible_act_groups' => 'solo, tease, toy', 'incompatible_acts' => 'tit_fuck, blowjob, footjob'],
            'topless_breast_hold' => ['key' => 'topless_breast_hold', 'label' => 'Breast hold', 'intensity' => 'explicit', 'category' => 'breast_tease', 'tags' => 'holding breasts, topless pose, exposed chest, explicit breast tease, cleavage centered', 'negative_tags' => 'hands away from chest, covered breasts, shirt closed', 'compatible_act_groups' => 'solo, tease, breast_sex', 'incompatible_acts' => 'handjob, footjob, anal_sex'],
            'nude_standing_spread' => ['key' => 'nude_standing_spread', 'label' => 'Standing spread', 'intensity' => 'explicit', 'category' => 'standing', 'tags' => 'nude standing pose, legs slightly apart, open hips, explicit full body exposure', 'negative_tags' => 'clothed, sitting, legs crossed', 'compatible_act_groups' => 'solo, tease', 'incompatible_acts' => 'missionary_position, prone_bone, doggy_style'],
            'toy_display_pose' => ['key' => 'toy_display_pose', 'label' => 'Toy display', 'intensity' => 'explicit', 'category' => 'toy', 'tags' => 'holding sex toy, toy display pose, teasing prop focus, explicit solo presentation', 'negative_tags' => 'no toy, hidden hands, partner toy use', 'compatible_act_groups' => 'solo, tease, toy', 'incompatible_acts' => 'tit_fuck, blowjob, doggy_style'],
            'pov_between_legs' => ['key' => 'pov_between_legs', 'label' => 'POV between legs', 'intensity' => 'explicit', 'category' => 'pov', 'tags' => 'pov between legs framing, thighs framing view, open hips, explicit perspective tease', 'negative_tags' => 'distant camera, closed legs, full crowd scene', 'compatible_act_groups' => 'solo, tease, toy, oral', 'incompatible_acts' => 'tit_fuck, back_view'],
            'tabletop_pose' => ['key' => 'tabletop_pose', 'label' => 'Tabletop pose', 'intensity' => 'explicit', 'category' => 'table', 'tags' => 'lying on tabletop, hips near edge, explicit display pose, legs posed clearly', 'negative_tags' => 'sitting at table, food focus, standing alone', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'tit_fuck, blowjob, deepthroat'],
            'wall_pressed_tease' => ['key' => 'wall_pressed_tease', 'label' => 'Wall pressed', 'intensity' => 'explicit', 'category' => 'standing', 'tags' => 'pressed against wall tease, one leg lifted, body arched to wall, explicit standing display', 'negative_tags' => 'lying down, no wall, distant framing', 'compatible_act_groups' => 'solo, tease, penetration', 'incompatible_acts' => 'missionary_position, prone_bone, tit_fuck'],
        ];
    }

    private function normalizeNsfwDirector(array $director): array
    {
        $defaults = $this->defaultPromptLibrary()['nsfw_director'];
        $storedVersion = (int) ($director['version'] ?? 0);
        $normalized = [
            'version' => 6,
            'intensities' => $this->normalizeDirectorList($director['intensities'] ?? [], $defaults['intensities'], ['hard_tags']),
            'acts' => $this->normalizeActs($director['acts'] ?? [], $defaults['acts']),
            'focuses' => $this->normalizeDirectorList($director['focuses'] ?? [], $defaults['focuses']),
            'expressions' => $this->normalizeDirectorList($director['expressions'] ?? [], $defaults['expressions']),
            'clothing_states' => $this->normalizeDirectorList($director['clothing_states'] ?? [], $defaults['clothing_states']),
            'cameras' => $this->normalizeDirectorList($director['cameras'] ?? [], $defaults['cameras']),
            'scene_intents' => $this->normalizeDirectorList($director['scene_intents'] ?? [], $defaults['scene_intents'], ['negative_tags']),
            'effects' => $this->normalizeDirectorList($director['effects'] ?? [], $defaults['effects'], ['group', 'negative_tags', 'compatible_act_groups', 'incompatible_acts', 'requires_scene_intent']),
        ];

        if ($storedVersion < 3) {
            $normalized['acts'] += $this->normalizeActs($defaults['acts'], $defaults['acts']);
            $normalized['expressions'] += $this->normalizeDirectorList($defaults['expressions'], $defaults['expressions']);
            $normalized['clothing_states'] += $this->normalizeDirectorList($defaults['clothing_states'], $defaults['clothing_states']);
            $normalized['scene_intents'] += $this->normalizeDirectorList($defaults['scene_intents'], $defaults['scene_intents'], ['negative_tags']);
        }

        if ($storedVersion < 4) {
            $normalized['expressions'] += $this->normalizeDirectorList($defaults['expressions'], $defaults['expressions']);
        }

        if ($storedVersion < 5) {
            $normalized['effects'] += $this->normalizeDirectorList($defaults['effects'], $defaults['effects'], ['group', 'negative_tags', 'compatible_act_groups', 'incompatible_acts', 'requires_scene_intent']);
        }

        $hardTags = mb_strtolower((string) ($normalized['intensities']['hard']['tags'] ?? ''));
        if (str_contains($hardTags, 'explicit sex') || str_contains($hardTags, 'hardcore')) {
            $normalized['intensities']['hard']['tags'] = $defaults['intensities']['hard']['tags'];
        }

        return $normalized;
    }

    private function normalizeDirectorList(array $items, array $fallback, array $extraFields = []): array
    {
        $normalized = [];
        foreach ($items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemKey = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim((string) ($item['key'] ?? $key))) ?: '';
            if ($itemKey === '') {
                continue;
            }
            $record = [
                'key' => $itemKey,
                'label' => trim((string) ($item['label'] ?? $itemKey)),
                'tags' => trim((string) ($item['tags'] ?? '')),
            ];
            foreach ($extraFields as $field) {
                $record[$field] = trim((string) ($item[$field] ?? ''));
            }
            $normalized[$itemKey] = $record;
        }
        return $normalized ?: $fallback;
    }

    private function normalizePoseLibrary(array $items, array $fallback): array
    {
        $normalized = [];
        foreach ($items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $poseKey = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim((string) ($item['key'] ?? $key))) ?: '';
            if ($poseKey === '') {
                continue;
            }
            $intensity = trim((string) ($item['intensity'] ?? 'suggestive'));
            if (!in_array($intensity, ['suggestive', 'explicit'], true)) {
                $intensity = 'suggestive';
            }
            $normalized[$poseKey] = [
                'key' => $poseKey,
                'label' => trim((string) ($item['label'] ?? $poseKey)),
                'intensity' => $intensity,
                'category' => trim((string) ($item['category'] ?? 'tease')),
                'tags' => trim((string) ($item['tags'] ?? '')),
                'negative_tags' => trim((string) ($item['negative_tags'] ?? '')),
                'compatible_act_groups' => trim((string) ($item['compatible_act_groups'] ?? '')),
                'incompatible_acts' => trim((string) ($item['incompatible_acts'] ?? '')),
            ];
        }
        return $normalized + $fallback;
    }

    private function normalizeActs(array $acts, array $fallback): array
    {
        $normalized = [];
        $extras = $this->defaultActExtras();
        foreach ($acts as $key => $act) {
            if (!is_array($act)) {
                continue;
            }
            $actKey = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim((string) ($act['key'] ?? $key))) ?: '';
            if ($actKey === '') {
                continue;
            }
            $record = [
                'key' => $actKey,
                'label' => trim((string) ($act['label'] ?? $actKey)),
                'aliases' => trim((string) ($act['aliases'] ?? '')),
                'positive_tags' => trim((string) ($act['positive_tags'] ?? '')),
                'hard_tags' => trim((string) ($act['hard_tags'] ?? '')),
                'negative_disambiguation' => trim((string) ($act['negative_disambiguation'] ?? '')),
                'recommended_focus' => trim((string) ($act['recommended_focus'] ?? '')),
                'recommended_camera' => trim((string) ($act['recommended_camera'] ?? '')),
                'conflict_negatives' => trim((string) ($act['conflict_negatives'] ?? ($extras[$actKey]['conflict_negatives'] ?? ''))),
                'act_group' => trim((string) ($act['act_group'] ?? ($extras[$actKey]['act_group'] ?? $this->inferActGroup($actKey)))),
                'scene_intent' => trim((string) ($act['scene_intent'] ?? ($extras[$actKey]['scene_intent'] ?? 'implied_pov'))),
                'anatomy_tags' => trim((string) ($act['anatomy_tags'] ?? ($extras[$actKey]['anatomy_tags'] ?? 'clear pose, anatomically coherent, visible contact point'))),
                'strict_tags' => trim((string) ($act['strict_tags'] ?? ($extras[$actKey]['strict_tags'] ?? 'single selected act, no mixed sex acts, clear act focus'))),
            ];
            if ($record['strict_tags'] === 'solo nude pose, clean body silhouette, no sex act') {
                $record['strict_tags'] = 'solo nude pose, clean body silhouette, artistic adult nude posing';
            }
            $normalized[$actKey] = $record;
        }
        foreach ($fallback as $key => $act) {
            if (isset($normalized[$key]) || !is_array($act)) {
                continue;
            }
            $normalized[$key] = array_replace([
                'conflict_negatives' => $extras[$key]['conflict_negatives'] ?? '',
                'act_group' => $extras[$key]['act_group'] ?? $this->inferActGroup((string) $key),
                'scene_intent' => $extras[$key]['scene_intent'] ?? 'implied_pov',
                'anatomy_tags' => $extras[$key]['anatomy_tags'] ?? 'clear pose, anatomically coherent, visible contact point',
                'strict_tags' => $extras[$key]['strict_tags'] ?? 'single selected act, no mixed sex acts, clear act focus',
            ], $act);
        }
        return $normalized;
    }

    private function defaultActExtras(): array
    {
        $anatomy = 'clear pose, anatomically coherent, visible contact point, correct limb placement, no extra limbs';
        $strict = 'single selected act, no mixed sex acts, clear act focus, explicit action clarity';
        return [
            'nude_pose' => ['conflict_negatives' => 'sex act, penetration, oral sex, dildo, vibrator, partner sex, bondage', 'scene_intent' => 'solo', 'anatomy_tags' => $anatomy, 'strict_tags' => 'solo nude pose, clean body silhouette, artistic adult nude posing'],
            'spread_legs' => ['conflict_negatives' => 'closed legs, oral sex, blowjob, dildo, vibrator, doggy style, cowgirl position', 'scene_intent' => 'solo', 'anatomy_tags' => $anatomy, 'strict_tags' => 'spread legs pose only, legs clearly open, clear hips'],
            'missionary_position' => ['conflict_negatives' => 'cowgirl position, reverse cowgirl, doggy style, oral sex, blowjob, dildo, sex toys, standing sex', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => $strict],
            'cowgirl_position' => ['conflict_negatives' => 'western theme, horse riding, standing sex, doggy style, missionary position, oral sex, blowjob, toy insertion, dildo, vibrator', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'woman on top only, straddling partner, riding position, clear hips over partner'],
            'reverse_cowgirl' => ['conflict_negatives' => 'western theme, horse riding, missionary position, doggy style, oral sex, blowjob, toy insertion, dildo, face to face', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'reverse cowgirl only, woman on top facing away, clear back view'],
            'doggy_style' => ['conflict_negatives' => 'dog, animal, oral sex, blowjob, cowgirl position, missionary position, sex toys, dildo, standing sex', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'doggy style sex position only, on all fours, from behind contact'],
            'from_behind' => ['conflict_negatives' => 'oral sex, blowjob, cowgirl position, missionary position, sex toys, dildo, standing alone, no contact', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'from behind sex position only, rear contact, clear hips'],
            'standing_sex' => ['conflict_negatives' => 'lying down, bed pose, missionary position, cowgirl position, doggy style, oral sex, sex toys, dildo', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'standing sex position only, upright bodies, clear body contact'],
            'sitting_sex' => ['conflict_negatives' => 'standing sex, lying down, doggy style, missionary position, oral sex, sex toys, dildo', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'seated sex position only, straddling lap, clear seated contact'],
            'lap_sitting' => ['conflict_negatives' => 'standing sex, doggy style, missionary position, oral sex, blowjob, sex toys, dildo, innocent lap sitting', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'adult lap sitting only, intimate lap contact, straddling lap'],
            'blowjob' => ['conflict_negatives' => 'dildo, vibrator, vaginal penetration, anal penetration, tit fuck, handjob, cowgirl position, doggy style, missionary position', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'oral sex only, mouth contact, no toy insertion'],
            'deepthroat' => ['conflict_negatives' => 'dildo, vibrator, vaginal penetration, anal penetration, tit fuck, handjob, cowgirl position, doggy style, missionary position', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'deepthroat only, deep oral contact, mouth and throat focus'],
            'tit_fuck' => ['conflict_negatives' => 'oral sex, blowjob, deepthroat, dildo, vibrator, vaginal penetration, anal penetration, handjob, cowgirl position, reverse cowgirl, woman on top, riding position, straddling partner, straddling sex, doggy style, missionary position, sitting sex, lap sitting, grinding on lap, penis from below, partner below, genital focus, pussy focus, lower body focus', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'tit fuck only, penis between breasts, breasts pressed together, no mouth contact, breast focus, upper body framing, hips cropped out, no lower body sex'],
            'tit_fuck_full_body' => ['conflict_negatives' => 'oral sex, blowjob, deepthroat, dildo, vibrator, vaginal penetration, anal penetration, handjob, cowgirl position, reverse cowgirl, woman on top, riding position, straddling partner, straddling sex, doggy style, missionary position, sitting sex, lap sitting, grinding on lap, penis from below, partner below, pussy focus, lower body penetration focus, cropped torso, close-up crop', 'scene_intent' => 'visible_partner', 'anatomy_tags' => $anatomy . ', full body readable, head-to-toe body clarity', 'strict_tags' => 'full body tit fuck only, penis between breasts, breasts pressed together, no mouth contact, breast contact readable, full body in frame, feet visible, no lower body sex'],
            'masturbation' => ['conflict_negatives' => 'partner sex, penis, oral sex, blowjob, cowgirl position, doggy style, missionary position, dildo insertion, vibrator insertion', 'scene_intent' => 'solo', 'anatomy_tags' => $anatomy, 'strict_tags' => 'solo masturbation only, self touch, no partner'],
            'fingering' => ['conflict_negatives' => 'penis, oral sex, blowjob, dildo, vibrator, cowgirl position, doggy style, missionary position, handjob', 'scene_intent' => 'solo', 'anatomy_tags' => $anatomy, 'strict_tags' => 'fingering only, fingers as contact point, no toy'],
            'handjob' => ['conflict_negatives' => 'oral sex, blowjob, deepthroat, tit fuck, vaginal penetration, anal penetration, dildo, vibrator, cowgirl position, doggy style', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'handjob only, hand around penis, manual contact'],
            'cunnilingus' => ['conflict_negatives' => 'blowjob, deepthroat, dildo, vibrator, vaginal penetration, anal penetration, tit fuck, handjob, cowgirl position', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'cunnilingus only, mouth between thighs, tongue contact'],
            'bondage_pose' => ['conflict_negatives' => 'violence, injury, blood, weapon, distressed, fear, nonconsensual, oral sex, penetration, dildo', 'scene_intent' => 'solo', 'anatomy_tags' => $anatomy, 'strict_tags' => 'consensual bondage pose only, restraints visible, no violent harm'],
            'sex_toys' => ['conflict_negatives' => 'partner penis, oral sex, blowjob, deepthroat, tit fuck, handjob, cowgirl position, doggy style, missionary position', 'scene_intent' => 'toy_only', 'anatomy_tags' => $anatomy, 'strict_tags' => 'sex toy only, toy contact point visible, no partner sex'],
            'prone_bone' => ['conflict_negatives' => 'cowgirl position, missionary position, oral sex, blowjob, sex toys, dildo, standing sex, face sitting', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'prone bone only, face down, rear contact, hips pinned'],
            'mating_press' => ['conflict_negatives' => 'cowgirl position, doggy style, oral sex, blowjob, sex toys, dildo, standing sex, sitting sex', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'mating press only, legs pushed up, folded body, partner above'],
            'spooning_sex' => ['conflict_negatives' => 'cowgirl position, doggy style, missionary position, oral sex, blowjob, sex toys, dildo, standing sex', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'spooning sex only, side lying contact, close bodies'],
            'wall_sex' => ['conflict_negatives' => 'lying down, bed pose, cowgirl position, doggy style, oral sex, blowjob, sex toys, dildo', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'wall sex only, pressed to wall, upright contact'],
            'table_sex' => ['conflict_negatives' => 'floor pose, bed pose, cowgirl position, doggy style, oral sex, blowjob, sex toys, dildo, dining scene', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'table sex only, hips at table edge, clear table surface'],
            'standing_doggy' => ['conflict_negatives' => 'dog, animal, kneeling on all fours, cowgirl position, missionary position, oral sex, blowjob, sex toys, dildo', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'standing doggy only, bent over, rear standing contact'],
            'facesitting' => ['conflict_negatives' => 'chair sitting, solo sitting, cowgirl position, missionary position, dildo, vibrator, penetration, handjob', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'facesitting only, face between thighs, oral contact point'],
            'sixty_nine' => ['conflict_negatives' => 'number text, solo, single oral act, cowgirl position, doggy style, missionary position, dildo, vibrator', 'scene_intent' => 'visible_partner', 'anatomy_tags' => $anatomy, 'strict_tags' => 'sixty nine only, mutual oral position, opposite body directions'],
            'footjob' => ['conflict_negatives' => 'oral sex, blowjob, deepthroat, vaginal penetration, anal penetration, dildo, vibrator, handjob, tit fuck', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'footjob only, feet contact, penis between feet'],
            'thigh_sex' => ['conflict_negatives' => 'oral sex, blowjob, vaginal penetration, anal penetration, dildo, vibrator, handjob, footjob, tit fuck', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'thigh sex only, penis between thighs, thighs pressed together'],
            'assjob' => ['conflict_negatives' => 'anal penetration, vaginal penetration, oral sex, blowjob, dildo, vibrator, handjob, doggy style', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'assjob only, between butt cheeks, no penetration'],
            'anal_sex' => ['conflict_negatives' => 'vaginal sex, oral sex, blowjob, dildo, vibrator, toy insertion, cowgirl position, missionary position', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'anal sex only, anal penetration, ass focus, no vaginal contact'],
            'toy_insertion' => ['conflict_negatives' => 'partner penis, oral sex, blowjob, deepthroat, vaginal sex with penis, anal sex with penis, tit fuck, handjob', 'scene_intent' => 'toy_only', 'anatomy_tags' => $anatomy, 'strict_tags' => 'toy insertion only, dildo contact point, solo toy focus'],
            'vibrator_play' => ['conflict_negatives' => 'partner penis, oral sex, blowjob, deepthroat, dildo insertion, vaginal penetration, anal penetration, tit fuck, handjob', 'scene_intent' => 'toy_only', 'anatomy_tags' => $anatomy, 'strict_tags' => 'vibrator play only, vibrator contact point, no penetration unless explicit'],
            'double_penetration' => ['conflict_negatives' => 'solo, single penetration, anal only, only anal penetration, single penis, one penis only, one insertion point, missing vaginal penetration, vaginal not visible, oral sex only, toy only, clothed tease, nude pose, masturbation', 'scene_intent' => 'visible_partner', 'anatomy_tags' => $anatomy, 'strict_tags' => 'double penetration only, two clear contact points, two visible contact points, both holes occupied, one penis in pussy, one penis in ass, no anal-only composition, multi-partner composition'],
            'breast_smother' => ['conflict_negatives' => 'oral sex, blowjob, deepthroat, penetration, dildo, vibrator, violence, injury, fear', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'breast smother only, face pressed into breasts, breast contact'],
            'grinding' => ['conflict_negatives' => 'penetration, oral sex, blowjob, dildo, vibrator, doggy style, missionary position, cowgirl position', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'grinding only, hips pressed together, clothed rubbing focus'],
            'straddling_tease' => ['conflict_negatives' => 'penetration, oral sex, blowjob, dildo, vibrator, doggy style, missionary position, western theme, horse riding', 'scene_intent' => 'implied_pov', 'anatomy_tags' => $anatomy, 'strict_tags' => 'straddling tease only, teasing straddle, hovering hips'],
            'panty_pull' => ['conflict_negatives' => 'fully nude, no panties, penetration, oral sex, blowjob, dildo, vibrator, doggy style, cowgirl position', 'scene_intent' => 'solo', 'anatomy_tags' => $anatomy, 'strict_tags' => 'panty pull only, underwear pulled aside, teasing exposure'],
            'clothed_sex' => ['conflict_negatives' => 'fully nude, nude pose, oral sex, blowjob, sex toys, dildo, vibrator, masturbation only', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'clothed sex only, clothes pulled aside, partially clothed contact'],
            'shower_sex' => ['conflict_negatives' => 'bath alone, rain scene, swimsuit, oral sex, blowjob, sex toys, dildo, dry bedroom', 'scene_intent' => 'close_contact', 'anatomy_tags' => $anatomy, 'strict_tags' => 'shower sex only, wet bodies, shower setting, close contact'],
        ];
    }

    private function inferActGroup(string $actKey): string
    {
        return match ($actKey) {
            'tit_fuck', 'tit_fuck_full_body', 'breast_smother' => 'breast_sex',
            'blowjob', 'deepthroat', 'cunnilingus', 'facesitting', 'sixty_nine' => 'oral',
            'missionary_position', 'cowgirl_position', 'reverse_cowgirl', 'doggy_style', 'from_behind', 'standing_sex', 'sitting_sex', 'lap_sitting', 'prone_bone', 'mating_press', 'spooning_sex', 'wall_sex', 'table_sex', 'standing_doggy', 'double_penetration', 'shower_sex' => 'penetration',
            'anal_sex' => 'anal',
            'sex_toys', 'toy_insertion', 'vibrator_play' => 'toy',
            'masturbation', 'fingering', 'nude_pose', 'spread_legs' => 'solo',
            'bondage_pose' => 'bondage',
            'footjob', 'thigh_sex', 'assjob', 'grinding', 'straddling_tease', 'panty_pull', 'clothed_sex' => 'tease',
            default => 'tease',
        };
    }

    private function seedCharacterStudioDefaults(): void
    {
        $pack = $this->seedPack();
        $this->seedSeriesFromPack($pack['series'] ?? []);
        $this->seedCharactersFromPack($pack['characters'] ?? []);
        $this->applySeedPackLoras($pack);
    }

    private function seedSeriesFromPack(array $seriesList): void
    {
        foreach ($seriesList as $series) {
            if (!is_array($series) || trim((string) ($series['key'] ?? '')) === '') {
                continue;
            }
            if ($this->getSeriesByKey((string) $series['key'])) {
                continue;
            }
            $this->saveSeries($series + [
                'source_type' => 'seed_pack',
                'description' => 'Seeded editable character catalog.',
                'nsfw_default' => true,
            ]);
        }
    }

    private function seedCharactersFromPack(array $characters): void
    {
        foreach ($characters as $character) {
            if (!is_array($character)) {
                continue;
            }
            $seriesKey = (string) ($character['series_key'] ?? '');
            $series = $this->getSeriesByKey($seriesKey);
            if (!$series) {
                continue;
            }
            $name = (string) ($character['display_name'] ?? $character['full_name'] ?? $character['name'] ?? '');
            $key = (string) ($character['key'] ?? $this->slug($name));
            if ($name === '' || $this->findCharacterBySeriesKey((int) $series['id'], $key)) {
                continue;
            }
            $this->saveCharacter([
                'series_id' => (int) $series['id'],
                'key' => $key,
                'display_name' => $name,
                'full_name' => (string) ($character['full_name'] ?? $name),
                'aliases' => is_array($character['aliases'] ?? null) ? $character['aliases'] : [],
                'feature_tags' => (string) ($character['feature_tags'] ?? ''),
                'adult_framing_tags' => (string) ($character['adult_framing_tags'] ?? self::ADULT_FRAMING_TAGS),
                'base_lora_alias' => (string) ($character['base_lora_alias'] ?? ''),
                'base_lora_weight' => (float) ($character['base_lora_weight'] ?? 1.0),
                'source_type' => 'seed_pack',
                'notes' => (string) ($character['notes'] ?? 'Editable seeded profile. Add a character LoRA in Settings when available.'),
                'appearances' => is_array($character['appearances'] ?? null) ? $character['appearances'] : [],
                'outfits' => is_array($character['outfits'] ?? null) ? $character['outfits'] : [
                    ['name' => 'Ninguno', 'prompt' => ''],
                    ['name' => 'Default style', 'prompt' => 'signature outfit, character accurate outfit'],
                ],
            ]);
        }
    }

    private function applySeedPackLoras(array $pack): void
    {
        foreach (($pack['lora_library'] ?? []) as $lora) {
            if (is_array($lora) && trim((string) ($lora['alias'] ?? '')) !== '') {
                $this->saveLoraLibrary($lora);
            }
        }
        foreach (($pack['lora_variants'] ?? []) as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $alias = (string) ($variant['lora_alias'] ?? $variant['alias'] ?? '');
            if ($alias !== '') {
                $this->saveLoraVariant($alias, $variant);
            }
        }
    }

    private function sanitizeUmaFeatureTags(string $name, string $tags): string
    {
        if ($this->slug($name) !== 'mayano_top_gun') {
            return trim($tags);
        }

        $blocked = [':d', 'one eye closed'];
        $parts = array_filter(array_map('trim', explode(',', $tags)), static function (string $tag) use ($blocked): bool {
            return $tag !== '' && !in_array(mb_strtolower($tag), $blocked, true);
        });

        return implode(', ', array_values($parts));
    }

    private function decodeSeries(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'source_type' => (string) ($row['source_type'] ?? 'manual'),
            'description' => (string) ($row['description'] ?? ''),
            'base_lora_alias' => (string) ($row['base_lora_alias'] ?? ''),
            'base_lora_weight' => (float) ($row['base_lora_weight'] ?? 1),
            'default_negative' => (string) ($row['default_negative'] ?? ''),
            'nsfw_default' => $this->dbBool($row['nsfw_default'] ?? true),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function decodeCharacterRow(array $row): array
    {
        $outfits = $this->getCharacterOutfits((int) $row['id']);
        $appearances = $this->getCharacterAppearances((int) $row['id']);
        return [
            'id' => (int) $row['id'],
            'series_id' => (int) $row['series_id'],
            'series_key' => (string) ($row['series_key'] ?? ''),
            'series_name' => (string) ($row['series_name'] ?? ''),
            'key' => (string) $row['key'],
            'name' => (string) ($row['display_name'] ?? $row['full_name']),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?: $row['display_name']),
            'aliases' => json_decode((string) ($row['aliases_json'] ?? '[]'), true) ?: [],
            'feature_tags' => (string) ($row['feature_tags'] ?? ''),
            'adult_framing_tags' => (string) ($row['adult_framing_tags'] ?: self::ADULT_FRAMING_TAGS),
            'base_lora_alias' => (string) ($row['base_lora_alias'] ?: ($row['series_base_lora_alias'] ?? '')),
            'base_lora_weight' => (float) ($row['base_lora_weight'] ?: ($row['series_base_lora_weight'] ?? 1)),
            'default_negative' => (string) ($row['default_negative'] ?? ''),
            'preview_image' => (string) ($row['preview_image'] ?? ''),
            'source_type' => (string) ($row['source_type'] ?? 'manual'),
            'notes' => (string) ($row['notes'] ?? ''),
            'nsfw_profile' => json_decode((string) ($row['nsfw_profile_json'] ?? '{}'), true) ?: [],
            'outfits' => $outfits,
            'appearances' => $appearances,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function getCharacterOutfits(int $characterId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM character_outfits WHERE character_id = :character_id ORDER BY id');
        $stmt->execute(['character_id' => $characterId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $outfits = [[
            'id' => 0,
            'name' => 'Ninguno',
            'prompt' => '',
            'negative_tags' => '',
            'preview_image' => '',
            'conflict_groups' => '',
        ]];
        foreach ($rows as $row) {
            if ((string) $row['name'] === 'Ninguno' && trim((string) $row['prompt']) === '') {
                continue;
            }
            $outfits[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'prompt' => (string) ($row['prompt'] ?? ''),
                'negative_tags' => (string) ($row['negative_tags'] ?? ''),
                'preview_image' => (string) ($row['preview_image'] ?? ''),
                'conflict_groups' => (string) ($row['conflict_groups'] ?? ''),
            ];
        }
        return $outfits;
    }

    private function replaceCharacterOutfits(int $characterId, array $outfits): void
    {
        if ($characterId <= 0) {
            return;
        }
        $this->pdo->prepare('DELETE FROM character_outfits WHERE character_id = :character_id')->execute(['character_id' => $characterId]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO character_outfits (character_id, name, prompt, negative_tags, preview_image, conflict_groups, created_at, updated_at)
             VALUES (:character_id, :name, :prompt, :negative_tags, :preview_image, :conflict_groups, :created_at, :updated_at)'
            . ' ON CONFLICT(character_id, name) DO UPDATE SET
                prompt = excluded.prompt,
                negative_tags = excluded.negative_tags,
                preview_image = excluded.preview_image,
                conflict_groups = excluded.conflict_groups,
                updated_at = excluded.updated_at'
        );
        $now = gmdate('c');
        foreach ($outfits as $outfit) {
            if (!is_array($outfit)) {
                continue;
            }
            $name = trim((string) ($outfit['name'] ?? ''));
            if ($name === '' || ($name === 'Ninguno' && trim((string) ($outfit['prompt'] ?? '')) === '')) {
                continue;
            }
            $stmt->execute([
                'character_id' => $characterId,
                'name' => $name,
                'prompt' => trim((string) ($outfit['prompt'] ?? '')),
                'negative_tags' => trim((string) ($outfit['negative_tags'] ?? '')),
                'preview_image' => trim((string) ($outfit['preview_image'] ?? '')),
                'conflict_groups' => trim((string) ($outfit['conflict_groups'] ?? '')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function getCharacterAppearances(int $characterId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM character_appearances WHERE character_id = :character_id ORDER BY sort_order, id');
        $stmt->execute(['character_id' => $characterId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'prompt' => (string) ($row['prompt'] ?? ''),
            'negative_tags' => (string) ($row['negative_tags'] ?? ''),
            'preview_image' => (string) ($row['preview_image'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function replaceCharacterAppearances(int $characterId, array $appearances): void
    {
        if ($characterId <= 0) {
            return;
        }
        $this->pdo->prepare('DELETE FROM character_appearances WHERE character_id = :character_id')->execute(['character_id' => $characterId]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO character_appearances (character_id, name, prompt, negative_tags, preview_image, sort_order, created_at, updated_at)
             VALUES (:character_id, :name, :prompt, :negative_tags, :preview_image, :sort_order, :created_at, :updated_at)
             ON CONFLICT(character_id, name) DO UPDATE SET
                prompt = excluded.prompt,
                negative_tags = excluded.negative_tags,
                preview_image = excluded.preview_image,
                sort_order = excluded.sort_order,
                updated_at = excluded.updated_at'
        );
        $now = gmdate('c');
        foreach (array_values($appearances) as $index => $appearance) {
            if (!is_array($appearance)) {
                continue;
            }
            $name = trim((string) ($appearance['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $stmt->execute([
                'character_id' => $characterId,
                'name' => $name,
                'prompt' => trim((string) ($appearance['prompt'] ?? '')),
                'negative_tags' => trim((string) ($appearance['negative_tags'] ?? '')),
                'preview_image' => trim((string) ($appearance['preview_image'] ?? '')),
                'sort_order' => (int) ($appearance['sort_order'] ?? $index),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function getSeriesByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM series WHERE key = :key');
        $stmt->execute(['key' => $this->slug($key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decodeSeries($row) : null;
    }

    private function findCharacterBySeriesKey(int $seriesId, string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, s.key AS series_key, s.name AS series_name, s.base_lora_alias AS series_base_lora_alias, s.base_lora_weight AS series_base_lora_weight
             FROM characters c
             JOIN series s ON s.id = c.series_id
             WHERE c.series_id = :series_id AND c.key = :key'
        );
        $stmt->execute(['series_id' => $seriesId, 'key' => $this->slug($key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decodeCharacterRow($row) : null;
    }

    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
    }

    private function slug(string $value): string
    {
        return trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', mb_strtolower(trim($value))), '_');
    }

    private function decodePreset(array|false $row): array
    {
        if (!$row) {
            return [];
        }
        $row['id'] = (int) $row['id'];
        $row['meta'] = json_decode((string) $row['meta_json'], true) ?: [];
        unset($row['meta_json']);
        return $row;
    }

    private function decodeGalleryItem(array $row): array
    {
        $paths = json_decode((string) $row['image_paths_json'], true) ?: [];
        $payload = json_decode((string) $row['payload_json'], true) ?: [];
        $actualSeed = (int) ($row['actual_seed'] ?? $row['seed'] ?? -1);
        return [
            'id' => (int) $row['id'],
            'uma' => $row['uma'],
            'outfit' => $row['outfit'],
            'prompt' => $row['prompt'],
            'negative_prompt' => $row['negative_prompt'],
            'payload' => $payload,
            'image_paths' => $paths,
            'image_urls' => array_map(static fn (string $path): string => '/generated/' . basename($path), $paths),
            'seed' => $actualSeed > -1 ? $actualSeed : (int) ($row['seed'] ?? -1),
            'request_seed' => (int) ($row['seed'] ?? -1),
            'actual_seed' => $actualSeed,
            'width' => (int) ($row['width'] ?? 0),
            'height' => (int) ($row['height'] ?? 0),
            'parent_gallery_id' => isset($row['parent_gallery_id']) ? (int) $row['parent_gallery_id'] : null,
            'operation' => (string) ($row['operation'] ?? 'generate'),
            'series_id' => (int) ($row['series_id'] ?? 0),
            'series_name' => (string) ($row['series_name'] ?? ''),
            'character_id' => (int) ($row['character_id'] ?? 0),
            'character_name' => (string) ($row['character_name'] ?? $row['uma'] ?? ''),
            'base_lora' => (string) ($row['base_lora'] ?? ''),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'created_at' => $row['created_at'],
        ];
    }

    private function decodeJob(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'payload' => json_decode((string) $row['payload_json'], true) ?: [],
            'progress' => (float) $row['progress'],
            'error' => (string) $row['error'],
            'result' => json_decode((string) $row['result_json'], true) ?: [],
            'created_at' => (string) $row['created_at'],
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
        ];
    }

    private function ensureGalleryColumns(): void
    {
        $columns = [];
        $rows = $this->pdo->query('PRAGMA table_info(gallery)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $columns[(string) $row['name']] = true;
        }

        $add = [
            'actual_seed' => 'ALTER TABLE gallery ADD COLUMN actual_seed INTEGER NOT NULL DEFAULT -1',
            'width' => 'ALTER TABLE gallery ADD COLUMN width INTEGER NOT NULL DEFAULT 0',
            'height' => 'ALTER TABLE gallery ADD COLUMN height INTEGER NOT NULL DEFAULT 0',
            'parent_gallery_id' => 'ALTER TABLE gallery ADD COLUMN parent_gallery_id INTEGER DEFAULT NULL',
            'operation' => 'ALTER TABLE gallery ADD COLUMN operation TEXT NOT NULL DEFAULT "generate"',
            'series_id' => 'ALTER TABLE gallery ADD COLUMN series_id INTEGER NOT NULL DEFAULT 0',
            'series_name' => 'ALTER TABLE gallery ADD COLUMN series_name TEXT NOT NULL DEFAULT ""',
            'character_id' => 'ALTER TABLE gallery ADD COLUMN character_id INTEGER NOT NULL DEFAULT 0',
            'character_name' => 'ALTER TABLE gallery ADD COLUMN character_name TEXT NOT NULL DEFAULT ""',
            'base_lora' => 'ALTER TABLE gallery ADD COLUMN base_lora TEXT NOT NULL DEFAULT ""',
            'source_type' => 'ALTER TABLE gallery ADD COLUMN source_type TEXT NOT NULL DEFAULT ""',
        ];

        foreach ($add as $column => $sql) {
            if (!isset($columns[$column])) {
                $this->pdo->exec($sql);
            }
        }
    }
}
