<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Character Studio</title>
    <script>window.APP_BASE_PATH = <?= json_encode($appBasePath ?? '', JSON_UNESCAPED_SLASHES) ?>;</script>
    <link rel="stylesheet" href="<?= htmlspecialchars(($appBasePath ?? '') . '/assets/vendor/sweetalert2/sweetalert2.min.css?v=' . (string) filemtime(__DIR__ . '/../assets/vendor/sweetalert2/sweetalert2.min.css'), ENT_QUOTES) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(($appBasePath ?? '') . '/assets/styles.css?v=' . (string) filemtime(__DIR__ . '/../assets/styles.css'), ENT_QUOTES) ?>">
    <script defer src="<?= htmlspecialchars(($appBasePath ?? '') . '/assets/vendor/sweetalert2/sweetalert2.all.min.js?v=' . (string) filemtime(__DIR__ . '/../assets/vendor/sweetalert2/sweetalert2.all.min.js'), ENT_QUOTES) ?>"></script>
</head>
<body>
<svg class="icon-sprite" aria-hidden="true" focusable="false">
    <symbol id="icon-planner" viewBox="0 0 24 24"><path d="M4 5.5h16M4 12h16M4 18.5h10"/><path d="M7 3v4M17 3v4"/></symbol>
    <symbol id="icon-presets" viewBox="0 0 24 24"><path d="M5 4h14v16l-7-3-7 3z"/><path d="M9 8h6M9 12h6"/></symbol>
    <symbol id="icon-history" viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.35-5.65"/><path d="M4 4v5h5M12 8v5l3 2"/></symbol>
    <symbol id="icon-gallery" viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="m4 16 4-4 3 3 3-4 6 6"/><path d="M8 9h.01"/></symbol>
    <symbol id="icon-settings" viewBox="0 0 24 24"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"/><path d="M19 12a7.5 7.5 0 0 0-.1-1.2l2-1.5-2-3.4-2.4 1a7 7 0 0 0-2-1.1L14.2 3h-4.4l-.3 2.8a7 7 0 0 0-2 1.1l-2.4-1-2 3.4 2 1.5A7.5 7.5 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.4-1a7 7 0 0 0 2 1.1l.3 2.8h4.4l.3-2.8a7 7 0 0 0 2-1.1l2.4 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z"/></symbol>
    <symbol id="icon-sync" viewBox="0 0 24 24"><path d="M20 11a8 8 0 0 0-14.9-4"/><path d="M4 5v5h5M4 13a8 8 0 0 0 14.9 4"/><path d="M20 19v-5h-5"/></symbol>
    <symbol id="icon-spark" viewBox="0 0 24 24"><path d="m12 3 1.7 5.3L19 10l-5.3 1.7L12 17l-1.7-5.3L5 10l5.3-1.7z"/><path d="m19 15 .8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></symbol>
    <symbol id="icon-brain" viewBox="0 0 24 24"><path d="M8.5 6A3.5 3.5 0 0 0 5 9.5V16a3 3 0 0 0 3 3h1"/><path d="M15.5 6A3.5 3.5 0 0 1 19 9.5V16a3 3 0 0 1-3 3h-1"/><path d="M9 6.2A3 3 0 0 1 12 3a3 3 0 0 1 3 3.2V21"/><path d="M9 21V6M5 13h4M15 13h4"/></symbol>
    <symbol id="icon-seed" viewBox="0 0 24 24"><path d="M12 21c-4-2.5-6-5.4-6-8.5A6 6 0 0 1 12 6a6 6 0 0 1 6 6.5c0 3.1-2 6-6 8.5z"/><path d="M12 6V3M9 12h6"/></symbol>
    <symbol id="icon-hires" viewBox="0 0 24 24"><path d="M12 19V5M7 10l5-5 5 5"/><path d="M5 19h14"/></symbol>
    <symbol id="icon-copy" viewBox="0 0 24 24"><path d="M8 8h11v11H8z"/><path d="M5 16H4V4h12v1"/></symbol>
    <symbol id="icon-eye" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><path d="M12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z"/></symbol>
    <symbol id="icon-download" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 21h14"/></symbol>
    <symbol id="icon-play" viewBox="0 0 24 24"><path d="M7 4v16l13-8z"/></symbol>
    <symbol id="icon-stop" viewBox="0 0 24 24"><path d="M7 7h10v10H7z"/></symbol>
    <symbol id="icon-rotate" viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-2.35-5.65"/><path d="M20 4v5h-5"/></symbol>
</svg>
<div id="app" class="app-shell">
    <aside class="sidebar">
        <div class="brand logo-only">
            <img class="brand-logo" src="<?= htmlspecialchars(($appBasePath ?? '') . '/assets/logo.png', ENT_QUOTES) ?>" alt="Character Studio">
        </div>
        <span id="sdStatus" class="visually-hidden">Stable Diffusion Prompt Planner</span>
        <nav class="nav-list" aria-label="Primary">
            <button class="nav-item active" data-view="planner"><svg class="ui-icon"><use href="#icon-planner"></use></svg><span>Planner</span></button>
            <button class="nav-item" data-view="presets"><svg class="ui-icon"><use href="#icon-presets"></use></svg><span>Presets</span></button>
            <button class="nav-item" data-view="history"><svg class="ui-icon"><use href="#icon-history"></use></svg><span>History</span></button>
            <button class="nav-item" data-view="gallery"><svg class="ui-icon"><use href="#icon-gallery"></use></svg><span>Gallery</span></button>
            <button class="nav-item" data-view="settings"><svg class="ui-icon"><use href="#icon-settings"></use></svg><span>Settings</span></button>
        </nav>
        <div class="sidebar-bottom">
            <div class="status-card" id="runtimeStatusCard">
                <span>Runtime</span>
                <strong id="runtimeStatusSummary">Checking services</strong>
                <small id="runtimeStatusDetails">SD / LLM / DB</small>
            </div>
            <a class="support-card support-card-kofi" href="https://ko-fi.com/naixxier" target="_blank" rel="noopener noreferrer">
                <span>Support</span>
                <strong>Ko-fi</strong>
                <small>Fuel future Character Studio updates.</small>
            </a>
            <a class="support-card support-card-patreon" href="https://www.patreon.com/Naixxier/posts/character-studio-160760524?utm_medium=clipboard_copy&utm_source=copyLink&utm_campaign=postshare_creator&utm_content=join_link" target="_blank" rel="noopener noreferrer">
                <span>Community</span>
                <strong>Patreon</strong>
                <small>Follow roadmap notes and support development.</small>
            </a>
            <div class="trainer-card">
                <div class="trainer-avatar">CS</div>
                <div>
                    <strong>Character Studio</strong>
                    <small>NSFW Creative Console</small>
                </div>
            </div>
        </div>
    </aside>

    <main class="workspace">
        <section id="plannerView" class="view active">
            <header class="topbar">
                <div>
                    <h1>Character Generation Console</h1>
                    <p>Series, character DNA, adult framing, NSFW Director, LoRAs, generate.</p>
                </div>
                <div class="top-actions">
                    <button id="refreshCatalog" class="button ghost" type="button"><svg class="ui-icon"><use href="#icon-sync"></use></svg><span>Sync ULTIMA Catalog</span></button>
                </div>
            </header>

            <div class="planner-compact-grid">
                <section class="panel uma-panel">
                    <div class="panel-heading">
                        <h2>1. Character</h2>
                        <span id="catalogCount">0</span>
                    </div>
                    <label class="search-field">
                        <span>Series</span>
                        <select id="seriesFilter" class="select"></select>
                    </label>
                    <label class="search-field">
                        <span>Search</span>
                        <input id="umaSearch" type="search" placeholder="Search character..." autocomplete="off">
                    </label>
                    <div id="umaList" class="uma-list"></div>
                    <select id="umaSelect" class="hidden-select" aria-label="Character"></select>
                </section>

                <section class="main-flow">
                    <section class="panel hero-card compact-hero">
                        <div class="hero-art">
                            <div id="heroAvatar" class="uma-art-avatar">A</div>
                        </div>
                        <div class="hero-copy">
                            <div class="hero-title-row">
                                <h2 id="heroName">Admire Vega</h2>
                                <span id="heroRarity" class="rarity-badge">3 Star</span>
                            </div>
                            <div id="heroTags" class="chip-row"></div>
                            <p id="heroDescription">Locked ULTIMA DNA plus your creative layers.</p>
                            <div class="stats-grid">
                                <div class="stat-card"><span id="lockedCount">0</span><small>Locked Tags</small></div>
                                <div class="stat-card"><span id="styleCount">0</span><small>Style Tags</small></div>
                            </div>
                        </div>
                    </section>

                    <section class="panel outfit-panel">
                        <div class="panel-heading"><h2>2. Outfit</h2></div>
                        <div id="appearanceBlock" class="appearance-block">
                            <label>Appearance</label>
                            <div id="appearanceCards" class="appearance-cards"></div>
                            <select id="appearanceSelect" class="hidden-select" aria-label="Appearance"></select>
                        </div>
                        <div id="outfitCards" class="outfit-cards"></div>
                        <select id="outfitSelect" class="hidden-select" aria-label="Outfit"></select>
                    </section>

                    <section class="panel ensemble-panel">
                        <div class="panel-heading">
                            <h2>Ensemble / Extra Characters</h2>
                            <span id="ensembleSummary">0 secondary</span>
                        </div>
                        <div class="director-toggle-row">
                            <button id="anonymousPartnerToggle" class="icon-toggle active" type="button" aria-pressed="true" title="Use anonymous male partner">1B</button>
                            <span>Up to 3 extra female characters for multi-character LoRA packs.</span>
                        </div>
                        <div id="ensembleSlots" class="ensemble-slots"></div>
                    </section>

                    <section class="panel composition-panel">
                        <div class="panel-heading">
                            <h2>3. Composition & Style</h2>
                            <span id="styleSummary">4 active</span>
                        </div>
                        <div class="style-groups compact-style">
                            <div><label>Composition</label><div id="compositionChips" class="choice-grid"></div></div>
                            <div><label>Angle</label><div id="angleChips" class="choice-grid"></div></div>
                            <div><label>Lighting</label><div id="lightingChips" class="choice-grid"></div></div>
                            <div><label>Extra</label><div id="extraChips" class="choice-grid"></div></div>
                        </div>
                    </section>

                    <section class="panel pose-panel">
                        <div class="panel-heading">
                            <h2>Pose Direction</h2>
                            <span id="poseSummary">none</span>
                        </div>
                        <div class="pose-tabs">
                            <button id="poseSuggestiveTab" class="pose-tab active" type="button">Suggestive</button>
                            <button id="poseExplicitTab" class="pose-tab explicit" type="button">Explicit</button>
                            <button id="clearPose" class="button ghost compact-button" type="button">Clear</button>
                        </div>
                        <div id="poseChips" class="pose-chip-grid"></div>
                    </section>

                    <section class="panel mode-tags-panel">
                        <div class="panel-heading">
                            <h2>4. Modo y Tags</h2>
                            <span id="modeSummary">Standard · 0 tags</span>
                        </div>
                        <div class="mode-stack">
                            <div>
                                <label>Modo</label>
                                <div id="modeChips" class="mode-chip-grid"></div>
                            </div>
                            <div>
                                <label>Quick Tags <span class="muted-inline">Visual quick tags only</span></label>
                                <div id="quickTagChips" class="quick-tag-grid"></div>
                            </div>
                            <label class="search-field compact-search">
                                <span>Custom Tags</span>
                                <input id="customTags" type="text" placeholder="open_mouth, blush, custom outfit...">
                            </label>
                        </div>
                    </section>

                    <section class="panel nsfw-director-panel">
                        <div class="panel-heading">
                            <h2>NSFW Director</h2>
                            <span id="nsfwSummary">off</span>
                        </div>
                        <div class="director-toggle-row">
                            <button id="nsfwToggle" class="icon-toggle" type="button" aria-pressed="false" title="Enable NSFW Director">◆</button>
                            <button id="nsfwStrictToggle" class="icon-toggle active" type="button" aria-pressed="true" title="Strict Act">!</button>
                            <button id="nsfwBoostToggle" class="icon-toggle" type="button" aria-pressed="false" title="Manual NSFW Boost">+</button>
                            <span>Semantic act dictionary for explicit poses and disambiguation.</span>
                        </div>
                        <div id="nsfwDirectorControls" class="director-controls">
                            <div><label>Intensity</label><div id="nsfwIntensityChips" class="director-chip-grid"></div></div>
                            <div><label>Act</label><div id="nsfwActChips" class="director-chip-grid act-grid"></div></div>
                            <div><label>Scene Intent</label><div id="nsfwSceneIntentChips" class="director-chip-grid"></div></div>
                            <div><label>Focus</label><div id="nsfwFocusChips" class="director-chip-grid"></div></div>
                            <div id="nsfwContactLockBlock"><label>Contact Lock</label><div id="nsfwContactLockChips" class="director-chip-grid"></div></div>
                            <div><label id="nsfwExpressionLabel">Expression</label><div id="nsfwExpressionChips" class="director-chip-grid"></div></div>
                            <div><label>Clothing</label><div id="nsfwClothingChips" class="director-chip-grid"></div></div>
                            <div><label id="nsfwEffectsLabel">Effects / Finish</label><div id="nsfwEffectChips" class="director-chip-grid"></div></div>
                            <div><label>Camera</label><div id="nsfwCameraChips" class="director-chip-grid"></div></div>
                        </div>
                    </section>

                    <section class="panel lora-panel">
                        <div class="panel-heading">
                            <h2>5. LoRAs + Trigger Words</h2>
                            <span id="loraCount">0 active</span>
                        </div>
                        <div id="loraCategoryChips" class="lora-category-chips"></div>
                        <div id="loraChipGrid" class="lora-chip-grid"></div>
                        <div id="loraVariantPicker" class="lora-variant-picker" hidden></div>
                        <div id="loraConflictMessage" class="settings-status"></div>
                        <label class="search-field">
                            <span>Search LoRAs</span>
                            <input id="loraSearch" type="search" placeholder="pose, style, concept...">
                        </label>
                        <div id="loraResults" class="lora-results"></div>
                        <div id="selectedLoras" class="selected-loras"></div>
                    </section>

                    <section class="panel prompt-builder compact-prompt">
                        <div class="panel-heading">
                            <h2>6. Prompt Builder</h2>
                            <span id="promptLength">0 chars</span>
                        </div>
                        <div class="prompt-grid">
                            <div class="field">
                                <label for="globalPreset">Global Prompt</label>
                                <select id="globalPreset" class="select"></select>
                                <textarea id="globalPrompt" class="textarea" rows="3"></textarea>
                            </div>
                            <div class="field">
                                <label for="negativePreset">Negative Prompt</label>
                                <select id="negativePreset" class="select"></select>
                                <textarea id="negativePrompt" class="textarea" rows="3"></textarea>
                            </div>
                            <div class="field">
                                <label for="manualPrompt">Manual Additions</label>
                                <textarea id="manualPrompt" class="textarea" rows="3" placeholder="scene, pose, camera, expression..."></textarea>
                            </div>
                            <div class="field">
                                <label for="llmPolish">AI Polish (LLM) <span id="autoLlmStatus" class="auto-llm-status">auto off</span></label>
                                <textarea id="llmPolish" class="textarea" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="seed-row">
                            <button id="seedToggle" class="icon-toggle" type="button" aria-pressed="false" title="Use fixed seed"><svg class="ui-icon"><use href="#icon-seed"></use></svg></button>
                            <input id="seed" class="input seed-input" type="number" value="-1" disabled>
                            <button id="hiresToggle" class="icon-toggle" type="button" aria-pressed="false" title="Use Hires.fix"><svg class="ui-icon"><use href="#icon-hires"></use></svg></button>
                            <select id="hiresProfileSelect" class="select hires-profile-select" title="Hires profile">
                                <option value="fine">Fine</option>
                                <option value="fine_detail">Fine Detail</option>
                                <option value="safe">Safe</option>
                                <option value="enhance">Enhance</option>
                            </select>
                            <button id="polishPrompt" class="button ghost accent compact-button" type="button"><svg class="ui-icon"><use href="#icon-spark"></use></svg><span>Polish</span></button>
                            <button id="autoLlmToggle" class="button ghost auto-llm-toggle compact-button" type="button" aria-pressed="false"><svg class="ui-icon"><use href="#icon-brain"></use></svg><span>Auto LLM Off</span></button>
                            <span>Seed locks recreation. Hires profile controls second pass style.</span>
                        </div>
                        <details class="prompt-preview">
                            <summary>Prompt Preview & Layers</summary>
                            <textarea id="finalPrompt" class="textarea code" rows="5" readonly></textarea>
                            <div id="promptAuditPanel" class="audit-panel audit-ok">
                                <div class="audit-heading"><strong>Prompt Audit</strong><span id="promptAuditSummary">idle</span></div>
                                <div id="promptAuditIssues" class="audit-issues"></div>
                            </div>
                            <div id="promptLayers" class="layer-list"></div>
                        </details>
                    </section>
                </section>

                <aside class="right-stack">
                    <section class="panel preview-panel">
                        <div class="panel-heading">
                            <h2>Preview</h2>
                            <div class="preview-size-controls">
                                <select id="aspectRatio" class="mini-select" title="Generation size">
                                    <option value="832x1216">Portrait 832×1216</option>
                                    <option value="1216x832">Landscape 1216×832</option>
                                    <option value="1024x1024">Square 1024×1024</option>
                                    <option value="960x1200">Portrait 960×1200</option>
                                    <option value="1344x768">Wide 1344×768</option>
                                </select>
                                <button id="rotateImageSize" class="icon-toggle compact-icon-toggle" type="button" title="Rotate size"><svg class="ui-icon"><use href="#icon-rotate"></use></svg></button>
                            </div>
                        </div>
                        <div id="previewStage" class="preview-stage">
                            <div id="previewPlaceholder" class="preview-placeholder">
                                <div class="preview-face">A</div>
                                <span>Preview appears here</span>
                            </div>
                            <div id="previewGrid" class="preview-grid"></div>
                        </div>
                        <div class="preview-metrics">
                            <div><small>Prompt Strength</small><strong id="promptStrength">0/100</strong><div class="meter"><span id="strengthFill"></span></div></div>
                            <div><small>Tokens</small><strong id="tokenEstimate">0</strong></div>
                        </div>
                        <div class="preview-actions">
                            <button id="copyPrompt" class="button ghost"><svg class="ui-icon"><use href="#icon-copy"></use></svg><span>Copy Prompt</span></button>
                            <button id="viewImage" class="button ghost" type="button"><svg class="ui-icon"><use href="#icon-eye"></use></svg><span>View</span></button>
                            <button id="downloadImage" class="button ghost" type="button"><svg class="ui-icon"><use href="#icon-download"></use></svg><span>Download</span></button>
                            <button id="generateImage" class="button primary" type="button"><svg class="ui-icon"><use href="#icon-play"></use></svg><span>Generate</span></button>
                        </div>
                        <div class="progress-wrap">
                            <div class="progress-bar"><span id="progressFill"></span></div>
                            <strong id="generationStatus">idle</strong>
                        </div>
                    </section>

                    <section class="panel dna-panel">
                        <div class="panel-heading">
                            <h2>Locked Tags</h2>
                            <span id="dnaTotal">0 Total</span>
                        </div>
                        <div id="lockedTagChips" class="dna-chips"></div>
                    </section>
                </aside>
            </div>
        </section>

        <section id="presetsView" class="view">
            <header class="section-header"><h1>Presets</h1><p>Edit, replace, or remove prompt presets.</p></header>
            <div class="panel">
                <div class="preset-editor">
                    <select id="presetType" class="select"><option value="global">global</option><option value="negative">negative</option></select>
                    <input id="presetName" class="input" placeholder="Preset name">
                    <textarea id="presetContent" class="textarea" rows="4" placeholder="comma-separated prompt tags"></textarea>
                    <div class="preset-actions">
                        <button id="savePreset" class="button primary">Save preset</button>
                        <button id="cancelPresetEdit" class="button ghost" type="button">Cancel</button>
                    </div>
                </div>
                <div id="presetList" class="preset-list"></div>
            </div>
        </section>

        <section id="historyView" class="view">
            <header class="section-header"><h1>History</h1><p>Recent jobs with prompts and thumbnails.</p></header>
            <div class="panel">
                <div class="panel-heading"><h2>Jobs</h2><button id="refreshJobs" class="button ghost" type="button">Refresh Jobs</button></div>
                <div id="jobsList" class="jobs-list"></div>
            </div>
            <div class="panel"><div id="historyList" class="history-list"></div></div>
        </section>

        <section id="galleryView" class="view">
            <header class="section-header"><h1>Gallery</h1><p>Images saved from generation results.</p></header>
            <div class="panel">
                <div class="gallery-toolbar">
                    <input id="galleryUmaFilter" class="input" placeholder="Filter by exact character name">
                    <select id="galleryOperationFilter" class="select">
                        <option value="">All operations</option>
                        <option value="generate">Generate</option>
                        <option value="variation">Variation</option>
                        <option value="enhance">Enhance</option>
                        <option value="repair">Repair</option>
                        <option value="preview">Preview</option>
                    </select>
                    <input id="galleryActFilter" class="input" placeholder="Filter act">
                    <input id="galleryLoraFilter" class="input" placeholder="Filter LoRA">
                    <button id="refreshGallery" class="button ghost" type="button">Refresh Gallery</button>
                </div>
                <div id="galleryGrid" class="gallery-grid"></div>
            </div>
        </section>

        <section id="settingsView" class="view">
            <header class="section-header"><h1>Settings</h1><p>Technical defaults and LLM prompt debugging live here.</p></header>
            <div class="settings-layout">
                <nav class="settings-subnav" aria-label="Settings sections">
                    <button class="settings-tab active" type="button" data-settings-tab="general">General</button>
                    <button class="settings-tab" type="button" data-settings-tab="sd">Stable Diffusion</button>
                    <button class="settings-tab" type="button" data-settings-tab="llm">LLM</button>
                    <button class="settings-tab" type="button" data-settings-tab="libraries">Prompt Libraries</button>
                    <button class="settings-tab" type="button" data-settings-tab="director">Director / Poses</button>
                    <button class="settings-tab" type="button" data-settings-tab="characters">Characters</button>
                    <button class="settings-tab" type="button" data-settings-tab="loras">LoRAs</button>
                    <button class="settings-tab" type="button" data-settings-tab="debug">Debug</button>
                </nav>
            <div class="settings-grid">
                <section class="panel" data-settings-section="general">
                    <div class="panel-heading"><h2>General</h2><button id="refreshRuntimeStatus" class="button ghost compact-button" type="button"><svg class="ui-icon"><use href="#icon-sync"></use></svg><span>Refresh Status</span></button></div>
                    <div class="settings-form one-col">
                        <label>Runtime summary<input id="settingRuntimeSummary" class="input" readonly></label>
                        <label>Runtime details<textarea id="settingRuntimeDetails" class="textarea code" rows="4" readonly></textarea></label>
                    </div>
                </section>
                <section class="panel" data-settings-section="sd">
                    <div class="panel-heading"><h2>Stable Diffusion</h2><button id="saveSdSettings" class="button primary" type="button">Save SD</button></div>
                    <div class="settings-form">
                        <label>Checkpoint<select id="settingCheckpoint" class="select"></select></label>
                        <label>Sampler<select id="settingSampler" class="select"></select></label>
                        <label>Width<input id="settingWidth" class="input" type="number" step="64"></label>
                        <label>Height<input id="settingHeight" class="input" type="number" step="64"></label>
                        <label>Steps<input id="settingSteps" class="input" type="number"></label>
                        <label>CFG<input id="settingCfg" class="input" type="number" step="0.5"></label>
                        <label>Batch<input id="settingBatch" class="input" type="number"></label>
                    </div>
                </section>
                <section class="panel" data-settings-section="sd">
                    <div class="panel-heading">
                        <h2>Hires.fix</h2>
                        <button id="saveHiresSettings" class="button primary" type="button">Save Hires</button>
                    </div>
                    <div class="settings-form">
                        <label>Default enabled<select id="settingHiresDefault" class="select"><option value="false">Off</option><option value="true">On</option></select></label>
                        <label>Upscaler<select id="settingHiresUpscaler" class="select"></select></label>
                        <label>Upscale by<input id="settingHiresScale" class="input" type="number" step="0.1"></label>
                        <label>Hires steps<input id="settingHiresSteps" class="input" type="number"></label>
                        <label>Denoising strength<input id="settingHiresDenoise" class="input" type="number" step="0.05"></label>
                        <label>Hires CFG<input id="settingHiresCfg" class="input" type="number" step="0.5"></label>
                        <label>Resize width<input id="settingHiresResizeX" class="input" type="number" step="64"></label>
                        <label>Resize height<input id="settingHiresResizeY" class="input" type="number" step="64"></label>
                    </div>
                    <p class="settings-help anatomy-help">Fine uses Latent bicubic antialiased for the polished look. SwinIR_4x is the stable non-Latent option here; R-ESRGAN/DAT may fail with a Forge TypeError.</p>
                    <div class="anatomy-guard-box">
                        <div class="panel-heading compact-heading"><h3>Hires Profiles</h3></div>
                        <div class="settings-form">
                            <label>Planner default<select id="settingActiveHiresProfile" class="select"><option value="fine">Fine</option><option value="fine_detail">Fine Detail</option><option value="safe">Safe</option><option value="enhance">Enhance</option></select></label>
                        </div>
                        <div class="hires-profile-settings">
                            <div class="hires-profile-card" data-profile="fine">
                                <h4>Fine</h4>
                                <label>Upscaler<select id="profileFineUpscaler" class="select"></select></label>
                                <label>Scale<input id="profileFineScale" class="input" type="number" step="0.1"></label>
                                <label>Steps<input id="profileFineSteps" class="input" type="number"></label>
                                <label>Denoise<input id="profileFineDenoise" class="input" type="number" step="0.01"></label>
                                <label>CFG<input id="profileFineCfg" class="input" type="number" step="0.5"></label>
                                <label>Sampler<select id="profileFineSampler" class="select"></select></label>
                                <label>Scheduler<select id="profileFineScheduler" class="select"></select></label>
                                <label>Style +<textarea id="profileFineStylePositive" class="textarea code" rows="2"></textarea></label>
                                <label>Style -<textarea id="profileFineStyleNegative" class="textarea code" rows="2"></textarea></label>
                                <label>Strip tags<textarea id="profileFineStripTags" class="textarea code" rows="2"></textarea></label>
                                <label>Guard<select id="profileFineAnatomy" class="select"><option value="true">On</option><option value="false">Off</option></select></label>
                            </div>
                            <div class="hires-profile-card" data-profile="fine_detail">
                                <h4>Fine Detail</h4>
                                <label>Upscaler<select id="profileFineDetailUpscaler" class="select"></select></label>
                                <label>Scale<input id="profileFineDetailScale" class="input" type="number" step="0.1"></label>
                                <label>Steps<input id="profileFineDetailSteps" class="input" type="number"></label>
                                <label>Denoise<input id="profileFineDetailDenoise" class="input" type="number" step="0.01"></label>
                                <label>CFG<input id="profileFineDetailCfg" class="input" type="number" step="0.5"></label>
                                <label>Sampler<select id="profileFineDetailSampler" class="select"></select></label>
                                <label>Scheduler<select id="profileFineDetailScheduler" class="select"></select></label>
                                <label>Style +<textarea id="profileFineDetailStylePositive" class="textarea code" rows="2"></textarea></label>
                                <label>Style -<textarea id="profileFineDetailStyleNegative" class="textarea code" rows="2"></textarea></label>
                                <label>Strip tags<textarea id="profileFineDetailStripTags" class="textarea code" rows="2"></textarea></label>
                                <label>Guard<select id="profileFineDetailAnatomy" class="select"><option value="true">On</option><option value="false">Off</option></select></label>
                            </div>
                            <div class="hires-profile-card" data-profile="safe">
                                <h4>Safe</h4>
                                <label>Upscaler<select id="profileSafeUpscaler" class="select"></select></label>
                                <label>Scale<input id="profileSafeScale" class="input" type="number" step="0.1"></label>
                                <label>Steps<input id="profileSafeSteps" class="input" type="number"></label>
                                <label>Denoise<input id="profileSafeDenoise" class="input" type="number" step="0.01"></label>
                                <label>CFG<input id="profileSafeCfg" class="input" type="number" step="0.5"></label>
                                <label>Sampler<select id="profileSafeSampler" class="select"></select></label>
                                <label>Scheduler<select id="profileSafeScheduler" class="select"></select></label>
                                <label>Style +<textarea id="profileSafeStylePositive" class="textarea code" rows="2"></textarea></label>
                                <label>Style -<textarea id="profileSafeStyleNegative" class="textarea code" rows="2"></textarea></label>
                                <label>Strip tags<textarea id="profileSafeStripTags" class="textarea code" rows="2"></textarea></label>
                                <label>Guard<select id="profileSafeAnatomy" class="select"><option value="true">On</option><option value="false">Off</option></select></label>
                            </div>
                            <div class="hires-profile-card" data-profile="enhance">
                                <h4>Enhance</h4>
                                <label>Upscaler<select id="profileEnhanceUpscaler" class="select"></select></label>
                                <label>Scale<input id="profileEnhanceScale" class="input" type="number" step="0.1"></label>
                                <label>Steps<input id="profileEnhanceSteps" class="input" type="number"></label>
                                <label>Denoise<input id="profileEnhanceDenoise" class="input" type="number" step="0.01"></label>
                                <label>CFG<input id="profileEnhanceCfg" class="input" type="number" step="0.5"></label>
                                <label>Sampler<select id="profileEnhanceSampler" class="select"></select></label>
                                <label>Scheduler<select id="profileEnhanceScheduler" class="select"></select></label>
                                <label>Style +<textarea id="profileEnhanceStylePositive" class="textarea code" rows="2"></textarea></label>
                                <label>Style -<textarea id="profileEnhanceStyleNegative" class="textarea code" rows="2"></textarea></label>
                                <label>Strip tags<textarea id="profileEnhanceStripTags" class="textarea code" rows="2"></textarea></label>
                                <label>Guard<select id="profileEnhanceAnatomy" class="select"><option value="true">On</option><option value="false">Off</option></select></label>
                            </div>
                        </div>
                    </div>
                    <div class="anatomy-guard-box">
                        <div class="panel-heading compact-heading">
                            <h3>Gallery Repair Profiles</h3>
                            <span class="settings-help">Used by the Gallery modal Enhance button.</span>
                        </div>
                        <div class="hires-profile-settings">
                            <div class="hires-profile-card" data-profile="repair_hiresfix">
                                <h4>Repair + Hires.fix</h4>
                                <label>Upscaler<select id="profileRepairHiresfixUpscaler" class="select"></select></label>
                                <label>Scale<input id="profileRepairHiresfixScale" class="input" type="number" step="0.1"></label>
                                <label>Steps<input id="profileRepairHiresfixSteps" class="input" type="number"></label>
                                <label>Denoise<input id="profileRepairHiresfixDenoise" class="input" type="number" step="0.01"></label>
                                <label>CFG<input id="profileRepairHiresfixCfg" class="input" type="number" step="0.5"></label>
                                <label>Sampler<select id="profileRepairHiresfixSampler" class="select"></select></label>
                                <label>Scheduler<select id="profileRepairHiresfixScheduler" class="select"></select></label>
                                <label>Style +<textarea id="profileRepairHiresfixStylePositive" class="textarea code" rows="2"></textarea></label>
                                <label>Style -<textarea id="profileRepairHiresfixStyleNegative" class="textarea code" rows="2"></textarea></label>
                                <label>Strip tags<textarea id="profileRepairHiresfixStripTags" class="textarea code" rows="2"></textarea></label>
                                <label>Guard<select id="profileRepairHiresfixAnatomy" class="select"><option value="true">On</option><option value="false">Off</option></select></label>
                            </div>
                            <div class="hires-profile-card" data-profile="repair_preserve">
                                <h4>Repair Preserve Quality</h4>
                                <label>Upscaler<select id="profileRepairPreserveUpscaler" class="select"></select></label>
                                <label>Scale<input id="profileRepairPreserveScale" class="input" type="number" step="0.1"></label>
                                <label>Steps<input id="profileRepairPreserveSteps" class="input" type="number"></label>
                                <label>Denoise<input id="profileRepairPreserveDenoise" class="input" type="number" step="0.01"></label>
                                <label>CFG<input id="profileRepairPreserveCfg" class="input" type="number" step="0.5"></label>
                                <label>Sampler<select id="profileRepairPreserveSampler" class="select"></select></label>
                                <label>Scheduler<select id="profileRepairPreserveScheduler" class="select"></select></label>
                                <label>Style +<textarea id="profileRepairPreserveStylePositive" class="textarea code" rows="2"></textarea></label>
                                <label>Style -<textarea id="profileRepairPreserveStyleNegative" class="textarea code" rows="2"></textarea></label>
                                <label>Strip tags<textarea id="profileRepairPreserveStripTags" class="textarea code" rows="2"></textarea></label>
                                <label>Guard<select id="profileRepairPreserveAnatomy" class="select"><option value="true">On</option><option value="false">Off</option></select></label>
                            </div>
                        </div>
                    </div>
                    <div class="anatomy-guard-box">
                        <div class="panel-heading compact-heading">
                            <h3>Anatomy Guard</h3>
                            <select id="settingAnatomyGuardEnabled" class="select compact-select">
                                <option value="true">On</option>
                                <option value="false">Off</option>
                            </select>
                        </div>
                        <div class="settings-form one-col">
                            <label>Hires anatomy denoise<input id="settingAnatomyDenoise" class="input" type="number" step="0.05"></label>
                            <label>Positive guard<textarea id="settingAnatomyPositive" class="textarea code" rows="3"></textarea></label>
                            <label>Negative guard<textarea id="settingAnatomyNegative" class="textarea code" rows="4"></textarea></label>
                            <label>Abdomen guard<textarea id="settingAbdomenGuard" class="textarea code" rows="2"></textarea></label>
                            <label>Hands guard<textarea id="settingHandsGuard" class="textarea code" rows="2"></textarea></label>
                            <label>Feet guard<textarea id="settingFeetGuard" class="textarea code" rows="2"></textarea></label>
                            <label>Feet safe crop<textarea id="settingFeetSafeCrop" class="textarea code" rows="2"></textarea></label>
                        </div>
                        <p class="settings-help anatomy-help">Applies only to Hires.fix / Enhance second pass.</p>
                    </div>
                </section>
                <section class="panel" data-settings-section="llm">
                    <div class="panel-heading"><h2>LLM</h2><button id="saveLlmSettings" class="button primary" type="button">Save LLM</button></div>
                    <div class="settings-form one-col">
                        <label>API Base<input id="settingLlmBase" class="input"></label>
                        <label>Model<input id="settingLlmModel" class="input"></label>
                        <label>API Key<input id="settingLlmApiKey" class="input" type="password" autocomplete="new-password" placeholder="Leave blank to keep saved key"></label>
                        <div class="api-key-row">
                            <span id="settingLlmApiKeyStatus" class="settings-status">No API key saved.</span>
                            <button id="clearLlmApiKey" class="button ghost danger compact-button" type="button">Clear API Key</button>
                        </div>
                        <label>Temperature<input id="settingLlmTemp" class="input" type="number" step="0.05"></label>
                        <label>Max tokens<input id="settingLlmTokens" class="input" type="number"></label>
                    </div>
                </section>
                <section class="panel mode-settings-panel" data-settings-section="libraries">
                    <div class="panel-heading"><h2>Modes & Quick Tags</h2><button id="saveModeLibrary" class="button primary" type="button">Save Library</button></div>
                    <div class="mode-settings-grid">
                        <div>
                            <div id="settingsModeList" class="settings-mode-list"></div>
                            <div class="preset-actions">
                                <button id="newMode" class="button ghost" type="button">New Mode</button>
                                <button id="deleteMode" class="button ghost danger" type="button">Delete Mode</button>
                            </div>
                        </div>
                        <div class="settings-form one-col">
                            <label>Key<input id="modeKey" class="input" placeholder="custom_mode"></label>
                            <label>Label<input id="modeLabel" class="input" placeholder="Custom Mode"></label>
                            <label>Positive tags<textarea id="modeTags" class="textarea code" rows="5" placeholder="comma-separated positive tags"></textarea></label>
                            <label>Negative tags<textarea id="modeNegativeTags" class="textarea code" rows="4" placeholder="comma-separated negative tags"></textarea></label>
                        </div>
                    </div>
                    <label class="quick-tags-editor">Quick tags<textarea id="quickTagsEditor" class="textarea code" rows="4" placeholder="one tag per line or comma-separated"></textarea></label>
                    <div id="modeLibraryStatus" class="settings-status">idle</div>
                </section>
                <section class="panel nsfw-settings-panel" data-settings-section="director">
                    <div class="panel-heading">
                        <h2>NSFW Director Library</h2>
                        <div class="settings-actions-inline">
                            <button id="auditNsfwDirector" class="button ghost" type="button">Audit Director</button>
                            <button id="resetNsfwDefaults" class="button ghost" type="button">Reset NSFW Defaults</button>
                            <button id="saveNsfwLibrary" class="button primary" type="button">Save Director</button>
                        </div>
                    </div>
                    <div class="nsfw-settings-grid">
                        <div>
                            <div id="settingsActList" class="settings-mode-list"></div>
                            <div class="preset-actions">
                                <button id="newNsfwAct" class="button ghost" type="button">New Act</button>
                                <button id="deleteNsfwAct" class="button ghost danger" type="button">Delete Act</button>
                            </div>
                        </div>
                        <div class="settings-form one-col">
                            <label>Key<input id="nsfwActKey" class="input" placeholder="custom_act"></label>
                            <label>Label<input id="nsfwActLabel" class="input" placeholder="Custom Act"></label>
                            <label>Aliases<textarea id="nsfwActAliases" class="textarea code" rows="2" placeholder="comma-separated aliases"></textarea></label>
                            <label>Positive tags<textarea id="nsfwActPositive" class="textarea code" rows="4"></textarea></label>
                            <label>Hard tags<textarea id="nsfwActHard" class="textarea code" rows="3"></textarea></label>
                            <label>Negative disambiguation<textarea id="nsfwActNegative" class="textarea code" rows="3"></textarea></label>
                            <label>Conflict negatives<textarea id="nsfwActConflicts" class="textarea code" rows="3"></textarea></label>
                            <label>Act group<input id="nsfwActGroup" class="input" placeholder="breast_sex, oral, penetration..."></label>
                            <label>Strict tags<textarea id="nsfwActStrictTags" class="textarea code" rows="2"></textarea></label>
                            <label>Anatomy tags<textarea id="nsfwActAnatomyTags" class="textarea code" rows="2"></textarea></label>
                            <div class="settings-form">
                                <label>Recommended focus<input id="nsfwActFocus" class="input"></label>
                                <label>Recommended camera<input id="nsfwActCamera" class="input"></label>
                                <label>Default scene intent<input id="nsfwActSceneIntent" class="input"></label>
                            </div>
                        </div>
                    </div>
                    <div class="director-list-editors">
                        <label>Intensities<textarea id="nsfwIntensitiesEditor" class="textarea code" rows="5"></textarea></label>
                        <label>Focuses<textarea id="nsfwFocusesEditor" class="textarea code" rows="5"></textarea></label>
                        <label>Expressions<textarea id="nsfwExpressionsEditor" class="textarea code" rows="5"></textarea></label>
                        <label>Clothing states<textarea id="nsfwClothingEditor" class="textarea code" rows="5"></textarea></label>
                        <label>Cameras<textarea id="nsfwCamerasEditor" class="textarea code" rows="5"></textarea></label>
                        <label>Scene intents<textarea id="nsfwSceneIntentsEditor" class="textarea code" rows="5"></textarea></label>
                        <label>Effects<textarea id="nsfwEffectsEditor" class="textarea code" rows="7"></textarea></label>
                    </div>
                    <p class="settings-help">List format: key | Label | positive tags | optional extra. Effects: key | Label | tags | negative tags | compatible groups | incompatible acts | scene intents | group.</p>
                    <pre id="nsfwAuditStatus" class="payload-preview audit-preview">Audit not run.</pre>
                </section>
                <section class="panel pose-library-panel" data-settings-section="director">
                    <div class="panel-heading">
                        <h2>Pose Library</h2>
                        <div class="settings-actions-inline">
                            <button id="resetPoseDefaults" class="button ghost" type="button">Reset Poses</button>
                            <button id="savePoseLibrary" class="button primary" type="button">Save Poses</button>
                        </div>
                    </div>
                    <p class="settings-help">Format: key | Label | intensity | tags | negative tags | category | compatible act groups | incompatible acts</p>
                    <textarea id="poseLibraryEditor" class="textarea code" rows="12"></textarea>
                    <pre id="poseLibraryStatus" class="payload-preview audit-preview">Pose library idle.</pre>
                </section>
                <section class="panel character-library-panel" data-settings-section="characters">
                    <div class="panel-heading">
                        <h2>Character Library</h2>
                        <div class="settings-actions-inline">
                            <button id="seedCharacterDefaults" class="button ghost" type="button">Seed Defaults</button>
                            <button id="newSeries" class="button ghost" type="button">New Series</button>
                            <button id="saveSeries" class="button ghost" type="button">Save Series</button>
                            <button id="newCharacter" class="button ghost" type="button">New Character</button>
                            <button id="saveCharacter" class="button primary" type="button">Save Character</button>
                        </div>
                    </div>
                    <div class="mode-settings-grid">
                        <div>
                            <input id="characterLibrarySearch" class="input" type="search" placeholder="Search characters...">
                            <div id="settingsCharacterList" class="settings-mode-list"></div>
                        </div>
                        <div class="settings-form one-col">
                            <div class="series-editor">
                                <div class="panel-heading compact-heading">
                                    <h3>Series</h3>
                                    <span id="seriesEditorStatus" class="settings-status">Select or create a series.</span>
                                </div>
                                <div class="settings-form">
                                    <label>Character series<select id="characterSeries" class="select"></select></label>
                                    <label>Series key<input id="seriesKey" class="input" placeholder="my_series"></label>
                                    <label>Series name<input id="seriesName" class="input" placeholder="My Series"></label>
                                    <label>Default NSFW<select id="seriesNsfwDefault" class="select"><option value="false">Off</option><option value="true">On</option></select></label>
                                </div>
                                <div class="settings-form">
                                    <label>Series base LoRA alias<input id="seriesBaseLora" class="input" placeholder="optional_series_lora"></label>
                                    <label>Series base LoRA weight<input id="seriesBaseWeight" class="input" type="number" step="0.05"></label>
                                </div>
                                <label>Series default negative<textarea id="seriesDefaultNegative" class="textarea code" rows="2"></textarea></label>
                                <label>Series description<textarea id="seriesDescription" class="textarea" rows="2"></textarea></label>
                            </div>
                            <label>Display name<input id="characterDisplayName" class="input" placeholder="Marin Kitagawa"></label>
                            <label>Full name<input id="characterFullName" class="input"></label>
                            <label>Aliases<textarea id="characterAliases" class="textarea code" rows="2"></textarea></label>
                            <label>Adult framing<textarea id="characterAdultFraming" class="textarea code" rows="2"></textarea></label>
                            <label>Feature tags<textarea id="characterFeatureTags" class="textarea code" rows="4"></textarea></label>
                            <div class="character-preview-editor">
                                <div id="characterPreviewThumb" class="character-preview-thumb">A</div>
                                <div>
                                    <strong>Character image</strong>
                                    <small>Shown in the selector, hero, and preview placeholder.</small>
                                    <input id="characterPreviewFile" class="input" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
                                    <button id="uploadCharacterPreview" class="button ghost compact-button" type="button">Upload Image</button>
                                </div>
                            </div>
                            <div class="settings-form">
                                <label>Base LoRA alias<input id="characterBaseLora" class="input" placeholder="optional_character_lora"></label>
                                <label>Base LoRA weight<input id="characterBaseWeight" class="input" type="number" step="0.05"></label>
                            </div>
                            <label>Appearances<textarea id="characterAppearances" class="textarea code" rows="4" placeholder="Default hair | long hair, blue eyes&#10;Ponytail | ponytail, blue eyes"></textarea></label>
                            <label>Outfits<textarea id="characterOutfits" class="textarea code" rows="5" placeholder="Outfit name | prompt tags"></textarea></label>
                            <label>Notes<textarea id="characterNotes" class="textarea" rows="2"></textarea></label>
                        </div>
                    </div>
                    <div id="characterLibraryStatus" class="settings-status">idle</div>
                </section>
                <section class="panel lora-library-panel" data-settings-section="loras">
                    <div class="panel-heading">
                        <h2>LoRA Library</h2>
                        <div class="settings-actions-inline">
                            <button id="auditLoraLibrary" class="button ghost" type="button">Audit LoRAs</button>
                            <button id="saveLoraLibrary" class="button primary" type="button">Save LoRA</button>
                        </div>
                    </div>
                    <div class="mode-settings-grid">
                        <div>
                            <input id="loraLibrarySearch" class="input" type="search" placeholder="Search detected LoRAs...">
                            <div id="settingsLoraList" class="settings-mode-list"></div>
                        </div>
                        <div class="settings-form one-col">
                            <label>Alias<input id="loraMetaAlias" class="input" readonly></label>
                            <label>Name<input id="loraMetaName" class="input"></label>
                            <label>Trigger words<textarea id="loraMetaTriggers" class="textarea code" rows="3" placeholder="comma-separated trigger words"></textarea></label>
                            <div class="settings-form">
                                <label>Category<select id="loraMetaCategory" class="select"></select></label>
                                <label>Default weight<input id="loraMetaWeight" class="input" type="number" step="0.05"></label>
                                <label>Enabled<select id="loraMetaEnabled" class="select"><option value="true">On</option><option value="false">Off</option></select></label>
                                <label>Favorite<select id="loraMetaFavorite" class="select"><option value="false">No</option><option value="true">Yes</option></select></label>
                            </div>
                            <label>Conflict groups<textarea id="loraMetaConflictGroups" class="textarea code" rows="2" placeholder="clothing_primary"></textarea></label>
                            <label>Conflict negatives<textarea id="loraMetaConflictNegatives" class="textarea code" rows="3" placeholder="dress, school uniform, nude..."></textarea></label>
                            <label>Compatible series<textarea id="loraMetaCompatibleSeries" class="textarea code" rows="2" placeholder="umamusume, my_dress_up_darling"></textarea></label>
                            <label>Compatible characters<textarea id="loraMetaCompatibleCharacters" class="textarea code" rows="2" placeholder="marin_kitagawa"></textarea></label>
                            <label>Compatible acts<textarea id="loraMetaCompatibleActs" class="textarea code" rows="2" placeholder="tit_fuck, cowgirl_position"></textarea></label>
                            <label>Incompatible acts<textarea id="loraMetaIncompatibleActs" class="textarea code" rows="2" placeholder="oral, doggy_style..."></textarea></label>
                            <label>Act groups<textarea id="loraMetaActGroups" class="textarea code" rows="2" placeholder="breast_sex, oral"></textarea></label>
                            <label>Scene intent hint<textarea id="loraMetaSceneIntentHint" class="textarea code" rows="2" placeholder="solo, implied_pov"></textarea></label>
                            <label>NSFW effect groups<textarea id="loraMetaEffectGroups" class="textarea code" rows="2" placeholder="fluids, aftermath"></textarea></label>
                            <label>Needs trigger<select id="loraMetaNeedsTrigger" class="select"><option value="false">No</option><option value="true">Yes</option></select></label>
                            <label>Requires Outfit Ninguno<select id="loraMetaRequiresOutfitNone" class="select"><option value="false">No</option><option value="true">Yes</option></select></label>
                            <label>Requires secondary characters<select id="loraMetaRequiresSecondary" class="select"><option value="false">No</option><option value="true">Yes</option></select></label>
                            <div class="settings-form">
                                <label>Min secondary<input id="loraMetaMinSecondary" class="input" type="number" min="0" max="3"></label>
                                <label>Max secondary<input id="loraMetaMaxSecondary" class="input" type="number" min="0" max="3"></label>
                            </div>
                            <label>Anonymous partner tags<textarea id="loraMetaAnonymousPartnerTags" class="textarea code" rows="2" placeholder="1boy, faceless male"></textarea></label>
                            <label>Ensemble tags<textarea id="loraMetaEnsembleTags" class="textarea code" rows="2" placeholder="3girls, cooperative sex"></textarea></label>
                            <div class="settings-actions-inline">
                                <button id="applyLoraIntakeSuggestion" class="button ghost" type="button">Apply NSFW Suggestion</button>
                                <button id="applyAllLoraIntakeSuggestions" class="button ghost" type="button">Classify Missing</button>
                            </div>
                            <label>Notes<textarea id="loraMetaNotes" class="textarea" rows="2"></textarea></label>
                            <div class="lora-pack-settings">
                                <div class="panel-heading compact-heading">
                                    <h3>Variants & References</h3>
                                    <button id="saveLoraVariant" class="button ghost" type="button">Save Variant</button>
                                </div>
                                <div id="loraVariantList" class="variant-list"></div>
                                <div class="settings-form">
                                    <label>Variant key<input id="loraVariantKey" class="input" placeholder="default"></label>
                                    <label>Label<input id="loraVariantLabel" class="input" placeholder="Default pose pack"></label>
                                    <label>Weight override<input id="loraVariantWeight" class="input" type="number" step="0.05" placeholder="optional"></label>
                                    <label>Enabled<select id="loraVariantEnabled" class="select"><option value="true">On</option><option value="false">Off</option></select></label>
                                </div>
                                <label>Variant trigger words<textarea id="loraVariantTriggers" class="textarea code" rows="2"></textarea></label>
                                <label>Variant positive tags<textarea id="loraVariantPositive" class="textarea code" rows="3"></textarea></label>
                                <div class="settings-form">
                                    <label>Clothing policy<select id="loraVariantClothingPolicy" class="select">
                                        <option value="incidental">Incidental - strip if outfit/clothing active</option>
                                        <option value="required">Required - warn/block conflicts</option>
                                        <option value="override">Override - requires Outfit Ninguno</option>
                                        <option value="forbidden">Forbidden - strip/negative clothing</option>
                                    </select></label>
                                    <label>Strip incidental clothing<select id="loraVariantStripClothing" class="select"><option value="true">On</option><option value="false">Off</option></select></label>
                                </div>
                                <label>Detected / clothing tags<textarea id="loraVariantClothingTags" class="textarea code" rows="2" placeholder="skirt, pantyhose, shirt"></textarea></label>
                                <label>Required clothing tags<textarea id="loraVariantRequiredClothing" class="textarea code" rows="2" placeholder="skirt lift, pantyhose pull"></textarea></label>
                                <button id="detectLoraVariantClothing" class="button ghost" type="button">Auto-detect Clothing Tags</button>
                                <label>Variant negative tags<textarea id="loraVariantNegative" class="textarea code" rows="2"></textarea></label>
                                <div class="settings-form">
                                    <label>Compatible acts<textarea id="loraVariantCompatibleActs" class="textarea code" rows="2"></textarea></label>
                                    <label>Incompatible acts<textarea id="loraVariantIncompatibleActs" class="textarea code" rows="2"></textarea></label>
                                </div>
                                <label>Act groups<input id="loraVariantActGroups" class="input"></label>
                                <div class="settings-form">
                                    <label>Requires secondary<select id="loraVariantRequiresSecondary" class="select"><option value="false">No</option><option value="true">Yes</option></select></label>
                                    <label>Min secondary<input id="loraVariantMinSecondary" class="input" type="number" min="0" max="3"></label>
                                    <label>Max secondary<input id="loraVariantMaxSecondary" class="input" type="number" min="0" max="3"></label>
                                </div>
                                <label>Anonymous partner tags<textarea id="loraVariantAnonymousPartnerTags" class="textarea code" rows="2"></textarea></label>
                                <label>Ensemble tags<textarea id="loraVariantEnsembleTags" class="textarea code" rows="2"></textarea></label>
                                <label>Variant notes<textarea id="loraVariantNotes" class="textarea" rows="2"></textarea></label>
                                <div class="lora-ref-upload">
                                    <label>Reference variant<select id="loraReferenceVariant" class="select"></select></label>
                                    <label>Caption<input id="loraReferenceCaption" class="input" placeholder="optional"></label>
                                    <label class="file-input">Reference image<input id="loraReferenceFile" type="file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
                                    <button id="uploadLoraReference" class="button ghost" type="button">Upload Reference</button>
                                </div>
                                <div id="loraReferenceGrid" class="lora-reference-grid"></div>
                                <div id="loraPackStatus" class="settings-status">Pack idle.</div>
                            </div>
                        </div>
                    </div>
                    <pre id="loraAuditStatus" class="payload-preview audit-preview">Audit not run.</pre>
                </section>
                <section class="panel prompt-debug-panel" data-settings-section="debug">
                    <div class="panel-heading"><h2>Prompt Debug</h2><button id="saveSettings" class="button primary" type="button">Save Settings</button></div>
                    <label>System template<textarea id="settingSystemTemplate" class="textarea code" rows="4"></textarea></label>
                    <label>User template<textarea id="settingUserTemplate" class="textarea code" rows="7"></textarea></label>
                    <button id="refreshLlmPayload" class="button ghost" type="button">Preview LLM Payload</button>
                    <pre id="llmPayloadPreview" class="payload-preview"></pre>
                </section>
            </div>
            </div>
        </section>
    </main>
</div>

<div id="imageModal" class="image-modal" hidden>
    <button id="modalBackdrop" class="modal-backdrop" type="button" aria-label="Close image viewer"></button>
    <div class="modal-shell" role="dialog" aria-modal="true" aria-label="Gallery image viewer">
        <div class="modal-image-wrap">
            <img id="modalImage" alt="Generated image">
            <div id="modalCompareStrip" class="modal-compare-strip" hidden>
                <button id="modalCompareBefore" class="compare-card" type="button">
                    <img id="modalCompareBeforeImage" alt="Original image">
                    <span>Original</span>
                </button>
                <button id="modalCompareAfter" class="compare-card active" type="button">
                    <img id="modalCompareAfterImage" alt="Result image">
                    <span>Result</span>
                </button>
            </div>
        </div>
        <aside class="modal-details">
            <div class="modal-heading">
                <div>
                    <h2 id="modalTitle">Gallery Image</h2>
                    <p id="modalMeta"></p>
                </div>
                <button id="closeImageModal" class="icon-toggle" type="button" aria-label="Close">×</button>
            </div>
            <div id="modalBadges" class="modal-badges"></div>
            <div id="modalSceneSummary" class="modal-scene-summary"></div>
            <label>Prompt<textarea id="modalPrompt" class="textarea code" rows="7" readonly></textarea></label>
            <label>Negative<textarea id="modalNegative" class="textarea code" rows="4" readonly></textarea></label>
            <details class="modal-layers-details">
                <summary>Prompt Layers / Restore Input</summary>
                <pre id="modalLayerSummary" class="payload-preview modal-layer-summary"></pre>
            </details>
            <div class="modal-actions">
                <button id="modalDownload" class="button ghost" type="button">Download</button>
                <button id="modalCopyPrompt" class="button ghost" type="button">Copy Prompt</button>
                <button id="modalCopySeed" class="button ghost" type="button">Copy Seed</button>
                <button id="modalUsePlanner" class="button ghost" type="button">Use in Planner</button>
                <button id="modalVariation" class="button ghost" type="button">Variation</button>
                <select id="modalHiresProfile" class="select modal-profile-select" title="Enhance profile"><option value="repair_hiresfix">Repair + Hires.fix</option><option value="repair_preserve">Repair Preserve Quality</option></select>
                <button id="modalEnhance" class="button primary" type="button">Enhance</button>
            </div>
            <div id="modalEnhanceStatus" class="modal-status">idle</div>
        </aside>
    </div>
</div>

<div id="loraReferenceModal" class="image-modal" hidden>
    <button id="loraReferenceBackdrop" class="modal-backdrop" type="button" aria-label="Close reference viewer"></button>
    <div class="modal-shell reference-modal-shell" role="dialog" aria-modal="true" aria-label="LoRA reference viewer">
        <div class="modal-image-wrap contain-image-wrap">
            <img id="loraReferenceModalImage" alt="LoRA reference image">
        </div>
        <aside class="modal-details">
            <div class="modal-heading">
                <div>
                    <h2 id="loraReferenceModalTitle">LoRA Reference</h2>
                    <p id="loraReferenceModalMeta"></p>
                </div>
                <button id="closeLoraReferenceModal" class="icon-toggle" type="button" aria-label="Close">×</button>
            </div>
            <p id="loraReferenceModalCaption" class="reference-caption"></p>
        </aside>
    </div>
</div>
<script type="module">
    import <?= json_encode(($appBasePath ?? '') . '/assets/app.js?v=' . (string) filemtime(__DIR__ . '/../assets/app.js'), JSON_UNESCAPED_SLASHES) ?>;
</script>
</body>
</html>
