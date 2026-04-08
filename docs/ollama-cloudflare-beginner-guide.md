# Ollama + Cloudflare Beginner Guide

This guide is for the most common difficult setup:

- Ollama runs on your own computer, home server, NAS, or Ubuntu/WSL machine.
- Your WordPress site is hosted somewhere else.
- You want AI Content Forge to use your own Ollama server safely.
- You want instructions that assume you may be starting from zero.

If your WordPress site and Ollama are already on the same machine, stop here and use:

```text
Base URL: ${OLLAMA_LOCAL_URL}
Access Header Name: leave blank
Access Header Value: leave blank
```

You only need the Cloudflare steps below when WordPress cannot already reach Ollama directly.

If your WordPress runtime is browser-based, such as WordPress Playground, do not stop at the direct upstream hostname path. After this guide, deploy the browser-safe Worker proxy documented in [docs/ollama-cloudflare-worker-proxy-guide.md](ollama-cloudflare-worker-proxy-guide.md).

## Fastest Path

If you want one script to perform the Cloudflare work for you, run:

```bash
./scripts/ollama-cloudflare-wizard.sh
```

That script can:

- reuse defaults already saved in `.env`
- create or reuse the tunnel
- create or update the DNS record
- create or reuse the Access app
- create or rotate the service token
- create or update the Service Auth policy
- enable single-header mode
- save the Cloudflare and Ollama values it used back into `.env`
- test the public protected Ollama endpoint
- print the final raw WordPress values for direct wp-admin pasting

For full automation, the script supports two permission modes.

Minimum permissions when you provide `ACCOUNT_ID` and `ZONE_ID` manually:

- `Cloudflare Tunnel Edit`
- `Access: Apps and Policies Edit`
- `Access: Service Tokens Edit`
- `DNS Edit`

Optional extra permission only when you want the script to auto-detect the IDs from your domain:

- `Zone Read`

The script treats `.env` as a local-only convenience file:

- `.env.example` is the committed template
- `.env` is gitignored and stays on your machine
- saved defaults such as your Cloudflare token, account ID, zone ID, tunnel name, and Ollama hostname are automatically reused the next time you run the wizard
- `CLOUDFLARE_ACCESS_HEADER_VALUE` is stored in escaped form so Docker Compose can still parse `.env`
- the wizard prints the raw `Access Header Value` separately at the end; copy that raw value into WordPress

## Quick Links

- [Create a Cloudflare account](https://dash.cloudflare.com/sign-up)
- [Add your domain to Cloudflare](https://developers.cloudflare.com/fundamentals/manage-domains/add-site/)
- [Open Cloudflare Zero Trust](https://one.dash.cloudflare.com/)
- [Install cloudflared packages](https://pkg.cloudflare.com/)
- [Tunnel configuration file reference](https://developers.cloudflare.com/tunnel/advanced/local-management/configuration-file/)
- [Create a self-hosted Access application](https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-apps/)
- [Cloudflare service tokens and single-header mode](https://developers.cloudflare.com/cloudflare-one/access-controls/service-credentials/service-tokens/)
- [Download/install Ollama for Linux](https://ollama.com/download/linux)

## What You Are Building

At the end of this guide, you will have:

1. Ollama running locally on your machine.
2. A Cloudflare Tunnel that publishes one hostname such as `${OLLAMA_PUBLIC_HOSTNAME}`.
3. A Cloudflare Access application protecting that hostname.
4. One header name and one header value that AI Content Forge can send to Cloudflare Access.
5. A working WordPress configuration:

```text
Base URL: https://${OLLAMA_PUBLIC_HOSTNAME}
Access Header Name: ${CLOUDFLARE_ACCESS_HEADER_NAME}
Access Header Value: {"cf-access-client-id":"${CF_ACCESS_CLIENT_ID}","cf-access-client-secret":"${CF_ACCESS_CLIENT_SECRET}"}
```

## Before You Start

Write down these values before you begin:

- your main domain, for example `${CLOUDFLARE_TUNNEL_DOMAIN}`
- your Ollama hostname, for example `${OLLAMA_PUBLIC_HOSTNAME}`
- your tunnel name, for example `${CLOUDFLARE_TUNNEL_NAME}`
- one Ollama model you plan to use first, for example `llama3.2:3b`

Keep these placeholders in mind:

- `YOUR_DOMAIN` = your main domain, such as `${CLOUDFLARE_TUNNEL_DOMAIN}`
- `YOUR_OLLAMA_HOSTNAME` = the public hostname for Ollama, such as `${OLLAMA_PUBLIC_HOSTNAME}`
- `YOUR_TUNNEL_NAME` = your tunnel name, such as `${CLOUDFLARE_TUNNEL_NAME}`
- `YOUR_TUNNEL_ID` = the tunnel ID returned by `cloudflared tunnel create`

## Step 1: Create a Cloudflare Account

If you already have a Cloudflare account, skip to Step 2.

1. Open [Create a Cloudflare account](https://dash.cloudflare.com/sign-up).
2. Create your account.
3. Log in to the Cloudflare dashboard.

## Step 2: Put Your Domain on Cloudflare

If your domain is already active on Cloudflare, skip to Step 3.

1. Open [Add your domain to Cloudflare](https://developers.cloudflare.com/fundamentals/manage-domains/add-site/).
2. Add your domain.
3. Cloudflare will scan your existing DNS records.
4. Cloudflare will show you two nameservers.
5. Open the website where you bought your domain name.
6. Replace your old nameservers with the two nameservers Cloudflare gave you.
7. Return to Cloudflare and wait until your domain status becomes `Active`.

Do not continue until the domain is active.

## Step 3: Install Ollama Locally

This guide assumes Ubuntu, Debian, or Ubuntu running inside WSL.

Open a terminal on the machine where you want Ollama to run and execute:

```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama pull llama3.2:3b
curl http://localhost:11434/api/tags
```

What success looks like:

- `ollama pull ...` downloads your model
- `curl http://localhost:11434/api/tags` prints JSON

If the local `curl` test fails, stop here and fix Ollama before you touch Cloudflare.

### If You Are Using WSL

The simplest setup is:

- run both `ollama` and `cloudflared` inside the same Ubuntu/WSL environment
- do not split one on Windows and the other in WSL unless you already understand the networking

If `systemctl` is not available in WSL, keep Ollama running manually in its own terminal:

```bash
ollama serve
```

Leave that terminal open.

## Step 4: Install cloudflared

Install `cloudflared` on the same machine that can already reach `${OLLAMA_LOCAL_URL}`.

For Ubuntu or Debian:

```bash
sudo mkdir -p --mode=0755 /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' | sudo tee /etc/apt/sources.list.d/cloudflared.list
sudo apt-get update
sudo apt-get install -y cloudflared jq
cloudflared --version
```

Why `jq` is included:

- later in this guide, `jq` edits the Cloudflare Access application JSON so the service token can be sent as one header

## Step 5: Log In and Create the Tunnel

Run these commands:

```bash
cloudflared login
cloudflared tunnel create YOUR_TUNNEL_NAME
sudo cloudflared tunnel route dns YOUR_TUNNEL_NAME YOUR_OLLAMA_HOSTNAME
```

Example:

```bash
cloudflared login
cloudflared tunnel create ${CLOUDFLARE_TUNNEL_NAME}
sudo cloudflared tunnel route dns ${CLOUDFLARE_TUNNEL_NAME} ${OLLAMA_PUBLIC_HOSTNAME}
```

What these commands do:

- `cloudflared login` opens a browser so you can choose your Cloudflare account and domain
- `cloudflared tunnel create ...` creates the tunnel and prints the tunnel ID
- `cloudflared tunnel route dns ...` creates the public DNS hostname that points to the tunnel

After `cloudflared tunnel create`, write down the tunnel ID.

## Step 6: Create `/etc/cloudflared/config.yml`

Create or edit `/etc/cloudflared/config.yml` so your Ollama hostname goes to the local Ollama service.

Template:

```yaml
tunnel: $CLOUDFLARE_TUNNEL_UUID
credentials-file: /etc/cloudflared/$CLOUDFLARE_TUNNEL_UUID.json

ingress:
  - hostname: $OLLAMA_PUBLIC_HOSTNAME
    service: $OLLAMA_LOCAL_URL
    originRequest:
      httpHostHeader: $OLLAMA_ORIGIN_HOST_HEADER
  - service: http_status:404
```

Example:

```yaml
tunnel: $CLOUDFLARE_TUNNEL_UUID
credentials-file: /etc/cloudflared/$CLOUDFLARE_TUNNEL_UUID.json

ingress:
  - hostname: ${OLLAMA_PUBLIC_HOSTNAME}
    service: $OLLAMA_LOCAL_URL
    originRequest:
      httpHostHeader: $OLLAMA_ORIGIN_HOST_HEADER
  - service: http_status:404
```

Important:

- keep the final `http_status:404` rule at the bottom
- use a hostname dedicated to Ollama only
- if the upstream Ollama server rejects requests by public hostname, set `OLLAMA_ORIGIN_HOST_HEADER` to the local upstream host such as `127.0.0.1:11434`
- do not reuse your main website hostname

## Step 7: Start the Tunnel

If your system uses systemd:

```bash
sudo systemctl restart cloudflared
sudo systemctl status cloudflared --no-pager
```

If you want to test manually first:

```bash
cloudflared tunnel --config /etc/cloudflared/config.yml run
```

If you are testing manually, keep that terminal open.

## Step 8: Create a Cloudflare Access App

Now protect the hostname before using it in WordPress.

1. Open [Cloudflare Zero Trust](https://one.dash.cloudflare.com/).
2. Go to `Access`.
3. Go to `Applications`.
4. Click `Add an application`.
5. Choose `Self-hosted`.
6. Name it using your configured variable, for example `${CLOUDFLARE_ACCESS_APP_NAME}`.
7. Set the domain to your Ollama hostname, for example `${OLLAMA_PUBLIC_HOSTNAME}`.
8. Save the application.

## Step 9: Create a Service Token and Policy

Still in Cloudflare Zero Trust:

1. Create a service token.
2. Save the `Client ID` and `Client Secret` immediately.
3. Create or edit an Access policy for the Ollama app.
4. Set the action to `Service Auth`.
5. Attach your service token to that policy.
6. Save the policy.

At this point Cloudflare is protecting the hostname, but service tokens still use two headers by default.

## Step 10: Convert Cloudflare Access to One Header

AI Content Forge accepts one optional header name and one optional header value for Ollama.

Cloudflare service tokens normally use two headers:

- `CF-Access-Client-Id`
- `CF-Access-Client-Secret`

So you must change the Access app to read service tokens from one header.

### 10A. Create a Cloudflare API Token

Create a Cloudflare API token that can read and edit Access applications for your account.

Store the token in your shell:

```bash
export CLOUDFLARE_API_TOKEN='PASTE_YOUR_API_TOKEN_HERE'
export ACCOUNT_ID='PASTE_YOUR_ACCOUNT_ID_HERE'
export APP_ID='PASTE_YOUR_ACCESS_APP_ID_HERE'
```

### 10B. Download the Current Access App JSON

```bash
curl "https://api.cloudflare.com/client/v4/accounts/$ACCOUNT_ID/access/apps/$APP_ID" \
  --request GET \
  --header "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  > access-app-response.json
```

### 10C. Add the Single-Header Setting

```bash
jq --arg header_name "$CLOUDFLARE_ACCESS_HEADER_NAME" '.result | .read_service_tokens_from_header = $header_name' \
  access-app-response.json \
  > access-app-update.json
```

### 10D. Send the Updated App JSON Back to Cloudflare

```bash
curl "https://api.cloudflare.com/client/v4/accounts/$ACCOUNT_ID/access/apps/$APP_ID" \
  --request PUT \
  --header "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  --header "Content-Type: application/json" \
  --data @access-app-update.json
```

After this, your one header will be:

```text
${CLOUDFLARE_ACCESS_HEADER_NAME}: {"cf-access-client-id":"${CF_ACCESS_CLIENT_ID}","cf-access-client-secret":"${CF_ACCESS_CLIENT_SECRET}"}
```

## Step 11: Test the Protected Hostname Before WordPress

Run this test from any machine that can reach the public hostname:

```bash
curl \
  -H "${CLOUDFLARE_ACCESS_HEADER_NAME}: {\"cf-access-client-id\":\"${CF_ACCESS_CLIENT_ID}\",\"cf-access-client-secret\":\"${CF_ACCESS_CLIENT_SECRET}\"}" \
  https://${OLLAMA_PUBLIC_HOSTNAME}/api/tags
```

Example:

```bash
curl \
  -H "${CLOUDFLARE_ACCESS_HEADER_NAME}: {\"cf-access-client-id\":\"${CF_ACCESS_CLIENT_ID}\",\"cf-access-client-secret\":\"${CF_ACCESS_CLIENT_SECRET}\"}" \
  https://${OLLAMA_PUBLIC_HOSTNAME}/api/tags
```

What success looks like:

- the command prints JSON

If it does not print JSON, stay here and fix the tunnel or Access configuration before touching WordPress.

## Step 12: Paste the Values into WordPress

Open `AI Content Forge` in wp-admin and paste:

```text
Base URL: https://${OLLAMA_PUBLIC_HOSTNAME}
Access Header Name: ${CLOUDFLARE_ACCESS_HEADER_NAME}
Access Header Value: {"cf-access-client-id":"${CF_ACCESS_CLIENT_ID}","cf-access-client-secret":"${CF_ACCESS_CLIENT_SECRET}"}
```

Then:

1. wait for the status to turn `Connected`
2. open the `Model` dropdown
3. choose your Ollama model
4. click `Save Settings`

## Step 13: Test Generation

Create or edit a post in Gutenberg and test one short generation first.

If the status is green in wp-admin but generation fails:

1. re-run the public `curl` test
2. confirm the selected model exists on the Ollama server
3. confirm the WordPress host can make outbound HTTPS requests to your Ollama hostname

## Step 14: Recommended Troubleshooting Order

Always test in this order:

1. Local Ollama:

```bash
curl http://localhost:11434/api/tags
```

2. Public protected hostname:

```bash
curl \
  -H "${CLOUDFLARE_ACCESS_HEADER_NAME}: {\"cf-access-client-id\":\"${CF_ACCESS_CLIENT_ID}\",\"cf-access-client-secret\":\"${CF_ACCESS_CLIENT_SECRET}\"}" \
  https://${OLLAMA_PUBLIC_HOSTNAME}/api/tags
```

3. WordPress plugin connection check
4. Gutenberg generation test

If step 1 fails, fix Ollama.

If step 1 works but step 2 fails, fix Cloudflare Tunnel or Cloudflare Access.

If step 1 and step 2 work but WordPress still fails, fix the values pasted into wp-admin or the WordPress host's outbound network access.

## Helper Files in This Repository

This repository also includes:

- `scripts/ollama-cloudflare-wizard.sh`
- `templates/ollama-cloudflare/cloudflared-config.example.yml`
- `templates/ollama-cloudflare/wordpress-ollama-values.example.txt`
- `templates/ollama-cloudflare/enable-access-single-header.example.sh`

The helper script generates personalized copies of those files plus local test commands and a next-steps checklist.
