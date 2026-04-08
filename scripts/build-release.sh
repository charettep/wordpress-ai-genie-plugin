#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="ai-content-forge"
PLUGIN_VERSION="$(awk -F': *' '/^[[:space:]]*\* Version:/ { print $2; exit }' "${ROOT_DIR}/ai-content-forge.php")"
BUILD_DIR="${ROOT_DIR}/gutenberg/build"
STAGE_DIR="$(mktemp -d)"
PLUGIN_DIR="${STAGE_DIR}/${PLUGIN_SLUG}"

if [[ -z "${PLUGIN_VERSION}" ]]; then
    echo "Could not determine plugin version from ai-content-forge.php." >&2
    exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
    echo "zip is required to build release archives." >&2
    exit 1
fi

ZIP_PATH="${ROOT_DIR}/${PLUGIN_SLUG}-v${PLUGIN_VERSION}.zip"

cleanup() {
    rm -rf "${STAGE_DIR}"
}
trap cleanup EXIT

if [[ ! -f "${BUILD_DIR}/index.js" || ! -f "${BUILD_DIR}/index.asset.php" ]]; then
    echo "Missing Gutenberg build assets in ${BUILD_DIR}. Run 'npm install --package-lock=false && npm run build' in gutenberg/ first." >&2
    exit 1
fi

mkdir -p "${PLUGIN_DIR}/gutenberg"

if [[ -e "${ZIP_PATH}" ]]; then
    echo "Refusing to overwrite existing release archive: ${ZIP_PATH}" >&2
    exit 1
fi

cp "${ROOT_DIR}/ai-content-forge.php" "${PLUGIN_DIR}/"
cp "${ROOT_DIR}/README.md" "${PLUGIN_DIR}/"
cp -R "${ROOT_DIR}/admin" "${PLUGIN_DIR}/"
cp -R "${ROOT_DIR}/assets" "${PLUGIN_DIR}/"
cp -R "${ROOT_DIR}/includes" "${PLUGIN_DIR}/"
cp -R "${ROOT_DIR}/gutenberg/build" "${PLUGIN_DIR}/gutenberg/"

if [[ -d "${ROOT_DIR}/docs" ]]; then
    cp -R "${ROOT_DIR}/docs" "${PLUGIN_DIR}/"
fi

if [[ -d "${ROOT_DIR}/templates" ]]; then
    cp -R "${ROOT_DIR}/templates" "${PLUGIN_DIR}/"
fi

( cd "${STAGE_DIR}" && zip -qr "${ZIP_PATH}" "${PLUGIN_SLUG}" )

if command -v unzip >/dev/null 2>&1; then
    ARCHIVE_LISTING="$(unzip -Z1 "${ZIP_PATH}")"

    if ! grep -qx "${PLUGIN_SLUG}/ai-content-forge.php" <<< "${ARCHIVE_LISTING}"; then
        echo "Release archive is missing ${PLUGIN_SLUG}/ai-content-forge.php." >&2
        exit 1
    fi

    if ! grep -qx "${PLUGIN_SLUG}/gutenberg/build/index.js" <<< "${ARCHIVE_LISTING}"; then
        echo "Release archive is missing ${PLUGIN_SLUG}/gutenberg/build/index.js." >&2
        exit 1
    fi

    if [[ -d "${ROOT_DIR}/docs" ]] && ! grep -qx "${PLUGIN_SLUG}/docs/ollama-cloudflare-beginner-guide.md" <<< "${ARCHIVE_LISTING}"; then
        echo "Release archive is missing ${PLUGIN_SLUG}/docs/ollama-cloudflare-beginner-guide.md." >&2
        exit 1
    fi
fi

echo "Built ${ZIP_PATH}"
