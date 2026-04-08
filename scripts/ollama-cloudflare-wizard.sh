#!/usr/bin/env bash
set -euo pipefail

CF_API_BASE="https://api.cloudflare.com/client/v4"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
OUTPUT_DIR_DEFAULT="${PWD}/ollama-cloudflare-setup-${TIMESTAMP}"

print_permissions() {
    cat <<'EOF'
Cloudflare API token permissions recommended for full automation:

Account permissions:
  - Cloudflare Tunnel Edit
  - Access: Apps and Policies Edit
  - Access: Service Tokens Edit

Zone permissions:
  - DNS Edit
  - Zone Read

Why these are needed:
  - Cloudflare Tunnel Edit: create tunnel, push tunnel config, fetch tunnel token
  - Access: Apps and Policies Edit: create/update the Access app and its Service Auth policy
  - Access: Service Tokens Edit: create the Access service token
  - DNS Edit: create/update the Ollama hostname DNS record
  - Zone Read: auto-detect the zone ID and account ID from your domain name
EOF
}

print_help() {
    cat <<'EOF'
Usage:
  ./scripts/ollama-cloudflare-wizard.sh
  ./scripts/ollama-cloudflare-wizard.sh --help
  ./scripts/ollama-cloudflare-wizard.sh --permissions

What it does:
  - verifies the local Ollama endpoint
  - installs cloudflared and jq on Debian/Ubuntu when needed
  - creates or reuses the Cloudflare Tunnel
  - pushes the tunnel ingress config
  - creates or updates the DNS record
  - creates or reuses the Cloudflare Access app
  - creates a service token and Service Auth policy
  - enables single-header mode
  - tests the final public Ollama endpoint
  - prints the exact WordPress values to paste into AI Content Forge

Use --permissions to print the required Cloudflare API token scopes without starting the interactive flow.
EOF
}

is_wsl() {
    grep -qiE '(microsoft|wsl)' /proc/sys/kernel/osrelease 2>/dev/null
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

prompt_secret() {
    local var_name="$1"
    local label="$2"
    local input=""

    read -r -s -p "${label}: " input
    printf '\n'
    printf -v "${var_name}" '%s' "${input}"
}

yes_no_prompt() {
    local label="$1"
    local default_answer="$2"
    local input=""

    read -r -p "${label} [${default_answer}]: " input
    input="${input:-${default_answer}}"

    case "${input}" in
        y|Y|yes|YES) return 0 ;;
        *) return 1 ;;
    esac
}

require_command() {
    local command_name="$1"

    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Missing required command: ${command_name}" >&2
        exit 1
    fi
}

require_root_if_needed() {
    if [[ "${RUN_MODE}" == "service" ]] && ! command -v sudo >/dev/null 2>&1; then
        echo "sudo is required to install the cloudflared service." >&2
        exit 1
    fi
}

save_json() {
    local target_file="$1"
    local json_payload="$2"

    printf '%s\n' "${json_payload}" > "${target_file}"
}

cf_api() {
    local method="$1"
    local path="$2"
    local data="${3:-}"
    local response_file
    response_file="$(mktemp)"

    if [[ -n "${data}" ]]; then
        curl -fsS "${CF_API_BASE}${path}" \
            --request "${method}" \
            --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
            --header "Content-Type: application/json" \
            --data "${data}" \
            > "${response_file}"
    else
        curl -fsS "${CF_API_BASE}${path}" \
            --request "${method}" \
            --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
            > "${response_file}"
    fi

    if ! jq -e '.success == true' "${response_file}" >/dev/null 2>&1; then
        echo "Cloudflare API call failed: ${method} ${path}" >&2
        cat "${response_file}" >&2
        rm -f "${response_file}"
        exit 1
    fi

    cat "${response_file}"
    rm -f "${response_file}"
}

cf_api_get_zones() {
    local domain_name="$1"
    local response_file
    response_file="$(mktemp)"

    curl -fsS -G "${CF_API_BASE}/zones" \
        --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
        --data-urlencode "name=${domain_name}" \
        --data-urlencode "match=all" \
        > "${response_file}"

    if ! jq -e '.success == true' "${response_file}" >/dev/null 2>&1; then
        echo "Cloudflare zone lookup failed." >&2
        cat "${response_file}" >&2
        rm -f "${response_file}"
        exit 1
    fi

    cat "${response_file}"
    rm -f "${response_file}"
}

cf_api_get_dns_record() {
    local zone_id="$1"
    local hostname="$2"
    local response_file
    response_file="$(mktemp)"

    curl -fsS -G "${CF_API_BASE}/zones/${zone_id}/dns_records" \
        --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
        --data-urlencode "type=CNAME" \
        --data-urlencode "name=${hostname}" \
        > "${response_file}"

    if ! jq -e '.success == true' "${response_file}" >/dev/null 2>&1; then
        echo "Cloudflare DNS lookup failed." >&2
        cat "${response_file}" >&2
        rm -f "${response_file}"
        exit 1
    fi

    cat "${response_file}"
    rm -f "${response_file}"
}

cf_api_list_tunnels() {
    local account_id="$1"
    cf_api "GET" "/accounts/${account_id}/cfd_tunnel"
}

cf_api_list_access_apps() {
    local account_id="$1"
    cf_api "GET" "/accounts/${account_id}/access/apps"
}

install_cloudflared_if_needed() {
    if command -v cloudflared >/dev/null 2>&1 && command -v jq >/dev/null 2>&1; then
        return
    fi

    if ! yes_no_prompt "cloudflared or jq is missing. Install them now with apt?" "y"; then
        echo "Install cloudflared and jq first, then re-run this script." >&2
        exit 1
    fi

    if ! command -v apt-get >/dev/null 2>&1; then
        echo "Automatic installation currently supports Debian/Ubuntu systems with apt-get." >&2
        echo "Install cloudflared from https://pkg.cloudflare.com/ and jq from your package manager, then re-run this script." >&2
        exit 1
    fi

    require_command "sudo"
    sudo mkdir -p --mode=0755 /usr/share/keyrings
    curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
    echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' | sudo tee /etc/apt/sources.list.d/cloudflared.list >/dev/null
    sudo apt-get update
    sudo apt-get install -y cloudflared jq
}

install_or_update_cloudflared_service() {
    local tunnel_token="$1"
    local install_command=(sudo cloudflared service install "${tunnel_token}")

    if [[ "${RUN_MODE}" == "manual" ]]; then
        MANUAL_RUN_COMMAND="cloudflared tunnel run --token ${tunnel_token}"
        return
    fi

    if ! command -v systemctl >/dev/null 2>&1; then
        echo "systemctl is not available, so the script will use manual cloudflared run mode instead."
        MANUAL_RUN_COMMAND="cloudflared tunnel run --token ${tunnel_token}"
        return
    fi

    if systemctl list-unit-files cloudflared.service >/dev/null 2>&1; then
        if yes_no_prompt "An existing cloudflared service was found. Replace it with this tunnel token?" "y"; then
            sudo cloudflared service uninstall || true
        else
            MANUAL_RUN_COMMAND="cloudflared tunnel run --token ${tunnel_token}"
            return
        fi
    fi

    "${install_command[@]}"
    sudo systemctl restart cloudflared
}

wait_for_public_endpoint() {
    local auth_header="$1"
    local url="$2"
    local attempts=0
    local max_attempts=30

    until curl -fsS -H "${ACCESS_HEADER_NAME}: ${auth_header}" "${url}" >/dev/null 2>&1; do
        (( attempts += 1 ))
        if (( attempts >= max_attempts )); then
            return 1
        fi
        sleep 2
    done

    return 0
}

print_heading "AI Content Forge Ollama + Cloudflare Wizard"
echo "This script can create and verify the Cloudflare Tunnel, DNS record, Access app,"
echo "service token, and service-auth policy needed for a remote Ollama endpoint."

case "${1:-}" in
    --help|-h)
        print_help
        exit 0
        ;;
    --permissions)
        print_permissions
        exit 0
        ;;
    "")
        ;;
    *)
        echo "Unknown argument: ${1}" >&2
        print_help >&2
        exit 1
        ;;
esac

print_permissions

prompt_secret "CLOUDFLARE_API_TOKEN" "Paste your Cloudflare API token"
prompt_with_default "MAIN_DOMAIN" "Your main domain name already on Cloudflare" "example.com"
prompt_with_default "OLLAMA_SUBDOMAIN" "Subdomain to use for Ollama" "ollama"
prompt_with_default "LOCAL_OLLAMA_URL" "Local Ollama URL that cloudflared should reach" "http://localhost:11434"
prompt_with_default "TUNNEL_NAME" "Tunnel name" "home-ollama"
prompt_with_default "ACCESS_APP_NAME" "Access app name" "Ollama API"
prompt_with_default "SERVICE_TOKEN_NAME" "Access service token name" "ai-content-forge-ollama"
prompt_with_default "SERVICE_TOKEN_DURATION" "Service token duration" "8760h"
prompt_with_default "ACCESS_HEADER_NAME" "Header name for single-header auth" "Authorization"
prompt_with_default "OUTPUT_DIR" "Directory for saved results" "${OUTPUT_DIR_DEFAULT}"

OLLAMA_HOSTNAME="${OLLAMA_SUBDOMAIN}.${MAIN_DOMAIN}"
PUBLIC_OLLAMA_URL="https://${OLLAMA_HOSTNAME}"
mkdir -p "${OUTPUT_DIR}"

if yes_no_prompt "Run cloudflared as a system service on this machine?" "y"; then
    RUN_MODE="service"
else
    RUN_MODE="manual"
fi

if is_wsl; then
    echo
    echo "WSL detected."
    echo "Recommended: run both Ollama and cloudflared inside this same Ubuntu/WSL environment."
fi

install_cloudflared_if_needed
require_command "curl"
require_command "jq"
require_command "cloudflared"
require_root_if_needed

print_heading "Checking local Ollama"
if ! curl -fsS "${LOCAL_OLLAMA_URL}/api/tags" > "${OUTPUT_DIR}/local-ollama-tags.json"; then
    echo "The local Ollama check failed at ${LOCAL_OLLAMA_URL}/api/tags." >&2
    echo "Fix Ollama first, then re-run this script." >&2
    exit 1
fi
echo "Local Ollama is responding."

print_heading "Finding zone and account"
ZONES_JSON="$(cf_api_get_zones "${MAIN_DOMAIN}")"
save_json "${OUTPUT_DIR}/zones.json" "${ZONES_JSON}"

ZONE_ID="$(jq -r '.result[0].id // empty' <<< "${ZONES_JSON}")"
ACCOUNT_ID="$(jq -r '.result[0].account.id // empty' <<< "${ZONES_JSON}")"

if [[ -z "${ZONE_ID}" || -z "${ACCOUNT_ID}" ]]; then
    echo "Could not determine the zone ID or account ID from ${MAIN_DOMAIN}." >&2
    echo "Make sure the domain is active on Cloudflare and the token has Zone Read." >&2
    exit 1
fi

echo "Zone ID: ${ZONE_ID}"
echo "Account ID: ${ACCOUNT_ID}"

print_heading "Creating or reusing tunnel"
TUNNELS_JSON="$(cf_api_list_tunnels "${ACCOUNT_ID}")"
save_json "${OUTPUT_DIR}/tunnels.json" "${TUNNELS_JSON}"

TUNNEL_ID="$(jq -r --arg name "${TUNNEL_NAME}" '.result[] | select(.name == $name) | .id' <<< "${TUNNELS_JSON}" | head -n 1)"
TUNNEL_TOKEN=""

if [[ -n "${TUNNEL_ID}" ]]; then
    echo "Reusing existing tunnel: ${TUNNEL_NAME} (${TUNNEL_ID})"
    TUNNEL_TOKEN_RESPONSE="$(cf_api "GET" "/accounts/${ACCOUNT_ID}/cfd_tunnel/${TUNNEL_ID}/token")"
    save_json "${OUTPUT_DIR}/tunnel-token.json" "${TUNNEL_TOKEN_RESPONSE}"
    TUNNEL_TOKEN="$(jq -r '.result // empty' <<< "${TUNNEL_TOKEN_RESPONSE}")"
else
    CREATE_TUNNEL_PAYLOAD="$(jq -nc --arg name "${TUNNEL_NAME}" '{"name": $name, "config_src": "cloudflare"}')"
    CREATE_TUNNEL_RESPONSE="$(cf_api "POST" "/accounts/${ACCOUNT_ID}/cfd_tunnel" "${CREATE_TUNNEL_PAYLOAD}")"
    save_json "${OUTPUT_DIR}/create-tunnel.json" "${CREATE_TUNNEL_RESPONSE}"
    TUNNEL_ID="$(jq -r '.result.id' <<< "${CREATE_TUNNEL_RESPONSE}")"
    TUNNEL_TOKEN="$(jq -r '.result.token // empty' <<< "${CREATE_TUNNEL_RESPONSE}")"

    if [[ -z "${TUNNEL_TOKEN}" ]]; then
        TUNNEL_TOKEN_RESPONSE="$(cf_api "GET" "/accounts/${ACCOUNT_ID}/cfd_tunnel/${TUNNEL_ID}/token")"
        save_json "${OUTPUT_DIR}/tunnel-token.json" "${TUNNEL_TOKEN_RESPONSE}"
        TUNNEL_TOKEN="$(jq -r '.result // empty' <<< "${TUNNEL_TOKEN_RESPONSE}")"
    fi

    echo "Created tunnel: ${TUNNEL_NAME} (${TUNNEL_ID})"
fi

if [[ -z "${TUNNEL_TOKEN}" ]]; then
    echo "Could not determine the tunnel token." >&2
    exit 1
fi

print_heading "Pushing tunnel ingress config"
TUNNEL_CONFIG_PAYLOAD="$(jq -nc \
    --arg hostname "${OLLAMA_HOSTNAME}" \
    --arg service "${LOCAL_OLLAMA_URL}" \
    '{
        "config": {
            "ingress": [
                {
                    "hostname": $hostname,
                    "service": $service,
                    "originRequest": {}
                },
                {
                    "service": "http_status:404"
                }
            ]
        }
    }'
)"
TUNNEL_CONFIG_RESPONSE="$(cf_api "PUT" "/accounts/${ACCOUNT_ID}/cfd_tunnel/${TUNNEL_ID}/configurations" "${TUNNEL_CONFIG_PAYLOAD}")"
save_json "${OUTPUT_DIR}/tunnel-config-response.json" "${TUNNEL_CONFIG_RESPONSE}"
echo "Tunnel config uploaded."

print_heading "Creating or updating DNS record"
DNS_LOOKUP_JSON="$(cf_api_get_dns_record "${ZONE_ID}" "${OLLAMA_HOSTNAME}")"
save_json "${OUTPUT_DIR}/dns-lookup.json" "${DNS_LOOKUP_JSON}"

EXPECTED_CNAME="${TUNNEL_ID}.cfargotunnel.com"
DNS_RECORD_ID="$(jq -r '.result[0].id // empty' <<< "${DNS_LOOKUP_JSON}")"

DNS_PAYLOAD="$(jq -nc \
    --arg name "${OLLAMA_HOSTNAME}" \
    --arg content "${EXPECTED_CNAME}" \
    '{
        "type": "CNAME",
        "proxied": true,
        "name": $name,
        "content": $content
    }'
)"

if [[ -n "${DNS_RECORD_ID}" ]]; then
    DNS_RESPONSE="$(cf_api "PUT" "/zones/${ZONE_ID}/dns_records/${DNS_RECORD_ID}" "${DNS_PAYLOAD}")"
else
    DNS_RESPONSE="$(cf_api "POST" "/zones/${ZONE_ID}/dns_records" "${DNS_PAYLOAD}")"
fi
save_json "${OUTPUT_DIR}/dns-response.json" "${DNS_RESPONSE}"
echo "DNS record is ready for ${OLLAMA_HOSTNAME}."

print_heading "Installing or updating local cloudflared"
install_or_update_cloudflared_service "${TUNNEL_TOKEN}"
if [[ -n "${MANUAL_RUN_COMMAND:-}" ]]; then
    echo "Manual cloudflared command prepared."
    printf '%s\n' "${MANUAL_RUN_COMMAND}" > "${OUTPUT_DIR}/manual-cloudflared-run-command.txt"
else
    sudo systemctl status cloudflared --no-pager > "${OUTPUT_DIR}/cloudflared-service-status.txt" 2>&1 || true
fi

print_heading "Creating or reusing Access app"
ACCESS_APPS_JSON="$(cf_api_list_access_apps "${ACCOUNT_ID}")"
save_json "${OUTPUT_DIR}/access-apps.json" "${ACCESS_APPS_JSON}"

ACCESS_APP_ID="$(jq -r --arg domain "${OLLAMA_HOSTNAME}" '.result[] | select(.domain == $domain) | .id' <<< "${ACCESS_APPS_JSON}" | head -n 1)"
ACCESS_APP_RESPONSE=""

if [[ -z "${ACCESS_APP_ID}" ]]; then
    CREATE_ACCESS_APP_PAYLOAD="$(jq -nc \
        --arg name "${ACCESS_APP_NAME}" \
        --arg domain "${OLLAMA_HOSTNAME}" \
        --arg header "${ACCESS_HEADER_NAME}" \
        '{
            "name": $name,
            "domain": $domain,
            "type": "self_hosted",
            "app_launcher_visible": false,
            "auto_redirect_to_identity": false,
            "session_duration": "24h",
            "read_service_tokens_from_header": $header
        }'
    )"
    CREATE_ACCESS_APP_RESPONSE="$(cf_api "POST" "/accounts/${ACCOUNT_ID}/access/apps" "${CREATE_ACCESS_APP_PAYLOAD}")"
    save_json "${OUTPUT_DIR}/create-access-app.json" "${CREATE_ACCESS_APP_RESPONSE}"
    ACCESS_APP_ID="$(jq -r '.result.id' <<< "${CREATE_ACCESS_APP_RESPONSE}")"
    ACCESS_APP_RESPONSE="${CREATE_ACCESS_APP_RESPONSE}"
    echo "Created Access app: ${ACCESS_APP_NAME} (${ACCESS_APP_ID})"
else
    echo "Reusing existing Access app for ${OLLAMA_HOSTNAME}: ${ACCESS_APP_ID}"
    EXISTING_ACCESS_APP_RESPONSE="$(cf_api "GET" "/accounts/${ACCOUNT_ID}/access/apps/${ACCESS_APP_ID}")"
    save_json "${OUTPUT_DIR}/existing-access-app.json" "${EXISTING_ACCESS_APP_RESPONSE}"
    UPDATE_ACCESS_APP_PAYLOAD="$(jq -c \
        --arg header "${ACCESS_HEADER_NAME}" \
        '.result | .read_service_tokens_from_header = $header' \
        <<< "${EXISTING_ACCESS_APP_RESPONSE}"
    )"
    UPDATE_ACCESS_APP_RESPONSE="$(cf_api "PUT" "/accounts/${ACCOUNT_ID}/access/apps/${ACCESS_APP_ID}" "${UPDATE_ACCESS_APP_PAYLOAD}")"
    save_json "${OUTPUT_DIR}/update-access-app.json" "${UPDATE_ACCESS_APP_RESPONSE}"
    ACCESS_APP_RESPONSE="${UPDATE_ACCESS_APP_RESPONSE}"
fi

NEXT_POLICY_PRECEDENCE="$(jq -r '(.result.policies // []) | map(.precedence // 0) | max // 0 | . + 1' <<< "${ACCESS_APP_RESPONSE}")"

print_heading "Creating Access service token"
CREATE_SERVICE_TOKEN_PAYLOAD="$(jq -nc \
    --arg name "${SERVICE_TOKEN_NAME}" \
    --arg duration "${SERVICE_TOKEN_DURATION}" \
    '{
        "name": $name,
        "duration": $duration
    }'
)"
CREATE_SERVICE_TOKEN_RESPONSE="$(cf_api "POST" "/accounts/${ACCOUNT_ID}/access/service_tokens" "${CREATE_SERVICE_TOKEN_PAYLOAD}")"
save_json "${OUTPUT_DIR}/create-service-token.json" "${CREATE_SERVICE_TOKEN_RESPONSE}"

SERVICE_TOKEN_ID="$(jq -r '.result.id' <<< "${CREATE_SERVICE_TOKEN_RESPONSE}")"
CLIENT_ID="$(jq -r '.result.client_id' <<< "${CREATE_SERVICE_TOKEN_RESPONSE}")"
CLIENT_SECRET="$(jq -r '.result.client_secret' <<< "${CREATE_SERVICE_TOKEN_RESPONSE}")"

print_heading "Creating Service Auth policy"
CREATE_POLICY_PAYLOAD="$(jq -nc \
    --arg token_id "${SERVICE_TOKEN_ID}" \
    --argjson precedence "${NEXT_POLICY_PRECEDENCE}" \
    '{
        "name": "AI Content Forge Service Auth",
        "decision": "non_identity",
        "include": [
            {
                "service_token": {
                    "token_id": $token_id
                }
            }
        ],
        "precedence": $precedence
    }'
)"
CREATE_POLICY_RESPONSE="$(cf_api "POST" "/accounts/${ACCOUNT_ID}/access/apps/${ACCESS_APP_ID}/policies" "${CREATE_POLICY_PAYLOAD}")"
save_json "${OUTPUT_DIR}/create-policy.json" "${CREATE_POLICY_RESPONSE}"

AUTH_HEADER_VALUE="$(jq -cn \
    --arg client_id "${CLIENT_ID}" \
    --arg client_secret "${CLIENT_SECRET}" \
    '{"cf-access-client-id": $client_id, "cf-access-client-secret": $client_secret}' \
)"

printf '%s\n' "Base URL: ${PUBLIC_OLLAMA_URL}" > "${OUTPUT_DIR}/wordpress-values.txt"
printf '%s\n' "Access Header Name: ${ACCESS_HEADER_NAME}" >> "${OUTPUT_DIR}/wordpress-values.txt"
printf '%s\n' "Access Header Value: ${AUTH_HEADER_VALUE}" >> "${OUTPUT_DIR}/wordpress-values.txt"

print_heading "Testing the public Ollama endpoint"
if wait_for_public_endpoint "${AUTH_HEADER_VALUE}" "${PUBLIC_OLLAMA_URL}/api/tags"; then
    curl -fsS -H "${ACCESS_HEADER_NAME}: ${AUTH_HEADER_VALUE}" "${PUBLIC_OLLAMA_URL}/api/tags" > "${OUTPUT_DIR}/public-ollama-tags.json"
    TEST_STATUS="success"
    echo "Public test succeeded."
else
    TEST_STATUS="failed"
    echo "The public test did not succeed within the timeout window." >&2
    echo "Check the files in ${OUTPUT_DIR} and confirm cloudflared is running locally." >&2
fi

cat <<EOF

Setup summary
=============

Cloudflare account ID: ${ACCOUNT_ID}
Cloudflare zone ID: ${ZONE_ID}
Tunnel ID: ${TUNNEL_ID}
Access app ID: ${ACCESS_APP_ID}
Service token ID: ${SERVICE_TOKEN_ID}
Public URL: ${PUBLIC_OLLAMA_URL}
Public test status: ${TEST_STATUS}

Paste these into AI Content Forge -> Ollama:

Base URL: ${PUBLIC_OLLAMA_URL}
Access Header Name: ${ACCESS_HEADER_NAME}
Access Header Value: ${AUTH_HEADER_VALUE}

Saved files:
  ${OUTPUT_DIR}/wordpress-values.txt
  ${OUTPUT_DIR}/create-tunnel.json
  ${OUTPUT_DIR}/tunnel-config-response.json
  ${OUTPUT_DIR}/dns-response.json
  ${OUTPUT_DIR}/create-access-app.json
  ${OUTPUT_DIR}/create-service-token.json
  ${OUTPUT_DIR}/create-policy.json

EOF

if [[ -n "${MANUAL_RUN_COMMAND:-}" ]]; then
    cat <<EOF
cloudflared was not installed as a system service.
Run this command in a dedicated terminal and keep it open:

${MANUAL_RUN_COMMAND}

EOF
fi
