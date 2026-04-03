# AI Content Forge

AI Content Forge is a WordPress plugin for generating editorial content with Anthropic Claude, OpenAI, or Ollama. It adds:

- a dedicated `AI Content Forge` wp-admin sidebar menu
- a Gutenberg sidebar for on-demand generation inside the block editor
- REST endpoints for generation, provider status, and model discovery

The current packaged release is `v2.4.6`.

## Features

- Generate full post body HTML
- Generate SEO titles
- Generate meta descriptions
- Generate excerpts
- Choose a global default provider
- Override the provider per generation run
- Control shared generation defaults such as `max_output_tokens`, `max_thinking_tokens`, and `temperature`
- Auto-check OpenAI, Claude, and Ollama connectivity from wp-admin as soon as the required API key or base URL is present
- Auto-load available provider models into a dropdown after a successful connection check
- Streaming generation with real-time token delivery in the block editor
- Run Usage panel: shows provider, model, token counts, and estimated USD cost after each generation run
- Post Usage Totals panel: cumulative token and cost breakdown per provider for the current editing session

## Requirements

- WordPress `6.4+`
- PHP `8.1+`
- Node `18+` only when building from source
- At least one configured provider

## Release Install

Use the packaged zip if you just want to install the plugin in WordPress.

1. Download the latest versioned package such as `ai-content-forge-v2.4.6.zip` from the latest GitHub release.
2. In WordPress admin, go to `Plugins -> Add Plugin -> Upload Plugin`.
3. Upload the versioned plugin archive.
4. Click `Install Now`, then `Activate Plugin`.
5. Open `AI Content Forge` in wp-admin and configure at least one provider.

## Build From Source

Clone the repo and build the Gutenberg assets before packaging a release.

```bash
git clone https://github.com/charettep/wordpress-ai-content-forge-plugin.git
cd wordpress-ai-content-forge-plugin/gutenberg
npm install --package-lock=false
npm run build
cd ..
./build-release.sh
```

That produces:

- `gutenberg/build/index.js`
- `gutenberg/build/index.asset.php`
- `ai-content-forge-vX.Y.Z.zip`

## Local Development Install

To test from source without using the release zip:

1. Build the Gutenberg assets.
2. Copy the plugin directory into `wp-content/plugins/ai-content-forge`.
3. Activate `AI Content Forge` in wp-admin.

Example:

```bash
cp -R /path/to/wordpress-ai-content-forge-plugin /path/to/wp-content/plugins/ai-content-forge
```

The plugin directory name inside WordPress must be `ai-content-forge` so the main file path resolves to:

```text
wp-content/plugins/ai-content-forge/ai-content-forge.php
```

## Docker Development

This repo includes a Docker-based WordPress development stack.

1. Run the interactive setup:

```bash
./docker-setup.sh
```

The setup script:

- prompts for database credentials, site ports, and WordPress admin details
- writes those values to a local `.env`
- starts the Compose stack
- installs WordPress if needed
- installs and activates the latest built `ai-content-forge-v*.zip` from the repo root

Notes:

- `docker-compose.yml` reads values from `.env`, and `.env.example` shows the available variables.
- The site URL is `http://localhost:<SITE_PORT>`.
- The phpMyAdmin URL is `http://localhost:<PMA_PORT>`.
- The plugin repo is mounted into the containers as a read-only workspace at `/workspace/ai-content-forge`, not as the live plugin directory.
- Build a new release archive and reinstall it to test updates cleanly:

```bash
./build-release.sh
./docker-install-plugin.sh
```

- When Ollama is running on the Docker host at `127.0.0.1:11434`, the bundled `ollama-proxy` service exposes it to containerized PHP at `host.docker.internal:${OLLAMA_PROXY_PORT}`.
- Rebuild `gutenberg/build/` after changing `gutenberg/src/`.
- `docker compose run --rm wpcli <command>` is available for WP-CLI tasks such as `plugin list` or `post list`.

## Configuration

Open `AI Content Forge` in wp-admin.

### Default Provider

Used whenever the generator UI does not specify a provider override.

### Anthropic Claude

- `API Key`: Anthropic API key
- `Model`: left blank until an API key is provided, then automatically populated from the Anthropic Models API after the key is detected and validated

### OpenAI

- `API Key`: OpenAI API key
- `Model`: left blank until an API key is provided, then automatically populated from the OpenAI Models API after the key is detected and validated

### Ollama

- `Base URL`: defaults to `http://localhost:11434`
- `Model`: left blank until the Ollama server is reached, then automatically populated from the Ollama tags API

Important:

- `localhost` is resolved from the WordPress runtime, not from your browser tab.
- In Docker, `localhost` means the container.
- When this plugin runs in the bundled Docker stack and the Ollama Base URL is `http://localhost:11434`, the backend automatically retries against the Docker host bridge exposed by `ollama-proxy`.

### Generation Defaults

- `Max Output Tokens`: visible answer budget; shorter content types are capped lower internally
- `Max Thinking Tokens`: reasoning budget for thinking-capable models, adapted per provider API
- `Temperature`: global creativity control

### Live Provider Status

- Anthropic Claude and OpenAI are checked automatically after the API key field becomes non-empty
- Ollama is checked automatically after the Base URL field becomes non-empty
- a green `Connected` status appears beside the provider heading after a successful check
- the `Model` dropdown is refreshed with the models returned by that provider API
- the selected model becomes the saved active model used for later generation after you click `Save Settings`

## User Guide

### Settings Screen

Use the settings screen to:

- store provider credentials
- choose the default provider
- set baseline generation behavior
- confirm provider connectivity and choose the exact model before editing content

### Gutenberg Sidebar

Open the block editor for a post or page, then open `AI Content Forge` from the editor sidebar / more-menu entry.

Available controls:

- `Content Type`
- `AI Provider`
- `Keywords / Topic hints`
- `Tone`
- `Language`

Generation streams tokens in real time from the provider into the `Result` panel. A `Stop` button appears during streaming. After generation completes, the sidebar exposes:

- `Copy`
- `Apply to Post`

#### Run Usage

The `Run Usage` panel is always visible below the Generate controls. It shows the token breakdown and estimated cost for the most recent generation run:

- `Provider`: which provider answered the request
- `Model`: the exact model used
- `Input Tokens`: tokens consumed by the prompt
- `Thinking Tokens`: reasoning tokens (where the provider exposes them separately)
- `Output Tokens`: tokens in the generated answer
- `Total Tokens`: combined input + output
- `Cost (USD)`: estimated cost at current published pricing

The panel displays a placeholder until the first generation run completes in the current page session.

#### Post Usage Totals

The `Post Usage Totals` panel (collapsed by default) accumulates token counts and cost across every generation run for the current post during the editing session, grouped by provider. This resets on page reload.

### What Each Content Type Does

#### Post Content

- Generates HTML intended for the block editor
- Applies the result by converting the generated HTML into native Gutenberg blocks such as paragraphs, headings, lists, and code blocks when possible
- Falls back to a single `Custom HTML` block only if Gutenberg cannot parse the generated markup

This is destructive to the current editor canvas, so generate carefully if the post already contains work you want to keep.

#### SEO Title

- Generates a short SEO-style title
- Applies the result by overwriting the current post title

#### Excerpt

- Generates a short excerpt
- Applies the result by overwriting the current post excerpt

#### Meta Description

- Generates a meta description
- Applies the result to `_acf_meta_description` post meta in the editor and saves it with the post

Important:

- this plugin stores that value in post meta and exposes it in the editor / REST API
- it does not display that meta key on the frontend by itself, so you still need theme code or an SEO integration if you want it surfaced elsewhere

## Prompt Behavior

The generator builds prompts from:

- post title
- keywords
- tone
- language
- existing content
- post type

Behavior by type:

- `post_content`: aims for a structured article with headings and HTML output
- `seo_title`: aims for a 50 to 60 character title
- `meta_description`: aims for a 150 to 160 character description
- `excerpt`: aims for a 40 to 55 word excerpt

## REST API

Namespace:

```text
/wp-json/ai-content-forge/v1
```

All endpoints require:

- a logged-in user with `edit_posts`
- a valid REST nonce

### `POST /generate`

Parameters:

| Parameter | Required | Notes |
| --- | --- | --- |
| `type` | yes | `post_content`, `seo_title`, `meta_description`, `excerpt` |
| `provider` | no | empty string uses the global default |
| `title` | no | post title context |
| `keywords` | no | keyword hints |
| `tone` | no | defaults to `professional` |
| `language` | no | defaults to `English` |
| `existing_content` | no | existing body content for context |
| `post_type` | no | defaults to `post` |

Successful response:

```json
{
  "success": true,
  "result": "Generated text here"
}
```

### `POST /generate-stream`

Same parameters as `POST /generate`. Returns a `text/event-stream` SSE response. The client receives:

| Event | Payload |
| --- | --- |
| `start` | `{ "success": true }` |
| `chunk` | `{ "text": "…" }` one or more times |
| `usage` | `{ "provider", "model", "input_tokens", "thinking_tokens", "output_tokens", "total_tokens", "cost_usd" }` |
| `done` | `{ "success": true, "usage": { … } }` |
| `error` | `{ "success": false, "message": "…" }` |

Falls back gracefully to `POST /generate` in environments where SSE streaming is unavailable.

### `POST /generate-stop`

Signals the server to cancel an active streaming generation for the current provider.

| Parameter | Required | Notes |
| --- | --- | --- |
| `provider` | no | empty string uses the global default |

### `POST /test-provider`

Parameters:

| Parameter | Required | Notes |
| --- | --- | --- |
| `provider` | yes | `claude`, `openai`, or `ollama` |

For `claude` and `openai`, this now validates credentials by loading the provider's model list instead of issuing a throwaway generation request.

### `POST /sync-provider`

This endpoint is used by the wp-admin settings screen for live API key validation and model discovery.

Permissions:

- logged-in user with `manage_options`
- valid REST nonce

Parameters:

| Parameter | Required | Notes |
| --- | --- | --- |
| `provider` | yes | `claude`, `openai`, or `ollama` |
| `api_key` | conditional | required for `claude` and `openai`; unsaved API key currently typed in the form |
| `base_url` | conditional | required for `ollama`; unsaved base URL currently typed in the form |
| `current_model` | no | currently selected or previously saved model |

### `GET /providers`

Returns the provider list with:

- `id`
- `label`
- `is_configured`
- `is_default`

## Packaging Releases

Use the release script from the plugin root:

```bash
./build-release.sh
```

The script:

- requires the Gutenberg build to exist first
- stages the plugin under the correct runtime folder name: `ai-content-forge`
- creates a clean versioned archive such as `ai-content-forge-v2.4.0.zip`
- includes only runtime plugin files needed for installation
- refuses to overwrite an existing archive for the same version
- excludes development-only directories such as `node_modules`

## Repository Layout

```text
ai-content-forge.php                 Plugin bootstrap and version headers
admin/class-acf-admin.php           Settings screen
admin/class-acf-gutenberg.php       Gutenberg asset loader
assets/css/admin.css                Settings page styles
assets/js/admin.js                  Settings page behavior
includes/class-acf-settings.php     Option storage and sanitization
includes/class-acf-provider.php     Provider base class
includes/class-acf-generator.php    Prompt construction and dispatch
includes/class-acf-rest-api.php     REST routes
includes/providers/                 Claude, OpenAI, Ollama drivers
gutenberg/src/index.js              Sidebar source
gutenberg/build/                    Compiled editor assets
build-release.sh                    Release packaging script
```

## Troubleshooting

### Plugin activation fails

Check that the installed archive contains:

- `admin/class-acf-admin.php`
- `admin/class-acf-gutenberg.php`
- `includes/...`
- `ai-content-forge.php`

The `v2.0.1` package fixes the broken zip layout that caused activation fatals in earlier builds.

### Gutenberg sidebar does not appear

Confirm the compiled assets exist:

```text
gutenberg/build/index.js
gutenberg/build/index.asset.php
```

If they are missing, rebuild with:

```bash
cd gutenberg
npm install --package-lock=false
npm run build
```

### Provider connection fails

Check:

- API key correctness
- whether the provider account exposes at least one supported text model
- outbound network access from the WordPress runtime
- Ollama reachability from the PHP runtime

If OpenAI, Claude, or Ollama connects successfully, the provider header will show `Connected` and the `Model` field will switch to a populated dropdown.

### Generated HTML is not block-native

`Apply to Post` uses Gutenberg's raw HTML conversion pipeline. If output still lands in a `Custom HTML` block, the generated markup likely contains structures Gutenberg cannot safely convert into native blocks.

## Changelog

### `v2.4.6`

- removed hardcoded domain names from Docker configuration, using environment-based configuration instead for multi-environment deployments

### `v2.4.5`

- fixed `Run Usage` panel not appearing in the Gutenberg sidebar: the v2.4.4 zip was packaged from a build where the panel was conditionally hidden until usage data arrived, but Claude never returned usage data; the panel now renders immediately with a placeholder and populates after each run
- added `stream_generate()` to the Claude provider so token usage and estimated cost are returned after generation instead of an empty payload; pricing covers all current Claude 3 and Claude 4 model families
- the `Run Usage` and `Post Usage Totals` panels are now fully functional for all three providers (Claude, OpenAI, Ollama)

### `v2.4.4`

- added streaming generation endpoint (`/generate-stream`) with real-time token delivery via SSE for all providers
- added `generate-stop` REST endpoint for mid-stream cancellation from the block editor
- added `Run Usage` panel to the Gutenberg sidebar showing per-run token counts and estimated cost
- added `Post Usage Totals` panel to the Gutenberg sidebar accumulating cost across runs in the current session
- added OpenAI real SSE streaming with per-event usage extraction and model-aware pricing
- added Ollama streaming with token count reporting from the final stream event

### `v2.4.1`

- split the old single token budget into separate `Max Output Tokens` and `Max Thinking Tokens` controls in the wp-admin settings UI
- mapped those settings per provider backend: Anthropic uses `thinking.budget_tokens`, OpenAI folds reasoning models into the combined response token cap and adjusts reasoning effort, and Ollama expands `num_predict` while toggling `think` for reasoning-capable local models
- kept the Ollama timeout hardening and direct-answer retries so reasoning-heavy local models are less likely to stall or return blank output

### `v2.4.0`

- moved the wp-admin configuration page from `Settings` into its own top-level `AI Content Forge` sidebar menu placed after `Plugins`
- added editable prompt templates in wp-admin for `Post Content`, `SEO Title`, `Meta Description`, and `Excerpt`
- converted the generator to use saved prompt templates with placeholder replacement instead of hard-coded prompt text
- kept the release archive limited to runtime plugin files for installation

### `v2.3.3`

- switched Ollama wp-admin settings to the same live connection and model-discovery flow used by OpenAI and Claude
- removed hardcoded default model placeholders so Claude and OpenAI stay blank until an API key is present, and Ollama stays blank until a reachable server returns models
- added Ollama model discovery through `/api/tags` plus Docker-aware backend fallbacks for host-local Ollama servers
- added Docker compose support for container-to-host Ollama access through `host.docker.internal` and the bundled `ollama-proxy` bridge

### `v2.3.2`

- fixed `Meta Description` apply/save so `_acf_meta_description` is registered in REST and persists with the post
- fixed blank-success OpenAI responses for `gpt-5` when the Responses API consumed `max_output_tokens` on reasoning without returning visible text
- cleaned up Gutenberg sidebar warnings, lint issues, and build instructions for the current toolchain
- replaced hard-coded Docker ports and credentials with an interactive `.env`-driven setup flow

### `v2.2.0`

- replaced the manual Claude and OpenAI `Test Connection` buttons with live API key validation
- added inline `Connected` status badges near the provider headings in wp-admin
- changed the Claude and OpenAI model fields from free text to provider-populated dropdowns
- added a new admin REST flow for unsaved API key validation and model discovery
- updated OpenAI generation to support modern selected models through model-aware request handling
- changed release packaging to versioned zip filenames such as `ai-content-forge-v2.2.0.zip`

### `v2.1.0`

- changed `Apply to Post` for `Post Content` to convert generated HTML into native Gutenberg blocks
- kept a `Custom HTML` fallback only for unparseable markup

### `v2.0.1`

- fixed the broken release packaging that omitted required admin files
- renamed the REST namespace constant to avoid a reserved keyword collision
- made Gutenberg asset loading conditional on compiled build files
- added a deterministic release packaging script
- removed an accidentally committed secret from the project documentation

## License

GPL-2.0+
