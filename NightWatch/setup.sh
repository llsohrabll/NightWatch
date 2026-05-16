#!/usr/bin/env bash
# Hardened Apache setup helper for the NightWatch PHP/MySQL application.
# Run this script from the project root, or set PROJECT_DIR=/path/to/NightWatch.

set -Eeuo pipefail

# Re-run through sudo when the script is started by a normal user.
if [[ "${EUID}" -ne 0 ]]; then
  exec sudo -E bash "$0" "$@"
fi

# Resolve the project root. The default is the directory containing this script.
PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"

# The browser-facing Apache document root must be WebApp, not the project root.
WEB_ROOT="${WEB_ROOT:-${PROJECT_DIR}/WebApp}"

# Database configuration is copied outside the document root.
CONFIG_SRC="${CONFIG_SRC:-${PROJECT_DIR}/DB/db_config.php}"
CONFIG_DIR="${CONFIG_DIR:-/var/www/config}"
CONFIG_DEST="${CONFIG_DEST:-${CONFIG_DIR}/db_config.php}"

# Private logs live beside WebApp so they are not publicly downloadable.
LOG_DIR="${LOG_DIR:-${PROJECT_DIR}/logs}"

# Apache defaults for Debian/Ubuntu. Override when your distro uses another user.
APACHE_USER="${APACHE_USER:-www-data}"
APACHE_GROUP="${APACHE_GROUP:-www-data}"

# Apache virtual-host details. Override SERVER_NAME with your real domain.
SERVER_NAME="${SERVER_NAME:-nightwatch.local}"
SITE_NAME="${SITE_NAME:-nightwatch.conf}"
SITE_AVAILABLE="/etc/apache2/sites-available/${SITE_NAME}"

# HTTPS is required for production endpoints. Set ENABLE_HTTPS=1 with cert paths.
ENABLE_HTTPS="${ENABLE_HTTPS:-0}"
SSL_CERT_FILE="${SSL_CERT_FILE:-/etc/letsencrypt/live/${SERVER_NAME}/fullchain.pem}"
SSL_KEY_FILE="${SSL_KEY_FILE:-/etc/letsencrypt/live/${SERVER_NAME}/privkey.pem}"

# Optional database import. Keep disabled unless you intentionally want setup.sh to run DB/DB.sql.
RUN_DB_IMPORT="${RUN_DB_IMPORT:-0}"
DB_SQL="${DB_SQL:-${PROJECT_DIR}/DB/DB.sql}"

# Fail early when required project files are missing.
[[ -d "${WEB_ROOT}" ]] || { echo "Missing WebApp directory: ${WEB_ROOT}" >&2; exit 1; }
[[ -f "${CONFIG_SRC}" ]] || { echo "Missing DB config file: ${CONFIG_SRC}" >&2; exit 1; }
[[ -f "${DB_SQL}" ]] || { echo "Missing SQL file: ${DB_SQL}" >&2; exit 1; }
command -v apache2ctl >/dev/null || { echo "Apache is not installed. Install apache2 first." >&2; exit 1; }

# Enable Apache modules required by the app's .htaccess security headers and routing.
a2enmod rewrite headers ssl >/dev/null

# Create the private configuration directory and protect it from normal users.
install -d -o root -g "${APACHE_GROUP}" -m 0750 "${CONFIG_DIR}"

# Copy db_config.php outside WebApp so database credentials are never served as static files.
install -o root -g "${APACHE_GROUP}" -m 0640 "${CONFIG_SRC}" "${CONFIG_DEST}"

# Create the private log directory and make only Apache/PHP able to write to it.
install -d -o "${APACHE_USER}" -g "${APACHE_GROUP}" -m 0700 "${LOG_DIR}"

# Create upload directories with Apache as owner so PHP can store profile photos.
install -d -o "${APACHE_USER}" -g "${APACHE_GROUP}" -m 0755 "${WEB_ROOT}/uploads" "${WEB_ROOT}/uploads/photos"

# Keep executable PHP out of upload folders, even if an attacker uploads a renamed script.
cat > "${WEB_ROOT}/uploads/.htaccess" <<'HTACCESS'
Options -Indexes
<FilesMatch "\.(php|phtml|php[0-9]?|phar)$">
    Require all denied
</FilesMatch>
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Cross-Origin-Resource-Policy "same-origin"
</IfModule>
HTACCESS
cp "${WEB_ROOT}/uploads/.htaccess" "${WEB_ROOT}/uploads/photos/.htaccess"
chown root:"${APACHE_GROUP}" "${WEB_ROOT}/uploads/.htaccess" "${WEB_ROOT}/uploads/photos/.htaccess"
chmod 0644 "${WEB_ROOT}/uploads/.htaccess" "${WEB_ROOT}/uploads/photos/.htaccess"

# Deny access if someone accidentally points Apache to the project root instead of WebApp.
cat > "${PROJECT_DIR}/.htaccess" <<'HTACCESS'
Require all denied
HTACCESS
chown root:"${APACHE_GROUP}" "${PROJECT_DIR}/.htaccess"
chmod 0644 "${PROJECT_DIR}/.htaccess"

# Lock application source files as read-only for Apache; only uploads and logs remain writable.
chown -R root:"${APACHE_GROUP}" "${PROJECT_DIR}/WebApp"
find "${PROJECT_DIR}/WebApp" -type d -exec chmod 0755 {} \;
find "${PROJECT_DIR}/WebApp" -type f -exec chmod 0644 {} \;
chown -R "${APACHE_USER}":"${APACHE_GROUP}" "${WEB_ROOT}/uploads" "${LOG_DIR}"
find "${WEB_ROOT}/uploads" -type d -exec chmod 0755 {} \;
find "${WEB_ROOT}/uploads" -type f -exec chmod 0644 {} \;
chmod 0700 "${LOG_DIR}"

# Write the Apache virtual host. The project root is denied; only WebApp is public.
if [[ "${ENABLE_HTTPS}" == "1" ]]; then
  [[ -f "${SSL_CERT_FILE}" ]] || { echo "Missing SSL certificate: ${SSL_CERT_FILE}" >&2; exit 1; }
  [[ -f "${SSL_KEY_FILE}" ]] || { echo "Missing SSL private key: ${SSL_KEY_FILE}" >&2; exit 1; }
  cat > "${SITE_AVAILABLE}" <<APACHECONF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    Redirect permanent / https://${SERVER_NAME}/
</VirtualHost>

<VirtualHost *:443>
    ServerName ${SERVER_NAME}
    DocumentRoot ${WEB_ROOT}

    SSLEngine on
    SSLCertificateFile ${SSL_CERT_FILE}
    SSLCertificateKeyFile ${SSL_KEY_FILE}

    ErrorLog \${APACHE_LOG_DIR}/nightwatch_error.log
    CustomLog \${APACHE_LOG_DIR}/nightwatch_access.log combined

    <Directory ${PROJECT_DIR}>
        Require all denied
    </Directory>

    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        LimitRequestBody 1048576
    </Directory>

    <Directory ${WEB_ROOT}/uploads>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
APACHECONF
else
  cat > "${SITE_AVAILABLE}" <<APACHECONF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    DocumentRoot ${WEB_ROOT}

    ErrorLog \${APACHE_LOG_DIR}/nightwatch_error.log
    CustomLog \${APACHE_LOG_DIR}/nightwatch_access.log combined

    <Directory ${PROJECT_DIR}>
        Require all denied
    </Directory>

    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        LimitRequestBody 1048576
    </Directory>

    <Directory ${WEB_ROOT}/uploads>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
APACHECONF
  echo "WARNING: ENABLE_HTTPS=0. Production login/register endpoints require HTTPS." >&2
fi

# Optionally provide runtime environment variables to Apache when they are present.
ENV_CONF="/etc/apache2/conf-available/nightwatch-env.conf"

# Quote Apache SetEnv values so spaces and most special characters survive safely.
apache_setenv() {
  local key="$1"
  local value="$2"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  printf 'SetEnv %s "%s"\n' "${key}" "${value}"
}

{
  echo "# Optional NightWatch environment variables generated by setup.sh"
  [[ -n "${NIGHTWATCH_DB_HOST:-}" ]] && apache_setenv NIGHTWATCH_DB_HOST "${NIGHTWATCH_DB_HOST}"
  [[ -n "${NIGHTWATCH_DB_USER:-}" ]] && apache_setenv NIGHTWATCH_DB_USER "${NIGHTWATCH_DB_USER}"
  [[ -n "${NIGHTWATCH_DB_PASSWORD:-}" ]] && apache_setenv NIGHTWATCH_DB_PASSWORD "${NIGHTWATCH_DB_PASSWORD}"
  [[ -n "${NIGHTWATCH_DB_NAME:-}" ]] && apache_setenv NIGHTWATCH_DB_NAME "${NIGHTWATCH_DB_NAME}"
  [[ -n "${SMTP_HOST:-}" ]] && apache_setenv SMTP_HOST "${SMTP_HOST}"
  [[ -n "${SMTP_PORT:-}" ]] && apache_setenv SMTP_PORT "${SMTP_PORT}"
  [[ -n "${SMTP_USERNAME:-}" ]] && apache_setenv SMTP_USERNAME "${SMTP_USERNAME}"
  [[ -n "${SMTP_PASSWORD:-}" ]] && apache_setenv SMTP_PASSWORD "${SMTP_PASSWORD}"
  [[ -n "${SMTP_ENCRYPTION:-}" ]] && apache_setenv SMTP_ENCRYPTION "${SMTP_ENCRYPTION}"
  [[ -n "${MAIL_FROM_ADDRESS:-}" ]] && apache_setenv MAIL_FROM_ADDRESS "${MAIL_FROM_ADDRESS}"
  [[ -n "${MAIL_FROM_NAME:-}" ]] && apache_setenv MAIL_FROM_NAME "${MAIL_FROM_NAME}"
  [[ -n "${NIGHTWATCH_TRUSTED_PROXIES:-}" ]] && apache_setenv NIGHTWATCH_TRUSTED_PROXIES "${NIGHTWATCH_TRUSTED_PROXIES}"
} > "${ENV_CONF}"
chown root:"${APACHE_GROUP}" "${ENV_CONF}"
chmod 0640 "${ENV_CONF}"
a2enconf nightwatch-env >/dev/null

# Enable the site after the configuration files have been written.
a2ensite "${SITE_NAME}" >/dev/null

# Optionally import the schema using a root/admin MySQL account.
if [[ "${RUN_DB_IMPORT}" == "1" ]]; then
  [[ -n "${NIGHTWATCH_DB_PASSWORD:-}" ]] || { echo "Set NIGHTWATCH_DB_PASSWORD before RUN_DB_IMPORT=1." >&2; exit 1; }
  TMP_SQL="$(mktemp)"
  python3 - "${DB_SQL}" "${TMP_SQL}" <<'PYSQL'
import os
import pathlib
import sys

source = pathlib.Path(sys.argv[1])
destination = pathlib.Path(sys.argv[2])
password = os.environ['NIGHTWATCH_DB_PASSWORD']
destination.write_text(source.read_text().replace('CHANGE_ME_STRONG_PASSWORD', password))
PYSQL
  mysql -u root -p < "${TMP_SQL}"
  rm -f "${TMP_SQL}"
fi

# Validate Apache configuration before reloading the service.
apache2ctl configtest
systemctl reload apache2

# Print the important paths so the deployer can verify them quickly.
echo "NightWatch Apache setup completed."
echo "Project root: ${PROJECT_DIR}"
echo "Document root: ${WEB_ROOT}"
echo "DB config: ${CONFIG_DEST}"
echo "Uploads: ${WEB_ROOT}/uploads/photos"
echo "Logs: ${LOG_DIR}"
