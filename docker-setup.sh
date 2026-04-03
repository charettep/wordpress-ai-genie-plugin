#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${ROOT_DIR}/.env"
ENV_TEMPLATE="${ROOT_DIR}/.env.example"
PLUGIN_SLUG="ai-content-forge"
CONTAINER_PLUGIN_REPO="/workspace/ai-content-forge"

cd "${ROOT_DIR}"

if [[ ! -t 0 ]]; then
	echo "docker-setup.sh requires an interactive terminal." >&2
	exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker is required but was not found in PATH." >&2
	exit 1
fi

if [[ -f "${ENV_TEMPLATE}" ]]; then
	set -a
	# shellcheck disable=SC1090
	source "${ENV_TEMPLATE}"
	set +a
fi

if [[ -f "${ENV_FILE}" ]]; then
	set -a
	# shellcheck disable=SC1090
	source "${ENV_FILE}"
	set +a
fi

prompt_var() {
	local key="$1"
	local label="$2"
	local current_default="${!key:-}"
	local input=""

	read -r -p "${label} [${current_default}]: " input
	printf -v "${key}" '%s' "${input:-${current_default}}"
}

escape_env_value() {
	printf '%s' "$1" | sed -e 's/[\\$`"]/\\&/g'
}

write_env_file() {
	cat > "${ENV_FILE}" <<EOF
SITE_PORT="$(escape_env_value "${SITE_PORT}")"
PMA_PORT="$(escape_env_value "${PMA_PORT}")"

MARIADB_DATABASE="$(escape_env_value "${MARIADB_DATABASE}")"
MARIADB_USER="$(escape_env_value "${MARIADB_USER}")"
MARIADB_PASSWORD="$(escape_env_value "${MARIADB_PASSWORD}")"
MARIADB_ROOT_PASSWORD="$(escape_env_value "${MARIADB_ROOT_PASSWORD}")"

WORDPRESS_DB_HOST="$(escape_env_value "${WORDPRESS_DB_HOST}")"
WORDPRESS_DB_NAME="$(escape_env_value "${WORDPRESS_DB_NAME}")"
WORDPRESS_DB_USER="$(escape_env_value "${WORDPRESS_DB_USER}")"
WORDPRESS_DB_PASSWORD="$(escape_env_value "${WORDPRESS_DB_PASSWORD}")"

PMA_HOST="$(escape_env_value "${PMA_HOST}")"
PMA_USER="$(escape_env_value "${PMA_USER}")"
PMA_PASSWORD="$(escape_env_value "${PMA_PASSWORD}")"

WP_ADMIN_USERNAME="$(escape_env_value "${WP_ADMIN_USERNAME}")"
WP_ADMIN_PASSWORD="$(escape_env_value "${WP_ADMIN_PASSWORD}")"
WP_ADMIN_EMAIL="$(escape_env_value "${WP_ADMIN_EMAIL}")"
WP_SITE_TITLE="$(escape_env_value "${WP_SITE_TITLE}")"
WP_BLOG_DESCRIPTION="$(escape_env_value "${WP_BLOG_DESCRIPTION}")"
EOF
}

resolve_latest_plugin_zip() {
	find "${ROOT_DIR}" -maxdepth 1 -type f -name "${PLUGIN_SLUG}-v*.zip" -printf '%f\n' | sort -V | tail -n 1
}

install_plugin_zip() {
	local zip_file="$1"

	if [[ -z "${zip_file}" ]]; then
		echo "No ${PLUGIN_SLUG}-v*.zip archive found in ${ROOT_DIR}." >&2
		echo "Build a release first with ./build-release.sh, then re-run docker-setup.sh." >&2
		exit 1
	fi

	echo "Installing plugin from ${zip_file}..."
	${WP} plugin install "${CONTAINER_PLUGIN_REPO}/${zip_file}" --force --activate
}

prompt_var "SITE_PORT" "WordPress site port"
prompt_var "PMA_PORT" "phpMyAdmin port"
prompt_var "MARIADB_DATABASE" "MariaDB database name"
prompt_var "MARIADB_USER" "MariaDB application user"
prompt_var "MARIADB_PASSWORD" "MariaDB application password"
prompt_var "MARIADB_ROOT_PASSWORD" "MariaDB root password"
prompt_var "WORDPRESS_DB_HOST" "WordPress database host"
prompt_var "WORDPRESS_DB_NAME" "WordPress database name"
prompt_var "WORDPRESS_DB_USER" "WordPress database user"
prompt_var "WORDPRESS_DB_PASSWORD" "WordPress database password"
prompt_var "PMA_HOST" "phpMyAdmin database host"
prompt_var "PMA_USER" "phpMyAdmin username"
prompt_var "PMA_PASSWORD" "phpMyAdmin password"
prompt_var "WP_ADMIN_USERNAME" "WordPress admin username"
prompt_var "WP_ADMIN_PASSWORD" "WordPress admin password"
prompt_var "WP_ADMIN_EMAIL" "WordPress admin email"
prompt_var "WP_SITE_TITLE" "WordPress site title"
prompt_var "WP_BLOG_DESCRIPTION" "WordPress blog description"

write_env_file

set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

WP="docker compose run --rm wpcli wp"
SITE_URL="http://localhost:${SITE_PORT}"
PLUGIN_ZIP_FILE="$(resolve_latest_plugin_zip)"

echo "Starting containers with values from ${ENV_FILE}..."
docker compose up -d

echo "Waiting for WordPress to be ready..."
until docker compose exec -T wordpress curl -sf http://localhost/wp-login.php >/dev/null 2>&1; do
	printf "."
	sleep 3
done
echo " ready."

if ${WP} core is-installed >/dev/null 2>&1; then
	echo "WordPress is already installed. Skipping core install."
else
	echo "Installing WordPress core..."
	${WP} core install \
		--url="${SITE_URL}" \
		--title="${WP_SITE_TITLE}" \
		--admin_user="${WP_ADMIN_USERNAME}" \
		--admin_password="${WP_ADMIN_PASSWORD}" \
		--admin_email="${WP_ADMIN_EMAIL}" \
		--skip-email
	echo "WordPress core installed."
fi

install_plugin_zip "${PLUGIN_ZIP_FILE}"

${WP} rewrite structure '/%postname%/' --hard >/dev/null
${WP} option update blogdescription "${WP_BLOG_DESCRIPTION}" >/dev/null

POST_ID="$(
	${WP} post create \
		--post_title="AI Content Forge Test Post" \
		--post_status=draft \
		--post_type=post \
		--porcelain 2>/dev/null || true
)"

if [[ -n "${POST_ID}" ]]; then
	echo "Created draft post ${POST_ID} for editor testing."
fi

cat <<EOF

WordPress is ready.

Site: ${SITE_URL}
Admin: ${SITE_URL}/wp-admin
Login: ${WP_ADMIN_USERNAME} / ${WP_ADMIN_PASSWORD}
phpMyAdmin: http://localhost:${PMA_PORT}
Plugin settings: ${SITE_URL}/wp-admin/admin.php?page=ai-content-forge

The active Docker configuration is saved in:
  ${ENV_FILE}

For later runs:
  docker compose up -d
  docker compose down
  docker compose run --rm wpcli wp plugin install ${CONTAINER_PLUGIN_REPO}/${PLUGIN_ZIP_FILE} --force --activate
EOF
