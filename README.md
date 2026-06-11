# Character Studio

<p align="center">
  <img src="https://i.ibb.co/7t40RcFt/logo.png" alt="Character Studio logo" width="260">
</p>

<p align="center">
  <strong>Design characters. Direct scenes. Generate images.</strong>
</p>

<p align="center">
  A visual planning studio for Stable Diffusion that turns prompt engineering into structured creative decisions.
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.3+-777BB4">
  <img alt="PostgreSQL" src="https://img.shields.io/badge/PostgreSQL-16-336791">
  <img alt="Docker" src="https://img.shields.io/badge/Docker-Ready-2496ED">
  <img alt="Stable Diffusion" src="https://img.shields.io/badge/Stable%20Diffusion-Compatible-orange">
  <img alt="Forge" src="https://img.shields.io/badge/Forge-Supported-green">
  <a href="https://discord.gg/48kesXCk7X">
    <img src="https://img.shields.io/discord/000000000000000000?label=Discord&logo=discord&color=5865F2" alt="Discord">
  </a>
</p>

## Overview

Character Studio is a web workspace for planning and generating Stable Diffusion images from structured choices instead of one long prompt wall.

Creators assemble character profiles, appearance details, outfits, poses, scene direction, LoRAs, generation settings, and optional LLM prompt polish. The app then composes those choices into a generation-ready prompt, audits the result for conflicts, streams generation progress, and stores the output with reusable metadata.

It is built with PHP, Apache, PostgreSQL, Docker, and plain browser JavaScript. It is designed to run alongside Automatic1111, Forge, or another Stable Diffusion API compatible with the common `/sdapi/v1` endpoints.

## Why Character Studio

Stable Diffusion workflows can become hard to maintain when every image depends on copied prompt fragments, forgotten LoRA triggers, manual seed notes, and hidden conflicts between clothing, poses, and scene intent.

Character Studio gives those decisions a visible structure:

- Reusable character and outfit planning.
- Scene direction through controls instead of buried prompt text.
- Pose and LoRA metadata with compatibility rules.
- Prompt audit checks before spending a generation.
- Prompt transparency, restore, remix, variation, and gallery workflows.

The goal is not to hide prompt craft. The goal is to make it repeatable.

## Creative Workflow

```text
Select character
  -> Configure appearance
  -> Choose outfit
  -> Select pose
  -> Configure scene director
  -> Add LoRAs
  -> Review prompt layers and audit results
  -> Generate image
  -> Save, restore, remix, enhance, or download
```

## Core Features

### Character Studio

Create reusable character profiles with reference images, prompt fragments, appearance presets, outfit choices, and generation metadata. Supporting characters can be added to build multi-character scenes while keeping prompt layers organized.

### Scene And NSFW Direction

Direct scene composition through structured controls for act, intensity, focus, expression, camera, clothing state, visual effects, and strict compatibility behavior. The director system helps catch incompatible combinations before generation.

### Pose Library

Use a metadata-driven pose library with categories, search, prompt injection, camera hints, act compatibility, and warning or blocking behavior depending on the selected direction.

### LoRA Intelligence

Manage LoRAs as first-class assets instead of loose prompt snippets. LoRA records can include trigger words, aliases, variants, reference images, categories, clothing policies, compatible and incompatible acts, scene intent, and audit metadata.

### Prompt Composer And Audit

The prompt composer builds a visible final prompt from structured layers:

```text
Character
+ Appearance
+ Outfit
+ Supporting characters
+ Pose
+ Scene direction
+ LoRAs
+ User modifiers
+ Optional LLM polish
= Final prompt
```

The audit system checks prompt integrity, director constraints, LoRA conflicts, clothing conflicts, strict act rules, and other combinations that should not silently pass into generation.

### Optional LLM Prompt Polish

Prompt polish can run against an OpenAI-compatible chat completions endpoint. It supports manual one-click polish, persistent Auto LLM mode, optional API key storage, and seed-aware polish caching so repeated generations with a locked seed do not receive a different LLM rewrite.

### Generation Workspace

Generate directly through a compatible Stable Diffusion API with streaming progress, preview updates, seed locking, Hires.fix profiles, image size controls, interrupt support, and stored generation metadata.

### Gallery And History

Generated images are stored with enough metadata to restore prompts, recover settings, create variations, download outputs, and continue an iterative creative workflow later.

## Architecture

```text
Browser UI
  |
  v
PHP / Apache API
  |-- PostgreSQL runtime database
  |-- data/ runtime assets
  |-- Stable Diffusion API
  +-- Optional OpenAI-compatible LLM API
```

PostgreSQL is the default runtime database for Docker installs. Runtime assets live under `data/` and are intentionally ignored by Git.

The Docker stack is self-contained: it builds the PHP/Apache application container and starts PostgreSQL for you. You do not need PHP, Apache, or Nginx installed on the host. A reverse proxy such as Nginx Proxy Manager is optional and only needed if you want to publish the app behind your own domain.

## Quick Start

Copy the example environment and start the app:

```bash
cp .env.example .env
docker compose up -d --build app
```

Open the app:

```text
http://localhost:8088
```

By default, the app expects Stable Diffusion WebUI or Forge to be running on the host machine at:

```text
http://host.docker.internal:7860
```

Stable Diffusion must expose its API. For Automatic1111 or Forge, start the WebUI with API support enabled, usually with:

```bash
--api --listen
```

Edit `SD_BASE_URL` in `.env` depending on where Stable Diffusion runs:

| Stable Diffusion location | `SD_BASE_URL` value |
| --- | --- |
| On the same computer, outside Docker | `http://host.docker.internal:7860` |
| On another computer in your LAN | `http://YOUR_SD_HOST:7860` |
| As another Docker Compose service | `http://stable-diffusion:7860` |
| On the same host without Docker | `http://127.0.0.1:7860` |

After changing `.env`, rebuild or restart the app container:

```bash
docker compose up -d --build app
```

To run a second test install on a machine that already has another Character Studio stack, set a unique project name and ports in `.env` before starting:

```env
COMPOSE_PROJECT_NAME=character-studio-test
APP_PORT=8090
POSTGRES_PORT=5433
```

## Configuration

Important environment variables:

| Variable | Purpose |
| --- | --- |
| `APP_PORT` | Host port for the web app. |
| `DB_DRIVER` | Database driver, defaulting to `pgsql` for Docker installs. |
| `DATABASE_URL` | PostgreSQL connection URL. |
| `SD_BASE_URL` | Automatic1111 or Forge API base URL. |
| `LLM_BASE_URL` | OpenAI-compatible chat completions API base URL. |
| `LLM_MODEL` | Model name used for optional prompt polish. |
| `LLM_API_KEY` | Optional bearer token for the LLM endpoint. |
| `UMA_BASE_LORA_ALIAS` | Base LoRA alias used by the prompt composer. |

For deployments that need an external `npm` Docker network, use the optional override:

```bash
docker compose -f docker-compose.yml -f docker-compose.npm.yml up -d --build app
```

Do not commit real secrets from `.env`. Use `.env.example` as the installation template.

## First Setup In The App

After opening Character Studio:

1. Go to `Settings -> Characters`.
2. Create or edit a series, then save it with `Save Series`.
3. Create a character with `New Character`, assign the saved series, add feature tags, appearances, outfits, and optional base LoRA data.
4. Save with `Save Character`.
5. Upload a character image only after the character has been saved.

The public sample pack gives you a working starting point, but your own characters and series are meant to be created from the UI.

## Runtime Data

Runtime data is stored under `data/` and is not committed:

- Generated images.
- Character reference images.
- LoRA reference images.
- Local runtime caches.
- Environment-specific database or backup files.

PostgreSQL is the source of truth for Docker deployments.

## Seed Packs

Character Studio can load seed packs for sample characters, prompt presets, and LoRA metadata. Public sample packs live under `packs/public/`.

Keep personal production packs outside Git in the ignored private pack directory. That is the right place for real character catalogs, LoRA metadata, prompt presets, and project-specific rules.

## Validation

Useful local checks:

```bash
php scripts/verify-prompt-flow.php
docker compose config
```

Health and audit endpoints:

```text
GET /api/health
GET /api/nsfw/audit
GET /api/director/audit/full
GET /api/lora/audit
```

## Project Notes

Character Studio is designed for creators who need consistency across characters, scenes, LoRAs, and generations. It works best when LoRA metadata, character references, and director rules are maintained as part of the creative workflow instead of treated as disposable prompt text.

## Support Character Studio

Character Studio is developed and maintained in spare time.

If the project saves you time, improves your workflow, or helps your creative process, consider supporting future development.

Your support helps fund:

- New features
- Better LoRA management tools
- Additional prompt systems
- Documentation
- Long-term maintenance

### Community

Join the Discord for project updates, setup notes, roadmap discussion, feature suggestions, and bug reports.

https://discord.gg/48kesXCk7X

### Donate

☕ Ko-fi:
https://ko-fi.com/naixxier

Patreon:
https://www.patreon.com/Naixxier/posts/character-studio-160760524?utm_medium=clipboard_copy&utm_source=copyLink&utm_campaign=postshare_creator&utm_content=join_link

❤️ Every contribution helps keep the project alive.

## Vision

Character Studio aims to be the workspace between an idea and a generated image: a place to define reusable visual identities, direct scenes, manage LoRAs, audit conflicts, and generate with less repetitive prompt rebuilding.

Spend less time rebuilding prompts and more time creating images.

## License

Character Studio is source-available software.

The source code may be viewed and used for personal or internal purposes, but redistribution, resale, hosting as a competing service, or commercial distribution without explicit permission is prohibited. Character Studio is not open source software.

See [LICENSE](LICENSE) for full details.
