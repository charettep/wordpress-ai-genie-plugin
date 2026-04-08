#!/usr/bin/env bash
set -euo pipefail

CF_API_BASE="https://api.cloudflare.com/client/v4"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${ROOT_DIR}/.env"
ENV_TEMPLATE="${ROOT_DIR}/.env.example"
ENV_LIB="${ROOT_DIR}/scripts/lib/env.sh"
WORKER_SOURCE="${ROOT_DIR}/workers/ollama-proxy/src/index.js"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
OUTPUT_DIR_DEFAULT="${PWD}/ollama-worker-proxy-setup-${TIMESTAMP}"
WRANGLER_CMD=(npx --yes wrangler@latest)
WORKER_ENV_KEYS=(
    CLOUDFLARE_ACCOUNT_ID
    CLOUDFLARE_ZONE_ID
    CLOUDFLARE_API_TOKEN
    CLOUDFLARE_TUNNEL_DOMAIN
    OLLAMA_PUBLIC_HOSTNAME
    CF_ACCESS_CLIENT_ID
    CF_ACCESS_CLIENT_SECRET
    CLOUDFLARE_ACCESS_HEADER_NAME
    OLLAMA_WORKER_PROXY_NAME
    OLLAMA_WORKER_PROXY_HOSTNAME
    OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS
    OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME
    OLLAMA_WORKER_PROXY_AUTH_VALUE
)

if [[ ! -f "${ENV_LIB}" ]]; then
    echo "Missing env helper library: ${ENV_LIB}" >&2
    exit 1
fi

if [[ ! -f "${WORKER_SOURCE}" ]]; then
    echo "Missing worker source file: ${WORKER_SOURCE}" >&2
    exit 1
fi

# shellcheck source=./lib/env.sh
source "${ENV_LIB}"

print_permissions() {
    cat <<'EOF'
Cloudflare API token permissions for the Worker proxy script:

Minimum permissions when you provide ACCOUNT_ID and ZONE_ID manually:
Account permissions:
  - Workers Scripts Edit

Zone permissions:
  - Workers Routes Edit
  - DNS Edit

Optional extra permission only when you want the script to auto-detect the IDs from your domain:
  - Zone Read

Why these are needed:
  - Workers Scripts Edit: deploy or update the Worker code and set Worker secrets
  - Workers Routes Edit: attach the Worker to the public hostname route
  - DNS Edit: create the proxied placeholder DNS record required for the Worker hostname
  - Zone Read: detect the zone/account automatically when you do not enter them manually
EOF
}

print_help() {
    cat <<'EOF'
Usage:
  ./scripts/create-ollama-worker-proxy.sh
  ./scripts/create-ollama-worker-proxy.sh --help
  ./scripts/create-ollama-worker-proxy.sh --permissions

What it does:
  - reads saved defaults from .env.example and .env when available
  - prompts for Cloudflare and upstream Ollama values
  - creates or reuses a Worker route hostname such as ollama-proxy.example.com
  - creates a proxied placeholder DNS record for the Worker hostname when needed
  - writes a temporary wrangler.jsonc deployment config
  - deploys the Worker via npx wrangler
  - stores the upstream Cloudflare Access service-token credentials as Worker secrets
  - stores one separate proxy token as a Worker secret for WordPress or Playground to send
  - tests the final Worker proxy endpoint with CORS preflight + authenticated GET
  - prints the exact Base URL, Header Name, and Header Value to paste into AI Content Forge

Prerequisite:
  - Your upstream Ollama hostname must already work through Cloudflare Access.
    The easiest way to create that upstream path is ./scripts/ollama-cloudflare-wizard.sh
EOF
}

print_heading() {
    printf '\n%s\n' "$1"
    printf '%*s\n' "${#1}" '' | tr ' ' '='
}

prompt_with_default() {
    local var_name="$1"
    local label="$2"
    local default_value="$3"
    local input=""

    read -r -p "${label} [${default_value}]: " input
    printf -v "${var_name}" '%s' "${input:-${default_value}}"
}

prompt_secret_with_default() {
    local var_name="$1"
    local label="$2"
    local current_default="$3"
    local input=""

    if [[ -n "${current_default}" ]]; then
        read -r -s -p "${label} [saved in .env, press Enter to keep]: " input
    else
        read -r -s -p "${label}: " input
    fi

    printf '\n'
    printf -v "${var_name}" '%s' "${input:-${current_default}}"
}

default_if_empty() {
    local var_name="$1"
    local default_value="$2"

    if [[ -z "${!var_name:-}" ]]; then
        printf -v "${var_name}" '%s' "${default_value}"
    fi
}

first_allowed_origin() {
    local raw_value="$1"

    printf '%s' "${raw_value}" | tr ',' '\n' | sed -n '/\S/ { s/^[[:space:]]*//; s/[[:space:]]*$//; p; q; }'
}

slugify_value() {
    local value="$1"

    printf '%s' "${value}" \
        | tr '[:upper:]' '[:lower:]' \
        | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//; s/-+/-/g'
}

generate_secure_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 48 | tr -d '\n' | tr '/+' 'AZ' | cut -c1-48
    else
        head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-48
    fi
}

require_non_empty() {
    local var_name="$1"
    local label="$2"

    if [[ -z "${!var_name:-}" ]]; then
        echo "${label} is required." >&2
        exit 1
    fi
}

require_command() {
    local command_name="$1"

    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Missing required command: ${command_name}" >&2
        exit 1
    fi
}

save_json() {
    local target_file="$1"
    local json_payload="$2"

    printf '%s\n' "${json_payload}" | jq '.' > "${target_file}"
}

cf_api() {
    local method="$1"
    local path="$2"
    local data="${3:-}"
    local response=""

    if [[ -n "${data}" ]]; then
        response="$(curl -fsS -X "${method}" \
            -H "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
            -H "Content-Type: application/json" \
            --data "${data}" \
            "${CF_API_BASE}${path}")"
    else
        response="$(curl -fsS -X "${method}" \
            -H "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
            "${CF_API_BASE}${path}")"
    fi

    if [[ "$(jq -r '.success // false' <<< "${response}")" != "true" ]]; then
        echo "Cloudflare API call failed: ${method} ${path}" >&2
        printf '%s\n' "${response}" >&2
        exit 1
    fi

    printf '%s' "${response}"
}

load_defaults() {
    env_ensure_file "${ENV_FILE}" "${ENV_TEMPLATE}"
    env_load_file "${ENV_TEMPLATE}" "${WORKER_ENV_KEYS[@]}"
    env_load_file "${ENV_FILE}" "${WORKER_ENV_KEYS[@]}"

    default_if_empty "CLOUDFLARE_ACCESS_HEADER_NAME" "Authorization"
    default_if_empty "OLLAMA_WORKER_PROXY_NAME" "acf-ollama-proxy"
    default_if_empty "OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS" "https://playground.wordpress.net"
    default_if_empty "OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME" "X-Ollama-Proxy-Token"
    default_if_empty "OLLAMA_WORKER_PROXY_AUTH_VALUE" "$(generate_secure_secret)"

    if [[ -z "${OLLAMA_WORKER_PROXY_HOSTNAME:-}" && -n "${CLOUDFLARE_TUNNEL_DOMAIN:-}" ]]; then
        OLLAMA_WORKER_PROXY_HOSTNAME="ollama-proxy.${CLOUDFLARE_TUNNEL_DOMAIN}"
    fi
}

persist_env() {
    env_ensure_file "${ENV_FILE}" "${ENV_TEMPLATE}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ACCOUNT_ID" "${CLOUDFLARE_ACCOUNT_ID:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ZONE_ID" "${CLOUDFLARE_ZONE_ID:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_API_TOKEN" "${CLOUDFLARE_API_TOKEN:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_TUNNEL_DOMAIN" "${CLOUDFLARE_TUNNEL_DOMAIN:-}"
    env_set "${ENV_FILE}" "OLLAMA_PUBLIC_HOSTNAME" "${OLLAMA_PUBLIC_HOSTNAME:-}"
    env_set "${ENV_FILE}" "CF_ACCESS_CLIENT_ID" "${CF_ACCESS_CLIENT_ID:-}"
    env_set "${ENV_FILE}" "CF_ACCESS_CLIENT_SECRET" "${CF_ACCESS_CLIENT_SECRET:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ACCESS_HEADER_NAME" "${CLOUDFLARE_ACCESS_HEADER_NAME:-}"
    env_set "${ENV_FILE}" "OLLAMA_WORKER_PROXY_NAME" "${OLLAMA_WORKER_PROXY_NAME:-}"
    env_set "${ENV_FILE}" "OLLAMA_WORKER_PROXY_HOSTNAME" "${OLLAMA_WORKER_PROXY_HOSTNAME:-}"
    env_set "${ENV_FILE}" "OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS" "${OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS:-}"
    env_set "${ENV_FILE}" "OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME" "${OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME:-}"
    env_set "${ENV_FILE}" "OLLAMA_WORKER_PROXY_AUTH_VALUE" "${OLLAMA_WORKER_PROXY_AUTH_VALUE:-}"
}

resolve_zone_name() {
    local zone_response=""

    zone_response="$(cf_api "GET" "/zones/${CLOUDFLARE_ZONE_ID}")"
    save_json "${OUTPUT_DIR}/zone.json" "${zone_response}"
    CLOUDFLARE_TUNNEL_DOMAIN="$(jq -r '.result.name' <<< "${zone_response}")"
}

discover_zone_and_account() {
    local zone_lookup=""

    zone_lookup="$(cf_api "GET" "/zones?name=${CLOUDFLARE_TUNNEL_DOMAIN}")"
    save_json "${OUTPUT_DIR}/zone-lookup.json" "${zone_lookup}"

    CLOUDFLARE_ZONE_ID="$(jq -r '.result[0].id // empty' <<< "${zone_lookup}")"
    CLOUDFLARE_ACCOUNT_ID="$(jq -r '.result[0].account.id // empty' <<< "${zone_lookup}")"
}

ensure_hostname_matches_zone() {
    if [[ "${OLLAMA_PUBLIC_HOSTNAME}" != *".${CLOUDFLARE_TUNNEL_DOMAIN}" && "${OLLAMA_PUBLIC_HOSTNAME}" != "${CLOUDFLARE_TUNNEL_DOMAIN}" ]]; then
        echo "Upstream Ollama hostname ${OLLAMA_PUBLIC_HOSTNAME} is not inside zone ${CLOUDFLARE_TUNNEL_DOMAIN}." >&2
        exit 1
    fi

    if [[ "${OLLAMA_WORKER_PROXY_HOSTNAME}" != *".${CLOUDFLARE_TUNNEL_DOMAIN}" && "${OLLAMA_WORKER_PROXY_HOSTNAME}" != "${CLOUDFLARE_TUNNEL_DOMAIN}" ]]; then
        echo "Worker proxy hostname ${OLLAMA_WORKER_PROXY_HOSTNAME} is not inside zone ${CLOUDFLARE_TUNNEL_DOMAIN}." >&2
        exit 1
    fi
}

check_upstream_access() {
    local header_value=""
    local status_code=""

    header_value="$(jq -cn \
        --arg client_id "${CF_ACCESS_CLIENT_ID}" \
        --arg client_secret "${CF_ACCESS_CLIENT_SECRET}" \
        '{"cf-access-client-id": $client_id, "cf-access-client-secret": $client_secret}' \
    )"

    status_code="$(curl -sS -o "${OUTPUT_DIR}/upstream-tags.json" -w '%{http_code}' \
        -H "${CLOUDFLARE_ACCESS_HEADER_NAME}: ${header_value}" \
        "https://${OLLAMA_PUBLIC_HOSTNAME}/api/tags")"

    if [[ "${status_code}" != "200" ]]; then
        echo "The upstream Ollama hostname did not validate successfully (HTTP ${status_code})." >&2
        echo "Run ./scripts/ollama-cloudflare-wizard.sh first, or provide working upstream service-token values." >&2
        exit 1
    fi
}

ensure_worker_dns_record() {
    local existing_records=""
    local record_count=""
    local existing_id=""
    local existing_proxied=""
    local record_type=""
    local create_response=""
    local update_response=""

    existing_records="$(cf_api "GET" "/zones/${CLOUDFLARE_ZONE_ID}/dns_records?name=${OLLAMA_WORKER_PROXY_HOSTNAME}")"
    save_json "${OUTPUT_DIR}/worker-dns-lookup.json" "${existing_records}"
    record_count="$(jq -r '.result | length' <<< "${existing_records}")"

    if [[ "${record_count}" == "0" ]]; then
        create_response="$(cf_api "POST" "/zones/${CLOUDFLARE_ZONE_ID}/dns_records" "$(jq -cn \
            --arg type "AAAA" \
            --arg name "${OLLAMA_WORKER_PROXY_HOSTNAME}" \
            --arg content "100::" \
            '{type: $type, name: $name, content: $content, proxied: true}')")"
        save_json "${OUTPUT_DIR}/worker-dns-create.json" "${create_response}"
        return
    fi

    existing_id="$(jq -r '.result[0].id' <<< "${existing_records}")"
    existing_proxied="$(jq -r '.result[0].proxied' <<< "${existing_records}")"
    record_type="$(jq -r '.result[0].type' <<< "${existing_records}")"

    if [[ "${existing_proxied}" != "true" ]]; then
        update_response="$(cf_api "PATCH" "/zones/${CLOUDFLARE_ZONE_ID}/dns_records/${existing_id}" "$(jq -cn \
            --arg type "${record_type}" \
            --arg name "${OLLAMA_WORKER_PROXY_HOSTNAME}" \
            --arg content "$(jq -r '.result[0].content' <<< "${existing_records}")" \
            '{type: $type, name: $name, content: $content, proxied: true}')")"
        save_json "${OUTPUT_DIR}/worker-dns-update.json" "${update_response}"
    fi
}

write_worker_project() {
    DEPLOY_DIR="${OUTPUT_DIR}/deploy"
    mkdir -p "${DEPLOY_DIR}"
    cp "${WORKER_SOURCE}" "${DEPLOY_DIR}/index.js"

    cat > "${DEPLOY_DIR}/wrangler.jsonc" <<EOF
{
  "name": "${OLLAMA_WORKER_PROXY_NAME}",
  "main": "index.js",
  "compatibility_date": "$(date +%F)",
  "account_id": "${CLOUDFLARE_ACCOUNT_ID}",
  "workers_dev": true,
  "routes": [
    {
      "pattern": "${OLLAMA_WORKER_PROXY_HOSTNAME}/*",
      "zone_id": "${CLOUDFLARE_ZONE_ID}"
    }
  ],
  "vars": {
    "UPSTREAM_OLLAMA_URL": "https://${OLLAMA_PUBLIC_HOSTNAME}",
    "UPSTREAM_AUTH_HEADER_NAME": "${CLOUDFLARE_ACCESS_HEADER_NAME}",
    "ALLOWED_ORIGINS": "${OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS}",
    "PROXY_AUTH_HEADER_NAME": "${OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME}"
  }
}
EOF
}

run_wrangler() {
    ( cd "${DEPLOY_DIR}" && \
        CLOUDFLARE_API_TOKEN="${CLOUDFLARE_API_TOKEN}" \
        CLOUDFLARE_ACCOUNT_ID="${CLOUDFLARE_ACCOUNT_ID}" \
        "${WRANGLER_CMD[@]}" "$@" )
}

deploy_worker() {
    if ! run_wrangler deploy --config wrangler.jsonc > "${OUTPUT_DIR}/wrangler-deploy.txt" 2> "${OUTPUT_DIR}/wrangler-deploy-error.txt"; then
        echo "Worker deployment failed." >&2
        echo "Check ${OUTPUT_DIR}/wrangler-deploy-error.txt for the raw Wrangler output." >&2
        echo "Your Cloudflare API token likely needs Workers Scripts Edit and Workers Routes Edit permissions." >&2
        exit 1
    fi

    printf '%s' "${CF_ACCESS_CLIENT_ID}" \
        | run_wrangler secret put UPSTREAM_CF_ACCESS_CLIENT_ID --config wrangler.jsonc > "${OUTPUT_DIR}/wrangler-secret-client-id.txt" 2>> "${OUTPUT_DIR}/wrangler-deploy-error.txt"

    printf '%s' "${CF_ACCESS_CLIENT_SECRET}" \
        | run_wrangler secret put UPSTREAM_CF_ACCESS_CLIENT_SECRET --config wrangler.jsonc > "${OUTPUT_DIR}/wrangler-secret-client-secret.txt" 2>> "${OUTPUT_DIR}/wrangler-deploy-error.txt"

    printf '%s' "${OLLAMA_WORKER_PROXY_AUTH_VALUE}" \
        | run_wrangler secret put PROXY_AUTH_HEADER_VALUE --config wrangler.jsonc > "${OUTPUT_DIR}/wrangler-secret-proxy-token.txt" 2>> "${OUTPUT_DIR}/wrangler-deploy-error.txt"
}

test_worker_proxy() {
    local preflight_status=""
    local get_status=""
    local base_url="https://${OLLAMA_WORKER_PROXY_HOSTNAME}"
    local test_origin=""
    local attempts=0
    local max_attempts=15

    test_origin="$(first_allowed_origin "${OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS}")"

    if [[ -z "${test_origin}" ]]; then
        test_origin="https://playground.wordpress.net"
    fi

    while (( attempts < max_attempts )); do
        attempts=$(( attempts + 1 ))

        preflight_status="$(curl -sS -o "${OUTPUT_DIR}/worker-preflight.txt" -w '%{http_code}' -X OPTIONS \
            -H "Origin: ${test_origin}" \
            -H "Access-Control-Request-Method: GET" \
            -H "Access-Control-Request-Headers: ${OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME}" \
            "${base_url}/api/tags" || true)"

        get_status="$(curl -sS -o "${OUTPUT_DIR}/worker-tags.json" -w '%{http_code}' \
            -H "${OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME}: ${OLLAMA_WORKER_PROXY_AUTH_VALUE}" \
            "${base_url}/api/tags" || true)"

        if [[ "${preflight_status}" == "204" && "${get_status}" == "200" ]]; then
            return 0
        fi

        sleep 2
    done

    echo "Worker proxy test failed after ${max_attempts} attempts." >&2
    echo "Preflight status: ${preflight_status:-unknown}" >&2
    echo "GET status: ${get_status:-unknown}" >&2
    exit 1
}

if [[ "${1:-}" == "--help" ]]; then
    print_help
    exit 0
fi

if [[ "${1:-}" == "--permissions" ]]; then
    print_permissions
    exit 0
fi

require_command "curl"
require_command "jq"
require_command "node"
require_command "npm"

load_defaults

OUTPUT_DIR="${OUTPUT_DIR_DEFAULT}"
mkdir -p "${OUTPUT_DIR}"

print_heading "Cloudflare Worker proxy for browser-based WordPress / Playground"

prompt_secret_with_default "CLOUDFLARE_API_TOKEN" "Cloudflare API token" "${CLOUDFLARE_API_TOKEN:-}"
prompt_with_default "CLOUDFLARE_ACCOUNT_ID" "Cloudflare account ID" "${CLOUDFLARE_ACCOUNT_ID:-}"
prompt_with_default "CLOUDFLARE_ZONE_ID" "Cloudflare zone ID" "${CLOUDFLARE_ZONE_ID:-}"
prompt_with_default "CLOUDFLARE_TUNNEL_DOMAIN" "Cloudflare main domain" "${CLOUDFLARE_TUNNEL_DOMAIN:-}"
prompt_with_default "OLLAMA_PUBLIC_HOSTNAME" "Existing protected upstream Ollama hostname" "${OLLAMA_PUBLIC_HOSTNAME:-}"
prompt_secret_with_default "CF_ACCESS_CLIENT_ID" "Upstream Cloudflare Access client ID" "${CF_ACCESS_CLIENT_ID:-}"
prompt_secret_with_default "CF_ACCESS_CLIENT_SECRET" "Upstream Cloudflare Access client secret" "${CF_ACCESS_CLIENT_SECRET:-}"
prompt_with_default "OLLAMA_WORKER_PROXY_NAME" "Worker name" "${OLLAMA_WORKER_PROXY_NAME:-}"
prompt_with_default "OLLAMA_WORKER_PROXY_HOSTNAME" "Public Worker proxy hostname" "${OLLAMA_WORKER_PROXY_HOSTNAME:-}"
prompt_with_default "OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS" "Allowed browser origins (comma-separated)" "${OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS:-}"
prompt_with_default "OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME" "Plugin header name for the Worker proxy" "${OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME:-}"
prompt_secret_with_default "OLLAMA_WORKER_PROXY_AUTH_VALUE" "Plugin header value / proxy token" "${OLLAMA_WORKER_PROXY_AUTH_VALUE:-}"

require_non_empty "CLOUDFLARE_API_TOKEN" "Cloudflare API token"
require_non_empty "OLLAMA_PUBLIC_HOSTNAME" "Existing protected upstream Ollama hostname"
require_non_empty "CF_ACCESS_CLIENT_ID" "Upstream Cloudflare Access client ID"
require_non_empty "CF_ACCESS_CLIENT_SECRET" "Upstream Cloudflare Access client secret"
require_non_empty "OLLAMA_WORKER_PROXY_NAME" "Worker name"
require_non_empty "OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS" "Allowed browser origins"
require_non_empty "OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME" "Plugin header name for the Worker proxy"
require_non_empty "OLLAMA_WORKER_PROXY_AUTH_VALUE" "Plugin header value / proxy token"

if [[ -z "${CLOUDFLARE_TUNNEL_DOMAIN}" && -n "${CLOUDFLARE_ZONE_ID}" ]]; then
    resolve_zone_name
fi

if [[ ( -z "${CLOUDFLARE_ZONE_ID}" || -z "${CLOUDFLARE_ACCOUNT_ID}" ) && -n "${CLOUDFLARE_TUNNEL_DOMAIN}" ]]; then
    discover_zone_and_account
fi

if [[ -z "${OLLAMA_WORKER_PROXY_HOSTNAME}" && -n "${CLOUDFLARE_TUNNEL_DOMAIN}" ]]; then
    OLLAMA_WORKER_PROXY_HOSTNAME="ollama-proxy.${CLOUDFLARE_TUNNEL_DOMAIN}"
fi

require_non_empty "CLOUDFLARE_TUNNEL_DOMAIN" "Cloudflare main domain"
require_non_empty "CLOUDFLARE_ACCOUNT_ID" "Cloudflare account ID"
require_non_empty "CLOUDFLARE_ZONE_ID" "Cloudflare zone ID"
require_non_empty "OLLAMA_WORKER_PROXY_HOSTNAME" "Public Worker proxy hostname"

if [[ "${OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME}" == "${CLOUDFLARE_ACCESS_HEADER_NAME}" ]]; then
    echo "The Worker proxy header name must be different from the upstream Access header name." >&2
    exit 1
fi

ensure_hostname_matches_zone
check_upstream_access
ensure_worker_dns_record
write_worker_project
deploy_worker
test_worker_proxy
persist_env

cat <<EOF

Worker proxy deployed successfully.

Paste these into AI Content Forge -> Ollama:

Base URL: https://${OLLAMA_WORKER_PROXY_HOSTNAME}
Access Header Name: ${OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME}
Access Header Value: ${OLLAMA_WORKER_PROXY_AUTH_VALUE}

Saved to .env:
  OLLAMA_WORKER_PROXY_NAME
  OLLAMA_WORKER_PROXY_HOSTNAME
  OLLAMA_WORKER_PROXY_ALLOWED_ORIGINS
  OLLAMA_WORKER_PROXY_AUTH_HEADER_NAME
  OLLAMA_WORKER_PROXY_AUTH_VALUE

Deployment artifacts:
  ${OUTPUT_DIR}/deploy/wrangler.jsonc
  ${OUTPUT_DIR}/wrangler-deploy.txt
  ${OUTPUT_DIR}/worker-preflight.txt
  ${OUTPUT_DIR}/worker-tags.json
EOF
