#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
ENV_FILE="${ROOT_DIR}/.env"
ENV_TEMPLATE="${ROOT_DIR}/.env.example"
ENV_LIB="${ROOT_DIR}/scripts/lib/env.sh"

if [[ ! -f "${ENV_LIB}" ]]; then
    echo "Missing env helper library: ${ENV_LIB}" >&2
    exit 1
fi

# shellcheck source=../../scripts/lib/env.sh
source "${ENV_LIB}"

env_load_file "${ENV_TEMPLATE}"
env_load_file "${ENV_FILE}"

CLOUDFLARE_API_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
ACCOUNT_ID="${ACCOUNT_ID:-${CLOUDFLARE_ACCOUNT_ID:-}}"
APP_ID="${APP_ID:-${CLOUDFLARE_ACCESS_APP_ID:-}}"
HEADER_NAME="${HEADER_NAME:-${CLOUDFLARE_ACCESS_HEADER_NAME:-Authorization}}"
PUBLIC_HOSTNAME="${OLLAMA_PUBLIC_HOSTNAME:-}"

: "${CLOUDFLARE_API_TOKEN:?Set CLOUDFLARE_API_TOKEN or save it in .env first}"
: "${ACCOUNT_ID:?Set ACCOUNT_ID or CLOUDFLARE_ACCOUNT_ID first}"
: "${HEADER_NAME:?Set HEADER_NAME or CLOUDFLARE_ACCESS_HEADER_NAME first}"

tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT

if [[ -z "${APP_ID}" ]]; then
  : "${PUBLIC_HOSTNAME:?Set APP_ID/CLOUDFLARE_ACCESS_APP_ID or OLLAMA_PUBLIC_HOSTNAME first}"

  curl "https://api.cloudflare.com/client/v4/accounts/${ACCOUNT_ID}/access/apps" \
    --request GET \
    --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
    > "${tmp_dir}/access-apps.json"

  APP_ID="$(jq -r --arg domain "${PUBLIC_HOSTNAME}" '.result[] | select(.domain == $domain) | .id' "${tmp_dir}/access-apps.json" | head -n 1)"
fi

: "${APP_ID:?Could not determine APP_ID or CLOUDFLARE_ACCESS_APP_ID}"

curl "https://api.cloudflare.com/client/v4/accounts/${ACCOUNT_ID}/access/apps/${APP_ID}" \
  --request GET \
  --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
  > "${tmp_dir}/access-app-response.json"

jq --arg header_name "${HEADER_NAME}" \
  '.result | .read_service_tokens_from_header = $header_name' \
  "${tmp_dir}/access-app-response.json" \
  > "${tmp_dir}/access-app-update.json"

curl "https://api.cloudflare.com/client/v4/accounts/${ACCOUNT_ID}/access/apps/${APP_ID}" \
  --request PUT \
  --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
  --header "Content-Type: application/json" \
  --data @"${tmp_dir}/access-app-update.json" \
  > "${tmp_dir}/access-app-put-response.json"

jq '{app_id: .result.id, domain: .result.domain, read_service_tokens_from_header: .result.read_service_tokens_from_header}' \
  "${tmp_dir}/access-app-put-response.json"
