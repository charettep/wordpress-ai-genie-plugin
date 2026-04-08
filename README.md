# AI Content Forge

AI Content Forge is a WordPress plugin for generating editorial content with Anthropic Claude, OpenAI, or Ollama. It adds:

- a dedicated `AI Content Forge` wp-admin sidebar menu
- a Gutenberg sidebar for on-demand generation inside the block editor
- REST endpoints for generation, provider status, and model discovery

The current packaged release is `v2.12.0`.

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
- Optional Ollama access header support for secured remote endpoints such as Cloudflare Access single-header mode
- Streaming generation with real-time token delivery in the block editor
- Run Usage panel: shows provider, model, token counts, and estimated USD cost after each generation run
- Post Usage Totals panel: cumulative token and cost breakdown per provider for the current editing session
- Context Scope control: choose full post, selected blocks, custom pasted context, or none
- Post Content structure + target length controls to shape output format and size
- Exact Post Content target length input with a linked `1-10000` word slider
- Advanced per-run overrides for model, prompt template, max output tokens, max thinking tokens, and temperature

## Changelog

### v2.12.0 — Top-Bar Generation Defaults

- removes the separate `Generation` tab from the wp-admin settings page
- lets you change the default provider directly from the top summary bar by clicking the provider chips
- marks the active default provider with a star in the summary bar
- makes `Max Output Tokens`, `Max Thinking Tokens`, and `Temperature` directly editable in the top summary bar

### v2.11.2 — Admin Menu Icon Fix

- stops passing the large branded PNG directly into WordPress admin menu registration
- uses a compact menu-safe SVG icon for the left admin navigation entry so the menu icon renders at the correct size and position
- keeps the updated bundled `plugin-icon.png` for page and editor branding where image sizing is explicitly controlled

### v2.11.1 — Stricter WordPress Formatting Templates

**Prompt templates**

- tightened the built-in default prompt templates so generated content follows stricter WordPress-safe formatting rules
- expanded the `post_content` default template with explicit guidance for headings, paragraphs, lists, links, tables, inline formatting, code, embeds, images, buttons, columns, accordions, footnotes, and page breaks
- tightened the `seo_title`, `meta_description`, and `excerpt` defaults so they return plain text only with no stray HTML, markdown, labels, or quotes

**Existing installs**

- added a safe prompt-template migration so sites still using the old built-in defaults automatically receive the new stricter defaults
- customized prompt templates are preserved as-is

### v2.11.0 — Sidebar Word Target + Prompt Override + Packaging Fix

**Gutenberg sidebar**

- replaced the hardcoded `Target Length` dropdown with an exact numeric `TARGET LENGTH (WORDS)` input
- added a linked slider for the same word target from `1` to `10000`
- kept the target length wired into the prompt placeholder flow so the generated prompt still includes the requested approximate word count
- added a per-run `Prompt Template Override` textarea in the `Advanced` panel
- added `Load Saved Prompt` and `Clear Override` actions so you can start from the saved template and tweak it for one generation only

**Release packaging**

- fixed the release zip builder so it now includes the `images/` directory
- added an archive validation check for `images/plugin-icon.png` to prevent future broken releases

**Docs**

- updated the README, Worker proxy guide, and wp-admin Ollama setup instructions to match the verified Playground-safe Worker flow and the new Gutenberg controls

### v2.10.0 — Worker Proxy Guidance + Real Plugin Icons

**Cloudflare Worker proxy**

- updated the autonomous Worker deployment script so its help text and final output clearly distinguish the verified browser-safe Worker proxy path from the direct upstream Access-header path for normal server-hosted WordPress
- clarified in the docs and wp-admin setup guide that WordPress Playground and other browser-executed WordPress runtimes must use the Worker proxy values after the upstream protected Ollama hostname is already working
- fixed Gutenberg streaming on scoped WordPress runtimes such as WordPress Playground by resolving the stream endpoint from WordPress's localized REST base URL instead of a hardcoded root-relative `/wp-json/...` path

**Frontend icons**

- replaced the generic plugin and provider glyphs in the admin page and Gutenberg sidebar with the bundled real PNG assets from `images/`
- the admin menu, wp-admin title area, provider cards, provider headers, and Gutenberg sidebar chrome now use the shipped plugin/provider artwork instead of placeholder shapes or emoji

**Docs**

- updated README and the wp-admin Ollama Setup guide to document the direct-vs-Worker decision explicitly and show the exact Worker proxy values that should be pasted into the plugin
- refreshed the Worker proxy guide so it explains why the Worker must terminate browser CORS and strip browser-origin headers before forwarding to the protected upstream Ollama hostname

### v2.9.1 — Worker Proxy Browser Header Fix

**Cloudflare Worker proxy**

- fixed the Worker proxy so it no longer forwards browser-only headers such as `Origin`, `Referer`, and fetch metadata to the upstream Access-protected Ollama hostname
- this prevents Cloudflare Access from re-entering its browser CORS path on the upstream request, which was causing `HTTP 403` in browser-based WordPress runtimes such as WordPress Playground even though terminal `curl` tests succeeded
- the Worker now forwards only the minimal upstream request headers required by the Ollama API plus the upstream Cloudflare Access credentials

**Docs**

- clarified that the Worker proxy is specifically meant to terminate browser CORS at the Worker layer and send a clean server-style upstream request to the protected Ollama hostname

### v2.9.0 — Worker Proxy for Browser-Based WordPress

**Cloudflare Worker proxy**

- added a deployable Worker proxy source file for browser-based WordPress runtimes such as WordPress Playground
- added `scripts/create-ollama-worker-proxy.sh` to build and deploy the Worker route, set Worker secrets, create the required DNS record, and print the final plugin values
- the Worker proxy handles browser CORS preflight and keeps the upstream Cloudflare Access service-token credentials out of WordPress

**Docs**

- added a dedicated Worker proxy guide for Playground and other browser-executed WordPress environments
- clarified when to use the direct upstream Ollama hostname versus the Worker proxy hostname
- documented the exact plugin header values for the Worker proxy path

### v2.8.0 — Ollama Remote Access Hardening

**Cloudflare / Ollama wizard**

- prompts directly for `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_ZONE_ID`, and the full desired `OLLAMA_PUBLIC_HOSTNAME`
- creates or reuses the Cloudflare Tunnel, DNS route, Access app, Service Auth policy, and service token through the API
- merges the Ollama hostname into the existing managed tunnel ingress instead of replacing unrelated tunnel routes
- stores the Cloudflare Access header JSON in `.env` in an escaped Docker Compose-safe form
- prints the raw `Access Header Value` separately in terminal so it can be pasted directly into the wp-admin Ollama `Header Value` field

**Admin settings page**

- fixed the `👁` reveal buttons for Claude, OpenAI, and Ollama secret fields so they now target the adjacent input reliably
- added explicit pressed-state metadata to the reveal buttons for clearer behavior and accessibility

### v2.7.0 — Frontend Rehaul

**Admin settings page**

- Tabbed layout with 4 panels: Providers, Generation, Prompts, Ollama Setup
- Summary strip at the top: active provider+model, token and temperature values, live status badges for all 3 providers
- Sticky save footer with unsaved-changes notice and Discard button
- Provider cards now show inline SVG logos and a pulsing checking animation
- Show/hide toggle button (👁) for all API key and secret fields
- Ollama auth header fields collapsed inside a `<details>` disclosure (auto-opens if values are saved)
- Prompt Templates tab: two-panel split layout — left rail to select prompt type, right pane for the editor
- Shared CSS design tokens across all admin surfaces

**Gutenberg sidebar**

- Provider status chip below the panel header (active provider + model name)
- `@wordpress/icons` `create` icon replaces the dashicon string
- Provider dropdown labels no longer use colored emoji circles
- Run Usage and Post Usage Totals merged into a single Usage panel

## Requirements

- WordPress `6.4+`
- PHP `8.1+`
- Node `18+` only when building from source
- At least one configured provider

## Compatibility And Hosting

- Release packages are intended to be installed, updated, and activated directly from `Plugins -> Add Plugin -> Upload Plugin` in WordPress `wp-admin`.
- Core plugin compatibility follows the supported stack for the WordPress site that runs it: WordPress `6.4+`, PHP `8.1+`, and the MariaDB/MySQL versions supported by that WordPress release.
- OpenAI and Anthropic Claude features work on self-hosted and managed/cloud-hosted WordPress sites as long as the server running WordPress can make outbound HTTPS requests to those provider APIs.
- Ollama features work only when the WordPress/PHP runtime can reach the configured Ollama base URL. The plugin does not bundle or host Ollama itself.
- Remote Ollama deployments can use one optional outbound access header, which is designed to work with Cloudflare Access single-header mode and similar authenticated gateways.
- Docker and `cloudflared` are optional operational tools. They are not required for the plugin to work on a normal WordPress site.
- The helper scripts in `scripts/` require Docker Engine with Docker Compose v2 (`docker compose`).
- No plugin feature is pinned to a specific Docker or `cloudflared` version. For Ollama, the plugin expects a server build that supports model listing via `/api/tags`, chat generation via `/api/chat`, streaming chat responses, and `keep_alive`-based model unload behavior.

### Self-Hosted vs Managed/Cloud-Hosted WordPress

#### OpenAI and Claude

- Self-hosted: usually works once the server can reach the public internet over HTTPS.
- Managed/cloud-hosted: usually works if the host allows standard outbound HTTPS requests from WordPress.

#### Ollama

- Self-hosted: simplest when WordPress and Ollama run on the same machine or the same private network. Typical examples are `http://localhost:11434` or a LAN/private host reachable from PHP.
- Managed/cloud-hosted: only works if the hosting provider allows the WordPress runtime to reach your Ollama server over a permitted network path, such as a secured public HTTPS endpoint, reverse proxy, VPN/private network link, or other host-approved outbound route.
- Recommended secure path: publish Ollama through a dedicated Cloudflare Tunnel hostname and protect it with Cloudflare Access service auth in single-header mode, then paste that hostname plus the single header name and value into the plugin settings.
- Browser-based WordPress runtimes such as WordPress Playground should use the Cloudflare Worker proxy path instead of sending the Cloudflare Access header directly from the browser.
- If the hosting platform does not allow outbound connections to your Ollama server, use OpenAI or Claude instead.

## Release Install

Use the packaged zip if you just want to install the plugin in WordPress.

1. Download the latest versioned package such as `ai-content-forge-v2.12.0.zip` from the latest GitHub release.
2. In WordPress admin, go to `Plugins -> Add Plugin -> Upload Plugin`.
3. Upload the versioned plugin archive.
4. Click `Install Now`, then `Activate Plugin`.
5. Open `AI Content Forge` in wp-admin and configure at least one provider.
6. For updates, upload the newer zip through the same `Upload Plugin` flow and let WordPress replace the previous installed version.

## Build From Source

Clone the repo and build the Gutenberg assets before packaging a release.

```bash
git clone https://github.com/charettep/wordpress-ai-content-forge-plugin.git
cd wordpress-ai-content-forge-plugin/gutenberg
npm install --package-lock=false
npm run build
cd ..
./scripts/build-release.sh
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

This repo includes an optional Docker-based WordPress development stack for local testing of the plugin package.

1. Run the interactive setup:

```bash
./scripts/docker-setup.sh
```

The setup script:

- starts from the tracked `.env.example` template
- creates a local `.env` if it does not exist yet
- preserves existing `.env` values when you re-run it
- generates secure random MariaDB credentials (user password + root password) for blank fields
- generates unique random local MariaDB database and username defaults for blank fields
- prompts for database credentials, site ports, and WordPress admin details
- writes those values back to the local `.env`
- starts the Compose stack
- installs WordPress if needed
- installs and activates the latest built `ai-content-forge-v*.zip` from the repo root

Notes:

- `docker-compose.yml` reads required values from `.env`, and `.env.example` is the committed blank template for the variables used by local helper scripts.
- `.env` is gitignored and intended only for local machine-specific values and secrets.
- The site URL is `http://localhost:<SITE_PORT>`.
- The phpMyAdmin URL is `http://localhost:<PMA_PORT>`.
- The plugin repo is mounted into the containers as a read-only workspace at `/workspace/ai-content-forge`, not as the live plugin directory.
- Build a new release archive and reinstall it to test updates cleanly:

```bash
./scripts/build-release.sh
./scripts/docker-install-plugin.sh
```

- When Ollama is running on the Docker host at `127.0.0.1:11434`, the bundled `ollama-proxy` service exposes it to containerized PHP at `host.docker.internal:${OLLAMA_PROXY_PORT}`.
- Rebuild `gutenberg/build/` after changing `gutenberg/src/`.
- `docker compose run --rm wpcli <command>` is available for WP-CLI tasks such as `plugin list` or `post list`.

## Configuration

Open `AI Content Forge` in wp-admin.

### Default Provider

Choose it directly from the top summary bar by clicking one of the provider chips. The selected default provider is marked with a star and is used whenever the generator UI does not specify a provider override.

### Anthropic Claude

- `API Key`: Anthropic API key
- `Model`: left blank until an API key is provided, then automatically populated from the Anthropic Models API after the key is detected and validated

### OpenAI

- `API Key`: OpenAI API key
- `Model`: left blank until an API key is provided, then automatically populated from the OpenAI Models API after the key is detected and validated

### Ollama

- `Base URL`: defaults to `http://localhost:11434`
- `Access Header Name`: optional, for a protected remote Ollama endpoint
- `Access Header Value`: optional, for a protected remote Ollama endpoint
- `Model`: left blank until the Ollama server is reached, then automatically populated from the Ollama tags API

Important:

- `localhost` is resolved from the WordPress runtime, not from your browser tab.
- In Docker, `localhost` means the container.
- When this plugin runs in the bundled Docker stack and the Ollama Base URL is `http://localhost:11434`, the backend automatically retries against the Docker host bridge exposed by `ollama-proxy`.
- On managed/cloud-hosted WordPress, Ollama requires a base URL that the hosting runtime can actually reach. A desktop-only `localhost` URL will not work unless Ollama is running on the same machine as WordPress.
- If you fill in `Access Header Value` but leave `Access Header Name` blank, the plugin automatically sends the value as `Authorization`.
- On browser-executed WordPress environments such as WordPress Playground, use the Worker proxy path documented below instead of the upstream Cloudflare Access header directly.

### Ollama Remote Access Wizard

If Ollama runs on your own computer, NAS, home server, or Ubuntu/WSL machine while WordPress is hosted somewhere else, the easiest supported path is:

1. expose Ollama through a dedicated Cloudflare Tunnel hostname
2. protect that hostname with Cloudflare Access
3. switch Access service-token auth into single-header mode
4. paste the final `Base URL`, `Access Header Name`, and `Access Header Value` into this plugin

Recommended starting points:

- beginner long-form guide: [docs/ollama-cloudflare-beginner-guide.md](docs/ollama-cloudflare-beginner-guide.md)
- browser/Playground Worker proxy guide: [docs/ollama-cloudflare-worker-proxy-guide.md](docs/ollama-cloudflare-worker-proxy-guide.md)
- generic templates: [templates/ollama-cloudflare/](templates/ollama-cloudflare/)
- automated helper script: `./scripts/ollama-cloudflare-wizard.sh`
- Worker proxy deployment script: `./scripts/create-ollama-worker-proxy.sh`

#### Automated Path

The helper script can now:

- read saved defaults from `.env.example` and `.env`
- verify your local Ollama endpoint first
- install `cloudflared` and `jq` on Debian/Ubuntu when missing
- create or reuse the Cloudflare Tunnel
- merge the Ollama hostname into the existing tunnel ingress config through the Cloudflare API
- create or update the DNS record for your Ollama hostname
- install or update the local `cloudflared` service, or fall back to a manual run command
- create or reuse the Cloudflare Access application
- create or rotate the Cloudflare Access service token
- create or update the Service Auth policy for that token
- enable single-header mode for the Access app
- save the Cloudflare/Ollama defaults it used back into `.env`
- test the final protected Ollama endpoint
- print the exact raw values to paste into WordPress

Run it like this:

```bash
./scripts/ollama-cloudflare-wizard.sh
```

The wizard stores and reuses these local defaults in `.env`:

- `CLOUDFLARE_API_TOKEN`
- `CLOUDFLARE_ACCOUNT_ID`
- `CLOUDFLARE_ZONE_ID`
- `CLOUDFLARE_TUNNEL_NAME`
- `CLOUDFLARE_TUNNEL_DOMAIN`
- `OLLAMA_PUBLIC_HOSTNAME`
- `CLOUDFLARE_ACCESS_APP_NAME`
- `CLOUDFLARE_ACCESS_APP_ID`
- `CLOUDFLARE_SERVICE_TOKEN_NAME`
- `CLOUDFLARE_SERVICE_TOKEN_ID`
- `CLOUDFLARE_SERVICE_TOKEN_DURATION`
- `CLOUDFLARE_ACCESS_POLICY_ID`
- `CLOUDFLARE_ACCESS_HEADER_NAME`
- `CLOUDFLARE_ACCESS_HEADER_VALUE`
- `CF_ACCESS_CLIENT_ID`
- `CF_ACCESS_CLIENT_SECRET`
- `OLLAMA_LOCAL_URL`
- `OLLAMA_HOST_TARGET`
- `OLLAMA_ORIGIN_HOST_HEADER`

The script supports two permission modes.

Minimum permissions when you provide `ACCOUNT_ID` and `ZONE_ID` manually:

- `Cloudflare Tunnel Edit`
- `Access: Apps and Policies Edit`
- `Access: Service Tokens Edit`
- `DNS Edit`

Optional extra permission only when you want the script to auto-detect the IDs from your domain:

- `Zone Read`

Why those permissions are needed:

- `Cloudflare Tunnel Edit`: create the tunnel, update ingress config, fetch the tunnel token
- `Access: Apps and Policies Edit`: create/update the Access app and attach the Service Auth policy
- `Access: Service Tokens Edit`: create the service token used by WordPress
- `DNS Edit`: create or update `OLLAMA_PUBLIC_HOSTNAME`
- `Zone Read`: detect the correct Cloudflare zone and account automatically from your domain if you do not enter them manually

At the end, the script prints raw values like this for direct wp-admin pasting:

```text
Base URL: https://${OLLAMA_PUBLIC_HOSTNAME}
Access Header Name: ${CLOUDFLARE_ACCESS_HEADER_NAME}
Access Header Value: {"cf-access-client-id":"${CF_ACCESS_CLIENT_ID}","cf-access-client-secret":"${CF_ACCESS_CLIENT_SECRET}"}
```

In `.env`, `CLOUDFLARE_ACCESS_HEADER_VALUE` is stored in escaped form so Docker Compose can still parse the project env file.

#### Manual Path

If you do not want the script to perform the Cloudflare work for you, follow the full beginner guide in [docs/ollama-cloudflare-beginner-guide.md](docs/ollama-cloudflare-beginner-guide.md). That guide assumes you may not yet have:

- Ollama installed
- `cloudflared` installed
- a Cloudflare account
- a domain already on Cloudflare
- a tunnel
- an Access application
- any prior Cloudflare experience

The manual guide includes:

- exact Ubuntu/Debian/WSL install commands
- the order to test each step
- copy/paste-ready `curl` commands
- `cloudflared` config templates
- the exact WordPress values to paste at the end

Notes:

- This plugin currently supports one optional Ollama access header, which matches Cloudflare Access single-header mode and many simple authenticated reverse proxies.
- The plugin does not currently support Cloudflare's default two-header service-token mode directly.
- If you do not want to expose a remote Ollama endpoint at all, use OpenAI or Claude instead.

### Ollama Worker Proxy For WordPress Playground

When WordPress runs in a browser sandbox such as `playground.wordpress.net`, the direct Cloudflare Access header path is unreliable because the browser sends a CORS preflight request first. The Worker proxy path solves that by letting a Cloudflare Worker answer the browser preflight and then forward the real request to your existing protected Ollama hostname server-side.

Use this path:

1. create or confirm the upstream protected Ollama hostname with `./scripts/ollama-cloudflare-wizard.sh`
2. deploy the Worker proxy with `./scripts/create-ollama-worker-proxy.sh`
3. paste the Worker proxy values into the plugin instead of the upstream Cloudflare Access values

The Worker proxy script prints values like:

```text
Base URL: https://ollama-proxy.example.com
Access Header Name: X-Ollama-Proxy-Token
Access Header Value: YOUR_LONG_RANDOM_PROXY_TOKEN
```

The full Worker proxy setup guide is in [docs/ollama-cloudflare-worker-proxy-guide.md](docs/ollama-cloudflare-worker-proxy-guide.md).

### Generation Defaults

- These controls now live directly in the top summary bar of the wp-admin settings page.
- `Max Output Tokens`: visible answer budget; shorter content types are capped lower internally
- `Max Thinking Tokens`: reasoning budget for thinking-capable models, adapted per provider API
- `Temperature`: global creativity control

### Live Provider Status

- Anthropic Claude and OpenAI are checked automatically after the API key field becomes non-empty
- Ollama is checked automatically after the Base URL field becomes non-empty, and any change to the optional access header fields triggers a new validation attempt
- a green `Connected` status appears beside the provider heading after a successful check
- the `Model` dropdown is refreshed with the models returned by that provider API
- the selected model becomes the saved active model used for later generation after you click `Save Settings`

## User Guide

### Settings Screen

Use the settings screen to:

- store provider credentials
- choose the default provider from the top summary bar
- set baseline generation behavior from the same top summary bar
- confirm provider connectivity and choose the exact model before editing content

### Gutenberg Sidebar

Open the block editor for a post or page, then open `AI Content Forge` from the editor sidebar / more-menu entry.

Available controls:

- `Content Type`
- `AI Provider`
- `Keywords / Topic hints`
- `Tone`
- `Language`
- `Context Scope` (full post, selected blocks, custom paste, or none)
- `Structure` (Post Content only)
- `TARGET LENGTH (WORDS)` numeric input + linked slider (Post Content only)

Generation streams tokens in real time from the provider into the `Result` panel. A `Stop` button appears during streaming. After generation completes, the sidebar exposes:

- `Copy`
- `Apply to Post`

#### Advanced Overrides

The `Advanced` panel (collapsed by default) lets you override the saved provider model, prompt template, and token budgets on a per-run basis:

- `Model Override`
- `Prompt Template Override`
- `Max Output Tokens`
- `Max Thinking Tokens`
- `Temperature`

If you want to start from the saved prompt template for the selected content type, click `Load Saved Prompt`, adjust it for the current run, and generate. Leaving the override blank keeps the saved wp-admin prompt template in effect.

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
- existing content (based on Context Scope)
- post type
- requested structure (Post Content)
- target length (Post Content)

Behavior by type:

- `post_content`: aims for a structured, publication-ready WordPress HTML fragment with stricter rules around heading hierarchy, paragraphs, lists, links, tables, inline formatting, and optional advanced blocks
- `seo_title`: aims for a 50 to 60 character plain-text title
- `meta_description`: aims for a 150 to 160 character plain-text description
- `excerpt`: aims for a 40 to 55 word plain-text excerpt

The built-in `post_content` template now explicitly instructs the model to:

- avoid `<h1>` because WordPress outputs the main title separately
- prefer valid WordPress-safe HTML such as `<h2>`, `<h3>`, `<p>`, `<ul>`, `<ol>`, `<li>`, `<strong>`, `<em>`, `<code>`, and valid `<table>` markup when useful
- use descriptive anchor text for links
- include advanced structures such as embeds, images, videos, buttons, columns, accordions, footnotes, and page breaks only when the topic or prompt clearly justifies them
- avoid unsupported scripts, markdown fences, stray explanations, and invalid pseudo-HTML

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
| `target_length` | no | target word count for `post_content` |
| `structure` | no | structure hint for `post_content` (e.g. `Outline`) |
| `model` | no | provider model override for this run |
| `max_output_tokens` | no | per-run override of max output tokens |
| `max_thinking_tokens` | no | per-run override of max thinking tokens |
| `temperature` | no | per-run override of temperature (0-2) |

Successful response:

```json
{
  "success": true,
  "result": "Generated text here",
  "usage": {
    "provider": "openai",
    "model": "gpt-4o",
    "input_tokens": 123,
    "thinking_tokens": 0,
    "output_tokens": 456,
    "total_tokens": 579,
    "cost_usd": 0.001234
  }
}
```

`usage` is `null` when the provider does not report token counts.

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
| `auth_header_name` | no | optional for `ollama`; unsaved access header name currently typed in the form |
| `auth_header_value` | no | optional for `ollama`; unsaved access header value currently typed in the form |
| `current_model` | no | currently selected or previously saved model |

### `GET /providers`

Returns the provider list with:

- `id`
- `label`
- `is_configured`
- `is_default`

### `GET /provider-models`

Returns the available models for a provider (cached for ~10 minutes).

Parameters:

| Parameter | Required | Notes |
| --- | --- | --- |
| `provider` | yes | `claude`, `openai`, or `ollama` |
| `refresh` | no | `true` to bypass the cache |

Successful response:

```json
{
  "success": true,
  "models": [
    { "id": "model-id", "label": "Model Name" }
  ]
}
```

## Packaging Releases

Use the release script from the plugin root:

```bash
./scripts/build-release.sh
```

The script:

- requires the Gutenberg build to exist first
- stages the plugin under the correct runtime folder name: `ai-content-forge`
- creates a clean versioned archive such as `ai-content-forge-v2.10.0.zip`
- the plugin zip remains suitable for direct `wp-admin` upload and activation
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
scripts/build-release.sh            Release packaging script
scripts/docker-setup.sh             Optional local Docker environment setup
scripts/docker-install-plugin.sh    Optional local Docker plugin reinstall helper
scripts/ollama-cloudflare-wizard.sh Remote Ollama + Cloudflare Access automation
scripts/create-ollama-worker-proxy.sh Browser-safe Worker proxy deployment helper
workers/ollama-proxy/src/index.js   Cloudflare Worker proxy source
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

### `v2.10.0`

- updated the Worker proxy deployment/help flow and the wp-admin Ollama setup instructions so the browser-safe Worker proxy path is explicit for WordPress Playground and similar runtimes
- fixed Gutenberg streaming on WordPress Playground by resolving the stream endpoint from the localized REST base URL instead of a hardcoded root-relative `/wp-json/...` path
- replaced generic plugin/provider UI glyphs with the bundled PNG assets from `images/` across the plugin frontend

### `v2.9.1`

- fixed the Cloudflare Worker proxy for WordPress Playground and other browser-based WordPress runtimes by stripping browser-origin headers before forwarding requests upstream to the Access-protected Ollama hostname

### `v2.9.0`

- added `scripts/create-ollama-worker-proxy.sh` to deploy a Cloudflare Worker proxy route, set Worker secrets, create the required DNS record, and print the exact plugin values for browser-based WordPress runtimes
- added `workers/ollama-proxy/src/index.js` as the committed Worker source that handles CORS preflight and forwards authenticated Ollama requests to the protected upstream hostname
- added `docs/ollama-cloudflare-worker-proxy-guide.md` and updated the README to document the Worker proxy path for WordPress Playground and similar browser-executed environments

### `v2.8.0`

- fixed the wp-admin secret reveal buttons so the Claude, OpenAI, and Ollama `👁` toggles now show and hide the adjacent field value correctly
- updated the Ollama Cloudflare wizard to prompt for the full public hostname, merge tunnel ingress changes safely, and manage the Access app, policy, and service token through the API
- changed the wizard to persist `CLOUDFLARE_ACCESS_HEADER_VALUE` in an escaped `.env` form that Docker Compose can parse, while still printing the raw JSON value for direct wp-admin pasting
- updated the README and bundled Ollama/Cloudflare guidance to reflect the current env keys, permission model, and copy/paste flow

### `v2.6.9`

- updated `.env.example` to include the full local env key set used in this project with blank defaults instead of hardcoded placeholder values
- removed insecure Docker Compose fallback credentials and switched required runtime values to `.env`-driven configuration
- hardened Docker setup defaults so local MariaDB credentials and names are generated securely and stored in `.env`, then reused
- removed hardcoded placeholder defaults from the Cloudflare wizard prompts and templates so values are loaded from `.env` or entered interactively

### `v2.6.8`

- centralized local `.env` parsing and updates in a shared shell helper so the Docker and Cloudflare scripts now reuse the same template and write path
- updated `docker-setup.sh` to seed `.env` from `.env.example`, preserve existing values, and generate secure local database/admin secrets instead of relying on placeholder credentials
- updated the Cloudflare wizard to read defaults from `.env`, persist the Cloudflare values it uses back into `.env`, and document the local-secret workflow more clearly

### `v2.6.7`

- updated the Cloudflare automation script so it can accept `ACCOUNT_ID` and `ZONE_ID` manually, which removes the need for `Zone Read` when you want the lowest-permission API token
- updated the beginner docs, admin onboarding, and permission guidance to make `Zone Read` optional instead of mandatory

### `v2.6.6`

- replaced the basic Ollama Cloudflare helper with a fully interactive automation script that can create or reuse the tunnel, DNS record, Access app, service token, Service Auth policy, and single-header mode, then print the exact WordPress values to paste
- expanded the Ollama onboarding into a true beginner-first flow across wp-admin, the README, and a dedicated long-form guide in `docs/ollama-cloudflare-beginner-guide.md`
- added reusable Cloudflare/Ollama templates to the release package and updated release packaging to include the new docs and templates

### `v2.6.5`

- added optional Ollama access header support so managed/cloud-hosted WordPress sites can talk to secured remote Ollama endpoints
- documented a non-technical Cloudflare Tunnel + Cloudflare Access setup flow in both wp-admin and the README
- updated the Ollama provider label and settings copy to reflect local and remote/self-hosted Ollama deployments

### `v2.6.4`

- documented direct `wp-admin` zip install/update/activation as a release-blocking compatibility requirement
- documented self-hosted versus managed/cloud-hosted behavior for OpenAI, Claude, and Ollama integrations, including Ollama reachability limits
- clarified that Docker and `cloudflared` are optional operational tools, not plugin requirements
- hardened the helper scripts for repeatable local use: Docker helpers now require Compose v2, avoid dependency re-creation during WP-CLI runs, and `docker-setup.sh` now reconciles site/admin settings for already-installed local environments
- `scripts/build-release.sh` now checks for `zip` and validates key files inside the generated release archive when `unzip` is available

### `v2.6.3`

- fixed the Gutenberg `Stop` flow for Ollama so cancel requests now carry a per-run generation ID to the backend instead of relying only on the browser stream abort
- Ollama streaming now checks a shared cancel flag during transfer progress and chunk writes, which stops long-running local generations even before the first visible token arrives
- the stop action now targets the exact active run, waits for the backend cancel request, and prevents an immediate re-run from getting stuck behind the canceled generation

### `v2.6.2`

- fixed Run Usage and Post Usage Totals panels not populating for Ollama and OpenAI when the browser falls back from SSE streaming to the non-streaming `/generate` endpoint
- the non-streaming `/generate` endpoint now returns `usage` alongside `result` by internally using `stream_generate` to capture provider token counts and cost
- the Gutenberg sidebar fallback path now extracts and displays usage data from the non-streaming response
- Thinking Tokens row is hidden when the value is zero or unavailable (most Ollama and standard OpenAI models)
- Cost (USD) row is replaced with a "Local model — no API cost" label for Ollama instead of showing a misleading `$0.000000`
- Post Usage Totals adapted with the same provider-aware display

### `v2.6.1`

- fixed Gutenberg sidebar regression: restored Context Scope, Structure, Target Length, and Advanced override controls that were lost during the v2.6.0 build toolchain upgrade
- updated Gutenberg source to include all v2.5.0 features so future rebuilds retain full functionality

### `v2.6.0`

- upgraded `@wordpress/scripts` from `^27.0.0` to `^31.8.0` (webpack 5.105, new JSX transform)
- resolved all 14 Dependabot security alerts (serialize-javascript RCE, minimatch ReDoS, tar-fs path traversal, ws DoS, webpack-dev-server source theft, cookie OOB, cross-spawn ReDoS)
- added npm overrides for `serialize-javascript`, `minimatch`, and `webpack-dev-server` to pin patched transitive dependency versions
- reduced Gutenberg build bundle size (~10 KB vs ~15 KB) via improved tree-shaking

### `v2.5.0`

- added Context Scope control in the Gutenberg sidebar (full post, selected blocks, custom paste, or none)
- added Post Content `Structure` and `Target Length` controls to shape output format and size
- added Advanced per-run overrides for model, max output tokens, max thinking tokens, and temperature
- added `GET /provider-models` REST endpoint so the sidebar can populate provider model dropdowns
- updated prompt templates to accept structure + target length placeholders for Post Content

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
