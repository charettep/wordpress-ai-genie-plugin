# Ollama Worker Proxy Guide

Use this path when the WordPress runtime is browser-based or otherwise subject to browser CORS rules, such as:

- `playground.wordpress.net`
- browser-embedded WordPress demos
- any setup where the plugin HTTP requests are executed through browser `fetch` instead of a normal PHP server

In those environments, sending the Cloudflare Access `Authorization` header directly to the protected Ollama hostname often fails because the browser triggers a CORS preflight request first. The Worker proxy avoids that problem.

## What This Builds

The final request path becomes:

```text
WordPress / Playground -> Worker proxy -> Cloudflare Access protected Ollama hostname -> Tunnel -> local Ollama
```

The Worker:

- answers browser `OPTIONS` preflight requests
- adds CORS headers for allowed origins such as `https://playground.wordpress.net`
- stores the upstream Cloudflare Access client ID and secret as Worker secrets
- forwards Ollama requests to your existing protected hostname
- requires one separate proxy token from WordPress

The plugin no longer needs the upstream Cloudflare Access JSON header directly. It only needs the Worker proxy token.

## Prerequisite

Before this guide, you must already have a working protected upstream Ollama hostname, for example:

```text
https://ollama.example.com
```

The easiest way to create that upstream hostname is:

```bash
./scripts/ollama-cloudflare-wizard.sh
```

Confirm that the upstream hostname already works before creating the Worker proxy.

## API Token Permissions

When you provide `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_ZONE_ID` manually, the Worker proxy deployment script needs:

Account permissions:

- `Workers Scripts Edit`

Zone permissions:

- `Workers Routes Edit`
- `DNS Edit`

Optional extra permission only when you want the script to auto-detect the account/zone from the domain:

- `Zone Read`

If your existing Ollama/Cloudflare wizard token only has Tunnel, Access, and DNS scopes, it will not be enough for Worker deployment. In that case, create a new token for the Worker script with the Worker-specific scopes above.

## Fastest Path

Run:

```bash
./scripts/create-ollama-worker-proxy.sh
```

The script can:

- read saved defaults from `.env.example` and `.env`
- verify your existing upstream Ollama hostname first
- create or reuse a Worker route hostname such as `ollama-proxy.example.com`
- create a proxied placeholder DNS record when needed
- deploy the Worker through `npx wrangler@latest`
- store upstream credentials and the proxy token as Worker secrets
- test browser preflight and authenticated `GET /api/tags`
- print the exact values to paste into WordPress

## Inputs The Script Prompts For

- `CLOUDFLARE_API_TOKEN`
- `CLOUDFLARE_ACCOUNT_ID`
- `CLOUDFLARE_ZONE_ID`
- `CLOUDFLARE_TUNNEL_DOMAIN`
- `OLLAMA_PUBLIC_HOSTNAME`
- `CF_ACCESS_CLIENT_ID`
- `CF_ACCESS_CLIENT_SECRET`
- `OLLAMA_WORKER_PROXY_NAME`
- `OLLAMA_WORKER_PROXY_HOSTNAME`
- `OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS`
- `OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME`
- `OLLAMA_WORKER_PROXY_AUTH_VALUE`

Recommended defaults:

- `OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS=https://playground.wordpress.net`
- `OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME=X-Ollama-Proxy-Token`
- `OLLAMA_WORKER_PROXY_HOSTNAME=ollama-proxy.<your-domain>`

## What To Paste Into AI Content Forge

At the end, the script prints values like:

```text
Base URL: https://ollama-proxy.example.com
Access Header Name: X-Ollama-Proxy-Token
Access Header Value: YOUR_LONG_RANDOM_PROXY_TOKEN
```

Paste those directly into the plugin settings.

Do not paste the upstream Cloudflare Access JSON header into the plugin when using the Worker proxy path.

## What Gets Saved In `.env`

The deployment script saves and reuses these local defaults:

- `OLLAMA_WORKER_PROXY_NAME`
- `OLLAMA_WORKER_PROXY_HOSTNAME`
- `OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS`
- `OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME`
- `OLLAMA_WORKER_PROXY_AUTH_VALUE`

It also reuses your existing upstream values:

- `OLLAMA_PUBLIC_HOSTNAME`
- `CF_ACCESS_CLIENT_ID`
- `CF_ACCESS_CLIENT_SECRET`

## Source Files

Worker source:

```text
workers/ollama-proxy/src/index.js
```

Deployment helper:

```text
scripts/create-ollama-worker-proxy.sh
```

## Manual Verification

After the script runs, these checks should work.

Preflight:

```bash
curl -i -X OPTIONS \
  -H 'Origin: https://playground.wordpress.net' \
  -H 'Access-Control-Request-Method: GET' \
  -H 'Access-Control-Request-Headers: X-Ollama-Proxy-Token' \
  https://ollama-proxy.example.com/api/tags
```

Authenticated request:

```bash
curl \
  -H 'X-Ollama-Proxy-Token: YOUR_LONG_RANDOM_PROXY_TOKEN' \
  https://ollama-proxy.example.com/api/tags
```

Both should succeed before you test WordPress Playground.
