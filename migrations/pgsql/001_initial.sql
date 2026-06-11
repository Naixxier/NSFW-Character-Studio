CREATE TABLE IF NOT EXISTS schema_migrations (
    version TEXT PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS lora_triggers (
    alias TEXT PRIMARY KEY,
    trigger_words TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS presets (
    id BIGSERIAL PRIMARY KEY,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    meta_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS series (
    id BIGSERIAL PRIMARY KEY,
    key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    source_type TEXT NOT NULL DEFAULT 'manual',
    description TEXT NOT NULL DEFAULT '',
    base_lora_alias TEXT NOT NULL DEFAULT '',
    base_lora_weight DOUBLE PRECISION NOT NULL DEFAULT 1,
    default_negative TEXT NOT NULL DEFAULT '',
    nsfw_default BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS characters (
    id BIGSERIAL PRIMARY KEY,
    series_id BIGINT NOT NULL REFERENCES series(id) ON DELETE CASCADE,
    key TEXT NOT NULL,
    display_name TEXT NOT NULL,
    full_name TEXT NOT NULL DEFAULT '',
    aliases_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    feature_tags TEXT NOT NULL DEFAULT '',
    adult_framing_tags TEXT NOT NULL DEFAULT '',
    base_lora_alias TEXT NOT NULL DEFAULT '',
    base_lora_weight DOUBLE PRECISION NOT NULL DEFAULT 1,
    default_negative TEXT NOT NULL DEFAULT '',
    preview_image TEXT NOT NULL DEFAULT '',
    source_type TEXT NOT NULL DEFAULT 'manual',
    notes TEXT NOT NULL DEFAULT '',
    nsfw_profile_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(series_id, key)
);

CREATE TABLE IF NOT EXISTS character_outfits (
    id BIGSERIAL PRIMARY KEY,
    character_id BIGINT NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    prompt TEXT NOT NULL DEFAULT '',
    negative_tags TEXT NOT NULL DEFAULT '',
    preview_image TEXT NOT NULL DEFAULT '',
    conflict_groups TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(character_id, name)
);

CREATE TABLE IF NOT EXISTS character_appearances (
    id BIGSERIAL PRIMARY KEY,
    character_id BIGINT NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    prompt TEXT NOT NULL DEFAULT '',
    negative_tags TEXT NOT NULL DEFAULT '',
    preview_image TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(character_id, name)
);

CREATE TABLE IF NOT EXISTS character_loras (
    id BIGSERIAL PRIMARY KEY,
    character_id BIGINT NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
    alias TEXT NOT NULL,
    weight DOUBLE PRECISION NOT NULL DEFAULT 1,
    trigger_words TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL DEFAULT 'base',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(character_id, alias, role)
);

CREATE TABLE IF NOT EXISTS lora_library (
    alias TEXT PRIMARY KEY,
    name TEXT NOT NULL DEFAULT '',
    trigger_words TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'uncategorized',
    default_weight DOUBLE PRECISION NOT NULL DEFAULT 0.8,
    conflict_groups TEXT NOT NULL DEFAULT '',
    conflict_negatives TEXT NOT NULL DEFAULT '',
    compatible_series TEXT NOT NULL DEFAULT '',
    compatible_characters TEXT NOT NULL DEFAULT '',
    compatible_acts TEXT NOT NULL DEFAULT '',
    incompatible_acts TEXT NOT NULL DEFAULT '',
    act_groups TEXT NOT NULL DEFAULT '',
    requires_outfit_none BOOLEAN NOT NULL DEFAULT FALSE,
    scene_intent_hint TEXT NOT NULL DEFAULT '',
    nsfw_effect_groups TEXT NOT NULL DEFAULT '',
    needs_trigger BOOLEAN NOT NULL DEFAULT FALSE,
    requires_secondary_characters BOOLEAN NOT NULL DEFAULT FALSE,
    min_secondary_characters INTEGER NOT NULL DEFAULT 0,
    max_secondary_characters INTEGER NOT NULL DEFAULT 0,
    anonymous_partner_tags TEXT NOT NULL DEFAULT '',
    ensemble_tags TEXT NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    favorite BOOLEAN NOT NULL DEFAULT FALSE,
    notes TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS lora_variants (
    lora_alias TEXT NOT NULL,
    variant_key TEXT NOT NULL,
    label TEXT NOT NULL DEFAULT '',
    trigger_words TEXT NOT NULL DEFAULT '',
    positive_tags TEXT NOT NULL DEFAULT '',
    negative_tags TEXT NOT NULL DEFAULT '',
    weight_override DOUBLE PRECISION NULL,
    compatible_acts TEXT NOT NULL DEFAULT '',
    incompatible_acts TEXT NOT NULL DEFAULT '',
    act_groups TEXT NOT NULL DEFAULT '',
    clothing_policy TEXT NOT NULL DEFAULT 'incidental',
    clothing_tags TEXT NOT NULL DEFAULT '',
    clothing_required_tags TEXT NOT NULL DEFAULT '',
    strip_clothing_when_outfit_active BOOLEAN NOT NULL DEFAULT TRUE,
    anonymous_partner_tags TEXT NOT NULL DEFAULT '',
    ensemble_tags TEXT NOT NULL DEFAULT '',
    requires_secondary_characters BOOLEAN NOT NULL DEFAULT FALSE,
    min_secondary_characters INTEGER NOT NULL DEFAULT 0,
    max_secondary_characters INTEGER NOT NULL DEFAULT 0,
    notes TEXT NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (lora_alias, variant_key)
);

CREATE TABLE IF NOT EXISTS lora_reference_images (
    id BIGSERIAL PRIMARY KEY,
    lora_alias TEXT NOT NULL,
    variant_key TEXT NOT NULL DEFAULT 'default',
    file_path TEXT NOT NULL,
    original_name TEXT NOT NULL DEFAULT '',
    mime_type TEXT NOT NULL DEFAULT '',
    width INTEGER NOT NULL DEFAULT 0,
    height INTEGER NOT NULL DEFAULT 0,
    caption TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS history (
    id BIGSERIAL PRIMARY KEY,
    prompt TEXT NOT NULL,
    negative_prompt TEXT NOT NULL,
    payload_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    result_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS gallery (
    id BIGSERIAL PRIMARY KEY,
    uma TEXT NOT NULL DEFAULT '',
    outfit TEXT NOT NULL DEFAULT '',
    prompt TEXT NOT NULL,
    negative_prompt TEXT NOT NULL,
    payload_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    image_paths_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    seed BIGINT NOT NULL DEFAULT -1,
    actual_seed BIGINT NOT NULL DEFAULT -1,
    width INTEGER NOT NULL DEFAULT 0,
    height INTEGER NOT NULL DEFAULT 0,
    parent_gallery_id BIGINT NULL REFERENCES gallery(id) ON DELETE SET NULL,
    operation TEXT NOT NULL DEFAULT 'generate',
    series_id BIGINT NOT NULL DEFAULT 0,
    series_name TEXT NOT NULL DEFAULT '',
    character_id BIGINT NOT NULL DEFAULT 0,
    character_name TEXT NOT NULL DEFAULT '',
    base_lora TEXT NOT NULL DEFAULT '',
    source_type TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS generation_jobs (
    id BIGSERIAL PRIMARY KEY,
    type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    payload_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    progress DOUBLE PRECISION NOT NULL DEFAULT 0,
    error TEXT NOT NULL DEFAULT '',
    result_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ NULL,
    finished_at TIMESTAMPTZ NULL
);

CREATE TABLE IF NOT EXISTS image_assets (
    id BIGSERIAL PRIMARY KEY,
    gallery_id BIGINT NULL REFERENCES gallery(id) ON DELETE CASCADE,
    parent_asset_id BIGINT NULL REFERENCES image_assets(id) ON DELETE SET NULL,
    path TEXT NOT NULL,
    width INTEGER NOT NULL DEFAULT 0,
    height INTEGER NOT NULL DEFAULT 0,
    seed BIGINT NOT NULL DEFAULT -1,
    hash TEXT NOT NULL DEFAULT '',
    operation TEXT NOT NULL DEFAULT 'generate',
    metadata_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO schema_migrations(version) VALUES ('001_initial')
ON CONFLICT(version) DO NOTHING;
