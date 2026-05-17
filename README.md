# NightWatch PHP Web Application

**Last updated:** 2026-05-16  
**Stack:** PHP 8.x, MySQL/MariaDB, Apache, HTML/CSS/JavaScript  
**Document root:** `WebApp/`

NightWatch is a PHP/MySQL web application for publishing ethical-hacking and security-learning writeups. The browser serves static pages and assets from `WebApp/`, while PHP JSON endpoints under `WebApp/functions/` handle authentication, sessions, email verification, password reset, writeup publishing, profile-photo uploads, rate limiting, security logging, and database access.

> **Important:** The Apache/Nginx document root must point to `WebApp/`, not the repository root. The frontend uses root-relative paths such as `/functions/login.php`, `/assets/nightwatch_symbol.png`, `/login`, `/register`, and `/panel`.

---

## Table of contents

1. [Project status](#project-status)
2. [Production assumptions](#production-assumptions)
3. [Directory layout](#directory-layout)
4. [Application architecture](#application-architecture)
5. [Requirements](#requirements)
6. [Environment variables](#environment-variables)
7. [Production deployment with Apache](#production-deployment-with-apache)
8. [Database setup](#database-setup)
9. [File and directory permissions](#file-and-directory-permissions)
10. [Email/SMTP setup](#emailsmtp-setup)
11. [Security model](#security-model)
12. [User flows](#user-flows)
13. [API endpoint reference](#api-endpoint-reference)
14. [Database schema reference](#database-schema-reference)
15. [Operational commands](#operational-commands)
16. [Testing and validation](#testing-and-validation)
17. [Troubleshooting](#troubleshooting)
18. [Production checklist](#production-checklist)
19. [Production operations follow-up](#production-operations-follow-up)

---

## Project status

This README has been audited against the current project files, PHP endpoints, frontend routes, setup scripts, database schema, tests, and operational scripts.

The current implementation is designed around:

- Apache serving `WebApp/` as the public document root.
- PHP endpoints under `WebApp/functions/` returning JSON.
- MySQL/MariaDB storing users, writeups, session token hashes, one-time-code hashes, and rate-limit windows.
- SMTP-based outgoing mail for registration verification, password resets, and email-change verification.
- Profile-photo uploads being re-encoded, center-cropped, metadata-stripped, stored outside the public web root in `storage/photos/`, and served through `WebApp/functions/photo.php` unless an optional HTTPS public photo base URL is configured.
- Structured JSONL security logs stored outside the public web root, with a forwarding hook in `ops/security-log-forwarder.php`.
- Admin moderation endpoints and a real admin dashboard under `WebApp/admin/`.
- Operational scripts for permission-restricted backups, restore drills, Docker Compose E2E tests, and security-log forwarding.
- Environment variables for secrets, moderation behavior, proxy trust, private photo storage, optional public photo URL delivery, backups, restore drills, and log forwarding.

### Implemented hardening and feature improvements in this build

The application-code and project-scaffolding backlog has been implemented in this project:

- CSRF token endpoint and `X-CSRF-Token` enforcement on state-changing endpoints.
- Same-origin checks for state-changing POST requests.
- Private profile-photo storage outside `WebApp/`, controlled photo serving through `/functions/photo.php`, upload re-encoding, metadata stripping, and center-cropping.
- Default profile image for all users using `WebApp/assets/nightwatch_symbol.png`.
- Structured JSONL security logging.
- Progressive account lockout after repeated bad logins.
- Email-change Account section in the panel plus request and verification endpoints.
- Admin moderation endpoints and `/admin` dashboard UI for pending writeups.
- Draft writeup support, categories, tags, pagination metadata, and Markdown policy enforcement.
- Database migrations, Docker Compose local development, Composer metadata, CI workflows, and automated helper/endpoint contract tests.
- User profile page at `/users/?username=<username>`.
- `sitemap.xml`, `robots.txt`, and Open Graph metadata.
- Docker Compose browser E2E test harness.
- Backup automation, restore-drill script, and JSONL security-log forwarder.
- Inline app scripts/styles and runtime `element.style` mutations moved to static files or safe DOM attributes so the app CSP does not require `unsafe-inline`.
- Optional `NIGHTWATCH_PHOTO_PUBLIC_BASE_URL` support for externally delivered profile-photo URLs. This is a URL-generation hook only; you still need to provision and sync a real object store/CDN if you choose to use one.

## Production assumptions

The instructions below assume:

```text
Application path:      /var/www/NightWatch
Public document root:  /var/www/NightWatch/WebApp
Apache user/group:     www-data:www-data
Config directory:      /var/www/config
Log directory:         /var/www/NightWatch/logs
Legacy upload dir:     /var/www/NightWatch/WebApp/uploads/photos
Private photo storage:  /var/www/NightWatch/storage/photos
Database name:         NightWatchDB
Database app user:     nightwatch_app
```

If your paths differ, update `PROJECT_DIR`, Apache virtual host paths, and environment variables accordingly.

---

## Directory layout

```text
NightWatch/
├── .htaccess
│   └── Denies access if the repository root is accidentally used as document root.
├── DB/
│   ├── DB.sql
│   │   └── Creates/updates database, app user, tables, and indexes.
│   ├── db_config.php
│   │   └── Reads NIGHTWATCH_DB_* environment variables with safe defaults.
│   ├── migrate.php
│   │   └── CLI migration runner for DB/migrations/*.sql.
│   └── migrations/
│       └── Incremental SQL migrations for existing installs.
│
├── WebApp/
│   ├── .htaccess
│   │   └── Apache security headers, directory handling, and public routing rules.
│   ├── assets/
│   │   └── Static images/icons such as nightwatch_symbol.png.
│   ├── favicon.ico
│   ├── index.html
│   │   └── Public landing page, latest featured writeup, and writeup feed.
│   ├── js/
│   │   └── auth.js
│   │       └── Shared frontend session/logout/auth-fetch helper.
│   ├── login/
│   │   └── Login page.
│   ├── register/
│   │   ├── index.html
│   │   └── verify/
│   │       └── Email verification page.
│   ├── forgetpassword/
│   │   └── Password reset page.
│   ├── panel/
│   │   └── Authenticated user panel and writeup composer.
│   ├── admin/
│   │   └── Administrator dashboard for writeup moderation.
│   ├── functions/
│   │   ├── common.php
│   │   ├── app_logging.php
│   │   ├── mail_config.php
│   │   ├── mailer.php
│   │   ├── csrf.php
│   │   ├── photo.php
│   │   ├── register.php
│   │   ├── verify_email.php
│   │   ├── resend_verification.php
│   │   ├── login.php
│   │   ├── logout.php
│   │   ├── session.php
│   │   ├── panel.php
│   │   ├── get_writeups.php
│   │   ├── get_user_profile.php
│   │   ├── create_writeup.php
│   │   ├── upload_photo.php
│   │   ├── get_users.php
│   │   ├── forget_password.php
│   │   ├── reset_password.php
│   │   ├── request_email_change.php
│   │   ├── verify_email_change.php
│   │   ├── admin_writeups.php
│   │   └── moderate_writeup.php
│   ├── users/
│   │   └── Public user profile page.
│   ├── robots.txt
│   ├── sitemap.xml
│   └── uploads/
│       └── photos/
│           └── Legacy profile-photo directory only.
│
├── storage/
│   └── photos/
│       └── Private profile-photo storage outside the document root.
│
├── tests/
│   ├── Helper and endpoint contract tests.
│   └── e2e/
│       └── Browser-driven Playwright tests for Docker Compose.
│
├── scripts/
│   ├── backup.sh
│   ├── restore-drill.sh
│   └── e2e-docker.sh
│
├── ops/
│   └── security-log-forwarder.php
│
├── package.json
├── playwright.config.js
│
├── logs/
│   └── Created at runtime by setup.sh for structured JSONL security logs. Must stay outside the public document root.
│
└── README.md
```

---

## Application architecture

### Request flow

```text
Browser
  │
  ├── GET /, /login, /register, /panel, /admin, /users/
  │      └── Static HTML/CSS/JS from WebApp/
  │
  ├── GET /functions/session.php
  │      └── Checks HttpOnly auth cookie and database token hash.
  │
  ├── POST /functions/login.php
  │      └── Validates credentials, rate limit, email verification, session token.
  │
  ├── POST /functions/register.php
  │      └── Creates unverified user and sends verification code.
  │
  ├── POST /functions/create_writeup.php
  │      └── Authenticated writeup creation.
  │
  └── POST /functions/upload_photo.php
         └── Authenticated profile-photo upload.
```

### Backend design

The PHP backend is intentionally simple:

- No framework.
- JSON endpoints only.
- Shared helpers live in `WebApp/functions/common.php`.
- Database connection logic lives in `WebApp/functions/common.php` and reads `DB/db_config.php`.
- Email configuration lives in `WebApp/functions/mail_config.php`.
- SMTP delivery lives in `WebApp/functions/mailer.php`.
- Security logging lives in `WebApp/functions/app_logging.php`.

### Data storage

MySQL/MariaDB stores:

- User accounts.
- Password hashes.
- Email verification codes.
- Password reset codes.
- Session token hashes.
- Writeups.
- Rate-limit counters.

Uploaded profile photos are stored on disk. Only the relative path is stored in the database.

---

## Requirements

### Server packages

Recommended Ubuntu/Apache baseline:

```bash
sudo apt update
sudo apt install -y \
  apache2 \
  mysql-server \
  php \
  php-cli \
  php-mysql \
  php-mbstring \
  php-curl \
  php-xml \
  php-gd \
  php-zip \
  unzip \
  rsync \
  openssl \
  python3
```

Optional but recommended for production HTTPS:

```bash
sudo apt install -y certbot python3-certbot-apache
```

Optional for local/browser tests:

```bash
sudo apt install -y nodejs npm docker.io docker-compose-plugin
```

### PHP extensions

Required by the current PHP code or Composer metadata:

```text
mysqli / pdo_mysql    Database access. The app uses mysqli directly.
json                  JSON responses.
fileinfo              MIME detection for uploads.
gd                    Image decoding, re-encoding, and square cropping.
session               PHP session cookie used for CSRF/session state.
openssl               Secure random bytes, hashing support, and SMTP TLS/SSL.
mbstring              UTF-8-safe helpers when available.
```

Check loaded modules:

```bash
php -m | grep -E 'mysqli|pdo_mysql|json|fileinfo|gd|session|openssl|mbstring'
```

### Apache modules

Enable the modules used by setup and deployment:

```bash
sudo a2enmod rewrite headers ssl
sudo systemctl reload apache2
```

Current usage:

```text
headers    Required for CSP and other security headers from WebApp/.htaccess.
ssl        Required for the HTTPS virtual host.
rewrite    Enabled by setup for route compatibility/future rewrites; the current WebApp/.htaccess does not depend on custom RewriteRule entries.
```

## Environment variables

Use environment variables for secrets and deployment-specific values. Do not hard-code production secrets into PHP files.

### Database variables

```bash
export NIGHTWATCH_DB_HOST='localhost'
export NIGHTWATCH_DB_USER='nightwatch_app'
export NIGHTWATCH_DB_PASSWORD='replace-with-a-strong-password'
export NIGHTWATCH_DB_NAME='NightWatchDB'
```

`DB/db_config.php` reads these variables. If `NIGHTWATCH_DB_PASSWORD` is not set, the fallback is `CHANGE_ME_STRONG_PASSWORD`; production requests fail with that placeholder unless `NIGHTWATCH_ALLOW_INSECURE_DEFAULTS=1` is explicitly set.

### SMTP variables

```bash
export SMTP_HOST='smtp.example.com'
export SMTP_PORT='587'
export SMTP_USERNAME='noreply@example.com'
export SMTP_PASSWORD='replace-with-your-smtp-password'
export SMTP_ENCRYPTION='tls'       # tls, ssl, or none for local Mailpit-style testing
export SMTP_VERIFY_CERT='true'
export SMTP_REQUIRE_AUTH='true'    # set to 0 only for trusted local SMTP catchers
export MAIL_FROM_ADDRESS='noreply@example.com'
export MAIL_FROM_NAME='NightWatch System'
```

Optional timeout overrides:

```bash
export EMAIL_VERIFICATION_TIMEOUT='900'
export PASSWORD_RESET_TIMEOUT='3600'
```

### Security/runtime variables

```bash
export NIGHTWATCH_PHOTO_STORAGE_DIR='/var/www/NightWatch/storage/photos'
export NIGHTWATCH_PHOTO_PUBLIC_BASE_URL=''        # optional HTTPS public URL base for already-synced photos
export NIGHTWATCH_ALLOW_INSECURE_PHOTO_BASE_URL='0'
export NIGHTWATCH_REQUIRE_MODERATION='0'
export NIGHTWATCH_TRUSTED_PROXIES=''              # comma-separated REMOTE_ADDR values allowed to set X-Forwarded-Proto/For
export NIGHTWATCH_ALLOW_INSECURE_DEFAULTS='0'
```

Keep `NIGHTWATCH_ALLOW_INSECURE_DEFAULTS=0` in production. Setting it to `1` allows placeholder database credentials and should only be used for isolated local experiments.

`NIGHTWATCH_PHOTO_PUBLIC_BASE_URL` changes returned photo URLs from `/functions/photo.php?file=...` to `https://your-photo-host/<filename>`. The application does not upload to that external service by itself; you must provision and sync object-store/CDN content separately.

### Operational variables

```bash
export NIGHTWATCH_ENV='production'
export NIGHTWATCH_LOG_DIR='/var/www/NightWatch/logs'
export NIGHTWATCH_BACKUP_DIR='/var/backups/nightwatch'
export NIGHTWATCH_BACKUP_RETENTION_DAYS='14'
export NIGHTWATCH_RESTORE_DB_USER='root'
export NIGHTWATCH_RESTORE_DB_PASSWORD='replace-with-restore-user-password'
export NIGHTWATCH_ALERT_WEBHOOK_URL='https://alerts.example.com/nightwatch'
export NIGHTWATCH_ALERT_LEVELS='WARNING,ERROR,AUTH_FAIL,MAIL_FAIL'
export NIGHTWATCH_LOG_FORWARDER_STATE_DIR='/var/lib/nightwatch/log-forwarder'
```

Keep alert webhooks on HTTPS. Use `NIGHTWATCH_ALLOW_INSECURE_WEBHOOK=1` only for isolated local testing.

### Deployment variables used by `setup.sh`

```bash
export PROJECT_DIR='/var/www/NightWatch'
export WEB_ROOT='/var/www/NightWatch/WebApp'
export CONFIG_DIR='/var/www/config'
export LOG_DIR='/var/www/NightWatch/logs'
export APACHE_USER='www-data'
export APACHE_GROUP='www-data'
export SERVER_NAME='example.com'
export ENABLE_HTTPS='1'
export NIGHTWATCH_PHOTO_STORAGE_DIR='/var/www/NightWatch/storage/photos'
export NIGHTWATCH_PHOTO_PUBLIC_BASE_URL=''
export NIGHTWATCH_REQUIRE_MODERATION='0'
export NIGHTWATCH_TRUSTED_PROXIES=''
export NIGHTWATCH_ALLOW_INSECURE_DEFAULTS='0'
```

`setup.sh` writes Apache environment values to:

```text
/etc/apache2/conf-available/nightwatch-env.conf
```

and enables it with:

```bash
sudo a2enconf nightwatch-env
```

### Manual Apache environment file option

If you are not using `setup.sh`, create the Apache environment file yourself:

```bash
sudo nano /etc/apache2/conf-available/nightwatch-env.conf
sudo chown root:www-data /etc/apache2/conf-available/nightwatch-env.conf
sudo chmod 640 /etc/apache2/conf-available/nightwatch-env.conf
sudo a2enconf nightwatch-env
sudo systemctl reload apache2
```

Example `/etc/apache2/conf-available/nightwatch-env.conf`:

```apache
SetEnv NIGHTWATCH_DB_HOST "localhost"
SetEnv NIGHTWATCH_DB_USER "nightwatch_app"
SetEnv NIGHTWATCH_DB_PASSWORD "replace-with-a-strong-password"
SetEnv NIGHTWATCH_DB_NAME "NightWatchDB"

SetEnv SMTP_HOST "smtp.example.com"
SetEnv SMTP_PORT "587"
SetEnv SMTP_USERNAME "noreply@example.com"
SetEnv SMTP_PASSWORD "replace-with-your-smtp-password"
SetEnv SMTP_ENCRYPTION "tls"
SetEnv SMTP_VERIFY_CERT "true"
SetEnv SMTP_REQUIRE_AUTH "true"
SetEnv MAIL_FROM_ADDRESS "noreply@example.com"
SetEnv MAIL_FROM_NAME "NightWatch System"

SetEnv NIGHTWATCH_LOG_DIR "/var/www/NightWatch/logs"
SetEnv NIGHTWATCH_PHOTO_STORAGE_DIR "/var/www/NightWatch/storage/photos"
SetEnv NIGHTWATCH_PHOTO_PUBLIC_BASE_URL ""
SetEnv NIGHTWATCH_REQUIRE_MODERATION "0"
SetEnv NIGHTWATCH_TRUSTED_PROXIES ""
SetEnv NIGHTWATCH_ALLOW_INSECURE_DEFAULTS "0"
```

## Production deployment with Apache

This section is a step-by-step Ubuntu runbook for serving NightWatch with Apache.
The safest setup is:

```text
Repository/application root: /var/www/NightWatch
Apache document root:       /var/www/NightWatch/WebApp
Private DB config:          /var/www/config/db_config.php
Private profile photos:     /var/www/NightWatch/storage/photos
Private JSONL logs:         /var/www/NightWatch/logs
Apache user/group:          www-data:www-data
```

Never point Apache at `/var/www/NightWatch` itself. Only `WebApp/` should be public.
The repository root contains database files, scripts, logs, and private storage that must not be web-accessible.

### Ubuntu + Apache quick start for local testing

Use this path when you want to run the app on an Ubuntu machine at `http://localhost`.
For a real domain or public server, continue with the production HTTPS steps below.

#### 1. Install Ubuntu packages

```bash
sudo apt update
sudo apt install -y \
  apache2 \
  mysql-server \
  php \
  php-cli \
  php-mysql \
  php-mbstring \
  php-curl \
  php-xml \
  php-gd \
  php-zip \
  unzip \
  rsync \
  openssl \
  python3
```

Enable the Apache modules used by the app:

```bash
sudo a2enmod rewrite headers ssl
sudo systemctl reload apache2
```

Confirm PHP is available:

```bash
php -v
php -m | grep -E 'mysqli|pdo_mysql|mbstring|fileinfo|gd|openssl|json|session'
```

#### 2. Copy the project into `/var/www/NightWatch`

From the directory that contains the `NightWatch/` project folder:

```bash
sudo mkdir -p /var/www/NightWatch
sudo rsync -av --delete ./NightWatch/ /var/www/NightWatch/
sudo chown -R root:root /var/www/NightWatch
```

If you downloaded a zip file, unzip it first and then run the same `rsync` command from the extracted folder.

#### 3. Generate a strong database password

```bash
DB_PASS="$(openssl rand -base64 32)"
echo "$DB_PASS"
```

Save this value somewhere secure. You need the same value for the database user and Apache environment. If you close the terminal before finishing deployment, export `DB_PASS` again before continuing.

#### 4. Create the database and app database user

Create a temporary SQL file from `DB/DB.sql` with the generated password inserted:

```bash
cd /var/www/NightWatch
DB_PASS="$DB_PASS" python3 - <<'PY'
import os
from pathlib import Path
source = Path('/var/www/NightWatch/DB/DB.sql')
dest = Path('/tmp/nightwatch-db.sql')
dest.write_text(source.read_text().replace('CHANGE_ME_STRONG_PASSWORD', os.environ['DB_PASS']))
PY
mysql -u root -p < /tmp/nightwatch-db.sql
sudo rm -f /tmp/nightwatch-db.sql
```

Confirm the database exists:

```bash
mysql -u root -p -e "SHOW DATABASES LIKE 'NightWatchDB';"
mysql -u root -p -e "USE NightWatchDB; SHOW TABLES;"
```

#### 5. Run the Apache setup helper for localhost

For local testing, use `SERVER_NAME=localhost` and `ENABLE_HTTPS=0`.
The app allows HTTP only for localhost-style development requests.

```bash
cd /var/www/NightWatch

sudo \
  SERVER_NAME='localhost' \
  PROJECT_DIR='/var/www/NightWatch' \
  WEB_ROOT='/var/www/NightWatch/WebApp' \
  CONFIG_DIR='/var/www/config' \
  LOG_DIR='/var/www/NightWatch/logs' \
  NIGHTWATCH_PHOTO_STORAGE_DIR='/var/www/NightWatch/storage/photos' \
  NIGHTWATCH_DB_HOST='localhost' \
  NIGHTWATCH_DB_USER='nightwatch_app' \
  NIGHTWATCH_DB_PASSWORD="$DB_PASS" \
  NIGHTWATCH_DB_NAME='NightWatchDB' \
  NIGHTWATCH_REQUIRE_MODERATION='0' \
  NIGHTWATCH_ALLOW_INSECURE_DEFAULTS='0' \
  ENABLE_HTTPS='0' \
  ./setup.sh
```

Disable the default Apache site if it takes precedence over NightWatch:

```bash
sudo a2dissite 000-default.conf
sudo a2ensite nightwatch.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

#### 6. Open the app

Open:

```text
http://localhost/
```

Quick checks:

```bash
curl -I http://localhost/
curl -i http://localhost/functions/session.php
```

You should receive the homepage for `/` and a JSON response from `/functions/session.php`.

#### 7. Configure email before using registration flows

Registration, email verification, password reset, and email-change verification need SMTP.
For local testing, either configure real SMTP values or use a local SMTP catcher.
The app reads SMTP values from Apache environment variables generated by `setup.sh`.

Example with a real SMTP provider:

```bash
sudo \
  SERVER_NAME='localhost' \
  PROJECT_DIR='/var/www/NightWatch' \
  NIGHTWATCH_DB_PASSWORD="$DB_PASS" \
  SMTP_HOST='smtp.example.com' \
  SMTP_PORT='587' \
  SMTP_USERNAME='noreply@example.com' \
  SMTP_PASSWORD='replace-with-your-smtp-password' \
  SMTP_ENCRYPTION='tls' \
  SMTP_REQUIRE_AUTH='true' \
  MAIL_FROM_ADDRESS='noreply@example.com' \
  MAIL_FROM_NAME='NightWatch System' \
  ENABLE_HTTPS='0' \
  ./setup.sh
```

After changing environment variables, always reload Apache:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

#### 8. Create the first admin user

Register the first user through the UI. After the email is verified, promote that user from MySQL:

```bash
mysql -u root -p
```

```sql
USE NightWatchDB;
UPDATE users SET is_admin = 1 WHERE email = 'your-email@example.com';
```

Then log out and log back in. The admin moderation dashboard is available at:

```text
http://localhost/admin
```

### Production Ubuntu + Apache deployment with HTTPS

Use this path for a public server or real domain. Public deployments should use HTTPS.
Most JSON endpoints reject plain HTTP unless the request is local development.

#### 1. Point DNS to the server

Create an `A` or `AAAA` record for your domain, for example:

```text
example.com -> your server public IP
```

Wait until the domain resolves to the server before requesting a certificate.

#### 2. Install packages and copy project files

Run the same package installation and `rsync` steps from the local quick start.
Use your real domain in later commands instead of `localhost`.

#### 3. Initialize the database

Run the same database initialization steps from the local quick start.
Keep the generated database password private.

#### 4. Create the initial HTTP Apache site

Run setup once with HTTP so Certbot can validate the domain:

```bash
cd /var/www/NightWatch

sudo \
  SERVER_NAME='example.com' \
  PROJECT_DIR='/var/www/NightWatch' \
  WEB_ROOT='/var/www/NightWatch/WebApp' \
  CONFIG_DIR='/var/www/config' \
  LOG_DIR='/var/www/NightWatch/logs' \
  NIGHTWATCH_PHOTO_STORAGE_DIR='/var/www/NightWatch/storage/photos' \
  NIGHTWATCH_DB_HOST='localhost' \
  NIGHTWATCH_DB_USER='nightwatch_app' \
  NIGHTWATCH_DB_PASSWORD="$DB_PASS" \
  NIGHTWATCH_DB_NAME='NightWatchDB' \
  NIGHTWATCH_REQUIRE_MODERATION='0' \
  NIGHTWATCH_ALLOW_INSECURE_DEFAULTS='0' \
  ENABLE_HTTPS='0' \
  ./setup.sh
```

Validate Apache:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

#### 5. Issue an HTTPS certificate

Install Certbot if it is not already installed:

```bash
sudo apt install -y certbot python3-certbot-apache
```

Request a certificate:

```bash
sudo certbot --apache -d example.com
```

Certbot stores certificates under:

```text
/etc/letsencrypt/live/example.com/fullchain.pem
/etc/letsencrypt/live/example.com/privkey.pem
```

#### 6. Re-run setup with HTTPS enabled

After the certificate exists, re-run the setup helper with HTTPS enabled:

```bash
cd /var/www/NightWatch

sudo \
  SERVER_NAME='example.com' \
  PROJECT_DIR='/var/www/NightWatch' \
  WEB_ROOT='/var/www/NightWatch/WebApp' \
  CONFIG_DIR='/var/www/config' \
  LOG_DIR='/var/www/NightWatch/logs' \
  NIGHTWATCH_PHOTO_STORAGE_DIR='/var/www/NightWatch/storage/photos' \
  NIGHTWATCH_DB_HOST='localhost' \
  NIGHTWATCH_DB_USER='nightwatch_app' \
  NIGHTWATCH_DB_PASSWORD="$DB_PASS" \
  NIGHTWATCH_DB_NAME='NightWatchDB' \
  SMTP_HOST='smtp.example.com' \
  SMTP_PORT='587' \
  SMTP_USERNAME='noreply@example.com' \
  SMTP_PASSWORD='replace-with-your-smtp-password' \
  SMTP_ENCRYPTION='tls' \
  SMTP_REQUIRE_AUTH='true' \
  MAIL_FROM_ADDRESS='noreply@example.com' \
  MAIL_FROM_NAME='NightWatch System' \
  NIGHTWATCH_REQUIRE_MODERATION='0' \
  NIGHTWATCH_ALLOW_INSECURE_DEFAULTS='0' \
  ENABLE_HTTPS='1' \
  ./setup.sh
```

The generated Apache config redirects HTTP to HTTPS and serves the app from `/var/www/NightWatch/WebApp`.

#### 7. Verify the production site

```bash
curl -I https://example.com/
curl -i https://example.com/functions/session.php
sudo tail -n 100 /var/log/apache2/nightwatch_error.log
sudo tail -n 100 /var/www/NightWatch/logs/security_*.jsonl 2>/dev/null || true
```

Open these URLs in a browser:

```text
https://example.com/
https://example.com/register
https://example.com/login
https://example.com/panel
https://example.com/admin
```

#### 8. Lock down permissions after deploy

```bash
sudo chown -R root:root /var/www/NightWatch
sudo chown -R www-data:www-data /var/www/NightWatch/logs
sudo chown -R www-data:www-data /var/www/NightWatch/storage/photos
sudo chown -R www-data:www-data /var/www/NightWatch/WebApp/uploads
sudo chmod 700 /var/www/NightWatch/logs
sudo chmod 750 /var/www/NightWatch/storage/photos
sudo chown root:www-data /var/www/config/db_config.php
sudo chmod 640 /var/www/config/db_config.php
```

Re-run `setup.sh` after permission changes if you want it to regenerate the Apache site and hardening files.

#### 9. Create and promote the production admin account

Register normally through the website and verify the email. Then promote the account:

```bash
mysql -u root -p
```

```sql
USE NightWatchDB;
UPDATE users SET is_admin = 1 WHERE email = 'admin@example.com';
```

The admin dashboard is available at:

```text
https://example.com/admin
```

### Manual Apache virtual host reference

Use this only if you do not want `setup.sh` to generate `/etc/apache2/sites-available/nightwatch.conf`.
For most installs, `setup.sh` is safer because it also creates directories, permissions, environment variables, and upload hardening files.

Example HTTP virtual host:

```apache
<VirtualHost *:80>
    ServerName example.com

    DocumentRoot /var/www/NightWatch/WebApp
    IncludeOptional /etc/apache2/conf-enabled/nightwatch-env.conf

    <Directory /var/www/NightWatch>
        Require all denied
    </Directory>

    <Directory /var/www/NightWatch/WebApp>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        LimitRequestBody 1048576
    </Directory>

    <Directory /var/www/NightWatch/WebApp/uploads>
        Options -Indexes
        AllowOverride All
        Require all granted

        <FilesMatch "\.(php|phtml|php[0-9]?|phar)$">
            Require all denied
        </FilesMatch>
    </Directory>

    <Directory /var/www/NightWatch/storage>
        Require all denied
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/nightwatch_error.log
    CustomLog ${APACHE_LOG_DIR}/nightwatch_access.log combined
</VirtualHost>
```

Enable it:

```bash
sudo a2ensite nightwatch.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### Common Ubuntu Apache commands

```bash
# Check Apache configuration
sudo apache2ctl configtest

# Reload Apache without dropping active connections
sudo systemctl reload apache2

# Restart Apache when needed
sudo systemctl restart apache2

# Enable/disable sites
sudo a2ensite nightwatch.conf
sudo a2dissite 000-default.conf

# View logs
sudo tail -f /var/log/apache2/nightwatch_error.log
sudo tail -f /var/log/apache2/nightwatch_access.log

# Confirm loaded modules
apache2ctl -M | grep -E 'rewrite|headers|ssl'
```


---

## Database setup

### 1. Review database SQL before running

Open `DB/DB.sql` and replace placeholder passwords before production:

```sql
CHANGE_ME_STRONG_PASSWORD
```

Use a long random password:

```bash
openssl rand -base64 32
```

### 2. Fresh install database bootstrap

Use a temporary SQL file so the generated password is not committed back into the project:

```bash
cd /var/www/NightWatch
DB_PASS="$(openssl rand -base64 32)"
python3 - <<'PY'
import os
from pathlib import Path
source = Path('DB/DB.sql')
dest = Path('/tmp/nightwatch-db.sql')
dest.write_text(source.read_text().replace('CHANGE_ME_STRONG_PASSWORD', os.environ['DB_PASS']))
PY
mysql -u root -p < /tmp/nightwatch-db.sql
rm -f /tmp/nightwatch-db.sql
```

Then use the same `DB_PASS` value as `NIGHTWATCH_DB_PASSWORD` in Apache/PHP.

### 3. Existing install migrations

For existing installs, run:

```bash
php DB/migrate.php
```

Migration behavior in this project:

```text
DB/migrations/001_initial_schema.sql      Bootstrap marker only. Fresh installs use DB/DB.sql.
DB/migrations/002_security_features.sql   Adds admin/security and writeup feature columns/indexes.
DB/migrations/003_legacy_code_hashing.sql Converts old plaintext 6-digit codes to SHA-256 hashes.
```

The PHP migration runner applies incremental migrations to the configured `NIGHTWATCH_DB_NAME` database. It no longer hard-codes `NightWatchDB` inside incremental migration files, so restore drills can safely run against temporary databases.

### 4. Confirm database user and tables

```bash
mysql -u root -p
```

```sql
SHOW DATABASES LIKE 'NightWatchDB';

USE NightWatchDB;

SHOW TABLES;

SELECT User, Host
FROM mysql.user
WHERE User = 'nightwatch_app';
```

Expected application tables:

```text
users
writeups
rate_limits
```

### 5. Confirm search index

```sql
SHOW INDEX FROM writeups WHERE Key_name = 'idx_writeups_search';
```

If the full-text index is missing, rerun the relevant migration or apply the index manually.

## File and directory permissions

### Recommended ownership model

```text
Application code:      root:root, not writable by Apache
Database config:       root:www-data, 0640
Logs directory:        www-data:www-data, 0700
Private photos:        www-data:www-data, 0750 directories, 0640 files
Legacy uploads:        www-data:www-data, 0755 directories, non-executable
```

### Commands

```bash
sudo chown -R root:root /var/www/NightWatch

sudo mkdir -p /var/www/config
sudo chown root:www-data /var/www/config
sudo chmod 750 /var/www/config

sudo chown root:www-data /var/www/config/db_config.php
sudo chmod 640 /var/www/config/db_config.php

sudo mkdir -p /var/www/NightWatch/logs
sudo chown www-data:www-data /var/www/NightWatch/logs
sudo chmod 700 /var/www/NightWatch/logs

sudo mkdir -p /var/www/NightWatch/storage/photos
sudo chown -R www-data:www-data /var/www/NightWatch/storage/photos
sudo chmod 750 /var/www/NightWatch/storage/photos

sudo mkdir -p /var/www/NightWatch/WebApp/uploads/photos
sudo chown -R www-data:www-data /var/www/NightWatch/WebApp/uploads
sudo find /var/www/NightWatch/WebApp/uploads -type d -exec chmod 755 {} \;
sudo find /var/www/NightWatch/WebApp/uploads -type f -exec chmod 644 {} \;
```

### Profile-photo storage hardening

New profile photos are not stored in the public document root. They are written to:

```text
/var/www/NightWatch/storage/photos
```

The browser receives controlled URLs such as:

```text
/functions/photo.php?file=profile_1_abcd1234.png
```

`photo.php` validates the filename, checks the file remains inside the configured storage directory, verifies the MIME type, and serves only JPEG/PNG bytes.

The legacy `WebApp/uploads/photos/` directory remains only for backward compatibility with older rows. PHP execution is still blocked there with `.htaccess`.

---

## Email/SMTP setup

Registration, verification resend, and password reset require working SMTP credentials.

### Recommended SMTP settings

Most providers support:

```text
Host:       smtp.example.com
Port:       587
Encryption: tls
Auth:       username + password/app-password
```

For SSL mode:

```text
Port:       465
Encryption: ssl
```

### Gmail notes

For Gmail, use an app password, not your normal account password:

1. Enable 2-Step Verification.
2. Generate an App Password.
3. Use the generated 16-character password as `SMTP_PASSWORD`.

### Test SMTP variables from PHP

Create a temporary test file outside the web root or run a CLI test script that includes `mail_config.php` and `mailer.php`. Delete the test file after use.

Never leave public mail-test endpoints on production.

---

## Security model

### Password storage

Passwords are hashed with `PASSWORD_ARGON2ID`. New passwords must be at least 10 characters and include uppercase, lowercase, and a number.

Operational notes:

- Never store plaintext passwords.
- Do not log passwords.
- Do not send passwords by email.
- Consider increasing Argon2id cost parameters only after measuring server performance.

### Session tokens

Session tokens use this pattern:

```text
Browser cookie:   random raw token in nightwatch_token
Database value:   SHA-256 hash of token in users.token
PHP session:      user id, username, email, admin flag, expiry, token hash
```

Benefits:

- Database leak does not directly expose active browser tokens.
- Tokens can be invalidated by clearing the database hash.
- Expiry is enforced on every protected request.

Cookie requirements implemented by `common.php`:

```text
HttpOnly: true
SameSite: Lax
Secure: true when the request is HTTPS
Path: /
```

Default session duration:

```text
Without remember me: 10 minutes
With remember me:    7 days
```

### CSRF and same-origin checks

State-changing endpoints enforce:

- `POST` method.
- Same-origin request checks using `Origin` and/or `Referer`.
- `X-CSRF-Token` header or `csrf_token` form field.
- HTTPS outside localhost-style development.

The frontend helper in `WebApp/js/auth.js` fetches `/functions/csrf.php` and automatically adds the token to `authFetch()` POST requests. The photo upload XHR also sets `X-CSRF-Token`.

### Email verification, password reset, and email-change codes

The app uses 6-digit numeric codes.

Current storage:

```text
Database value: SHA-256 hash of the code
Comparison:     hash_equals()
Email verify:   15 minutes by default
Email change:   15 minutes by default
Password reset: 1 hour by default
```

The code verifier still accepts legacy plaintext 6-digit rows during migration. `DB/migrations/003_legacy_code_hashing.sql` converts those legacy rows to SHA-256 hashes.

### Rate limiting and account lockout

The application uses database-backed per-IP rate limiting. The current implementation records one row per attempt in `rate_limits`; it does not use a unique `(scope, ip_address)` counter row.

Actual endpoint limits in the current PHP code:

| Scope | Limit | Window |
| --- | ---: | --- |
| `login` | 8 attempts | 5 minutes |
| `register` | 3 attempts | 30 minutes |
| `verify_email` | 10 attempts | 5 minutes |
| `resend_verification` | 3 attempts | 15 minutes |
| `forget_password` | 3 attempts | 30 minutes |
| `reset_password` | 5 attempts | 10 minutes |
| `create_writeup` | 20 attempts | 1 hour |
| `upload_photo` | 10 attempts | 1 hour |
| `request_email_change` | 3 attempts | 30 minutes |
| `verify_email_change` | 5 attempts | 15 minutes |

When a rate limit is hit, the server returns HTTP `429` with `Retry-After`.

Login also has per-account progressive lockout after repeated bad passwords:

```text
First lock starts after 5 failed password attempts.
Lock duration grows progressively and caps at 1 hour.
Successful login resets login_failures and locked_until.
```

### HTTPS enforcement

Sensitive JSON endpoints call `enforce_https()` and reject insecure non-local production requests.

Production HTTPS headers:

```text
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: same-origin
```

Only enable HSTS preload after confirming all subdomains support HTTPS.

`X-Forwarded-Proto` and `X-Forwarded-For` are trusted only when the direct peer `REMOTE_ADDR` is listed in `NIGHTWATCH_TRUSTED_PROXIES`.

### Content Security Policy

The current `WebApp/.htaccess` CSP is same-origin only for app code:

```apache
Header always set Content-Security-Policy "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' https: data: blob:; font-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'"
```

Notes:

- App page scripts, inline event handlers, HTML `style` attributes, and runtime `element.style` mutations have been moved into static `.js`/`.css` files or safe DOM attributes such as `src` and `value`.
- Email templates still contain inline email CSS because email-client rendering is separate from the browser CSP.
- Keep external fonts/scripts disabled unless CSP is updated intentionally.
- `upgrade-insecure-requests` is intentionally not enabled in the shared `.htaccess` because it breaks the documented plain-HTTP localhost setup. Use Apache HTTP-to-HTTPS redirects/HSTS for production HTTPS enforcement.
- Do not use broad wildcards such as `*`.

### File upload security

Profile-photo uploads enforce:

```text
Allowed extensions: jpg, jpeg, png
Maximum size:       500 KB
Dimensions:         64x64 to 4096x4096 pixels
Validation layers:  extension + getimagesize() + finfo MIME check
Filename:           random server-generated name
Output:             512x512 re-encoded JPG/PNG square
Storage path:       private storage/photos/ directory outside WebApp
New DB path:        profile_photos/<filename>
Default DB path:    assets/nightwatch_symbol.png
Delivery:           /functions/photo.php?file=<filename>, or optional HTTPS public photo base URL
PHP execution:      blocked by Apache config and .htaccess in legacy upload folders
```

Legacy `uploads/photos/<filename>` paths are still accepted by safe path helpers for old database rows, but new uploads use `profile_photos/<filename>` and private storage.

Never trust the original uploaded filename.

### Logging and audit trail

Security logs include:

- Timestamp.
- Severity.
- Event type/text.
- IP address.
- User ID when available.
- HTTP method and URI.
- Request ID.
- Safe scalar context metadata.

Security logs must not include:

- Passwords.
- SMTP passwords.
- Session tokens.
- Reset codes.
- Verification codes.
- Full uploaded file contents.

Current default location:

```text
/var/www/NightWatch/logs/security_YYYY-MM-DD.jsonl
```

If `NIGHTWATCH_LOG_DIR` is not writable, the logger falls back to a temp directory.

Recommended permissions:

```text
Directory: 0700
Files:     0600
Owner:     www-data:www-data
```

`ops/security-log-forwarder.php` forwards selected JSONL events to an HTTPS webhook or stdout. It tracks file offsets under `storage/log-forwarder/` by default, or `NIGHTWATCH_LOG_FORWARDER_STATE_DIR` when configured.

## User flows

### Registration flow

1. User opens `/register`.
2. Browser loads `/js/auth.js`, requests a CSRF token when submitting, and sends `username`, `email`, and `password`.
3. `POST /functions/register.php` validates username, email, password strength, method, origin, CSRF, HTTPS, and rate limit.
4. Server checks username/email uniqueness. Verified emails cannot be reused. An old unverified row for the same email can be replaced so the user can restart registration.
5. Server hashes the password with Argon2id.
6. Server generates a 6-digit verification code, stores only its SHA-256 hash, and sends the code by SMTP.
7. New user gets `photo_path = assets/nightwatch_symbol.png` and `email_verified = 0`.
8. Browser redirects user to `/register/verify`.

Successful response example:

```json
{
  "success": true,
  "message": "Registration successful! Please check your email to verify your account.",
  "email": "user@example.com",
  "email_sent": true,
  "requires_email_verification": true,
  "redirect": "/register/verify"
}
```

### Email verification flow

1. User opens `/register/verify` after registering. The email field is editable; `sessionStorage` only pre-fills it for convenience, so verification still works after refreshes, new tabs, or another browser.
2. User enters the 6-digit verification code.
3. `POST /functions/verify_email.php` validates input, method, origin, CSRF, HTTPS, and rate limit.
4. Server hashes the submitted code and compares it with the stored value using `hash_equals()` through `nightwatch_verify_one_time_code()`.
5. Server rejects expired codes.
6. Server marks `email_verified = 1` and clears verification-code fields.
7. Server creates an authenticated session and auth token.
8. Browser redirects to `/panel`.

### Resend verification flow

1. User clicks resend code on `/register/verify`.
2. Browser sends `POST /functions/resend_verification.php` with the email.
3. Server validates input, method, origin, CSRF, HTTPS, and rate limit.
4. Unknown emails receive a generic success response to reduce account enumeration.
5. Already verified accounts receive a login redirect response.
6. Unverified accounts receive a fresh code and expiry.
7. Server stores the hashed code and sends the code by SMTP.

### Login flow

1. User opens `/login`.
2. Browser submits username/email, password, and optional `remember_me` to `POST /functions/login.php`.
3. Server validates input, method, origin, CSRF, HTTPS, and IP rate limit.
4. Server loads user by username or email.
5. Server checks account lockout and verifies the password hash.
6. Failed password attempts increment `login_failures`; after 5 failures, progressive lockout begins.
7. Server rejects unverified accounts and returns a verification redirect only after the correct password is provided.
8. The login page pre-fills the verification page email for that valid unverified account, while still requiring the 6-digit email code.
9. For verified accounts, the server creates a random auth token, stores only its SHA-256 hash, stores matching session metadata, and sets the HttpOnly cookie.
10. Browser redirects to `/panel`.

Session duration:

```text
Without remember me: 10 minutes
With remember me:    7 days
```

### Session validation flow

Frontend helper:

```text
WebApp/js/auth.js
```

Backend endpoint:

```text
GET /functions/session.php
```

Validation requires:

1. HttpOnly `nightwatch_token` cookie exists.
2. Token hash matches the database.
3. Token has not expired.
4. User account still exists.
5. User email is verified.

If validation fails, the backend clears the session and returns logged-out status.

### Logout flow

```text
POST /functions/logout.php
```

Logout clears the current database token when available, clears the auth cookie, destroys the PHP session, and returns JSON success.

### Password reset flow

#### Step 1: request reset code

```text
POST /functions/forget_password.php
```

Expected field:

```text
email
```

Behavior:

- Validates method, origin, CSRF, HTTPS, and rate limit.
- Always returns a generic success-style message to reduce account enumeration.
- If the email exists, creates a reset code, stores the hashed code, sets expiry, sends email, and logs the event safely.

#### Step 2: set new password

```text
POST /functions/reset_password.php
```

Expected fields:

```text
email
reset_code
new_password
confirm_password
```

Behavior:

- Validates method, origin, CSRF, HTTPS, and rate limit.
- Validates code format and password strength.
- Compares hashed submitted code using `hash_equals()`.
- Rejects expired code.
- Hashes new password with Argon2id.
- Clears reset fields, active token, login failures, and lockout.
- Returns JSON success response.

### Public writeup feed

```text
GET /functions/get_writeups.php?limit=12
GET /functions/get_writeups.php?limit=12&page=2
GET /functions/get_writeups.php?limit=12&q=search-term
```

Behavior:

- Returns only `published` writeups.
- Powers the homepage feed and latest featured writeup card.
- Supports optional search over title, excerpt, body, category, tags, and username.
- Supports `page` and `limit` pagination. Current `limit` range is 1 to 30.
- Includes safe author/profile-photo data.
- Includes pagination metadata.
- Does not expose private user fields.

### Create writeup flow

```text
POST /functions/create_writeup.php
```

Required fields:

```text
title
content_md
```

Optional fields:

```text
status      # draft or published
category
tags
```

Behavior:

- Requires valid session.
- Validates method, origin, CSRF, HTTPS, and rate limit.
- Validates title length and Markdown body length.
- Rejects active raw HTML elements such as script, iframe, object, embed, form, input, button, style, link, and meta.
- Normalizes category and tags.
- Creates a plain-text excerpt.
- Saves as `draft` when requested.
- Saves as `pending` when `NIGHTWATCH_REQUIRE_MODERATION=1` and the user is not an admin.
- Saves as `published` otherwise.
- Returns new writeup metadata.

### Admin moderation flow

Dashboard:

```text
/admin
```

Endpoints:

```text
GET  /functions/admin_writeups.php?status=pending&limit=50
POST /functions/moderate_writeup.php
```

Behavior:

- Requires a valid verified session with `is_admin = 1`.
- `admin_writeups.php` lists writeups by status: `pending`, `published`, `rejected`, or `draft`.
- `moderate_writeup.php` accepts `writeup_id`, `action=publish|reject`, and optional `reason`.
- Only pending writeups can be moderated.
- Publishing sets `status = published` and updates `published_at`.
- Rejecting sets `status = rejected` and stores the rejection reason.

### Profile-photo upload flow

```text
POST /functions/upload_photo.php
```

Expected multipart field:

```text
photo
```

Behavior:

- Requires valid session.
- Validates method, origin, CSRF, HTTPS, and rate limit.
- Requires PHP GD.
- Enforces file size, extension, dimensions, and MIME/content checks.
- Generates random filename.
- Re-encodes, strips metadata, center-crops to a 512x512 square image, and writes the result to private photo storage.
- Updates database with `profile_photos/<filename>`.
- Deletes old private/legacy profile photo only after database update succeeds.
- Stores only `profile_photos/<filename>` in the database and returns a public delivery URL such as `functions/photo.php?file=profile_1_abcd.png`, or an HTTPS external URL when `NIGHTWATCH_PHOTO_PUBLIC_BASE_URL` is configured.

### Controlled profile-photo delivery flow

```text
GET /functions/photo.php?file=profile_1_abcd.png
```

Behavior:

- Validates the requested filename.
- Serves from private `storage/photos/` first.
- Falls back to legacy `WebApp/uploads/photos/` for old rows.
- Verifies image MIME before serving.
- Adds `ETag`, `Cache-Control`, `Content-Disposition`, and nosniff headers.
- Returns `404` for invalid filenames, missing files, or non-image content.

### Public user profile flow

```text
/users/?username=member_name
GET /functions/get_user_profile.php?username=member_name
```

Behavior:

- Accepts usernames matching `[a-zA-Z0-9_]{3,50}`.
- Returns only verified public profile data.
- Returns the user’s 10 most recent published writeups.
- Does not expose email addresses.

### Authenticated user directory flow

```text
GET /functions/get_users.php?limit=50
```

Behavior:

- Requires a valid session.
- Returns up to 100 verified users with safe public fields: `id`, `username`, `photo_path`, and `created_at`.
- Does not expose email addresses.

### Email change flow

The panel includes an Account section that uses the verified email-change endpoints. A signed-in user enters a new email address and current password, receives a 6-digit confirmation code at the new address, and confirms the change without exposing email-change secrets in the browser.

#### Step 1: request email change

```text
POST /functions/request_email_change.php
```

Expected fields:

```text
new_email
current_password
```

Behavior:

- Requires valid session.
- Validates method, origin, CSRF, HTTPS, and rate limit.
- Verifies current password.
- Rejects the current email and already-used emails.
- Stores `pending_email` and a hashed 6-digit email-change code.
- Sends the confirmation code to the new email address.

#### Step 2: verify email change

```text
POST /functions/verify_email_change.php
```

Expected field:

```text
verification_code
```

Behavior:

- Requires valid session.
- Validates method, origin, CSRF, HTTPS, and rate limit.
- Verifies the 6-digit code and expiry.
- Re-checks that the pending email is still unused.
- Updates `users.email`, clears pending email-change fields, and refreshes the PHP session email.

## API endpoint reference

All endpoints return JSON except `photo.php`, which returns image bytes for valid images and simple HTTP errors for invalid requests.

| Endpoint | Method | Auth required | Purpose |
| --- | --- | --- | --- |
| `/functions/csrf.php` | GET | No | Issue same-origin CSRF token for POST requests. |
| `/functions/register.php` | POST | No | Create unverified account and send verification code. |
| `/functions/verify_email.php` | POST | No | Verify email code and sign user in. |
| `/functions/resend_verification.php` | POST | No | Send a fresh verification code for an unverified account. |
| `/functions/login.php` | POST | No | Verify credentials and create session. |
| `/functions/session.php` | GET | Cookie for success | Check current login state. |
| `/functions/logout.php` | POST | Cookie optional | Clear auth token and PHP session. |
| `/functions/panel.php` | GET | Yes | Load authenticated panel data and latest public writeups. |
| `/functions/get_writeups.php` | GET | No | Load published writeups with optional search and pagination. |
| `/functions/create_writeup.php` | POST | Yes | Save a draft, submit for moderation, or publish a writeup. |
| `/functions/upload_photo.php` | POST | Yes | Upload and re-encode profile photo. |
| `/functions/photo.php` | GET | No | Serve validated private or legacy profile-photo files. |
| `/functions/get_users.php` | GET | Yes | Return authenticated directory of verified users with safe public fields. |
| `/functions/get_user_profile.php` | GET | No | Return one public user profile and recent published writeups. |
| `/functions/forget_password.php` | POST | No | Start password reset flow. |
| `/functions/reset_password.php` | POST | No | Verify reset code and set new password. |
| `/functions/request_email_change.php` | POST | Yes | Start verified email-change flow. |
| `/functions/verify_email_change.php` | POST | Yes | Complete verified email-change flow. |
| `/functions/admin_writeups.php` | GET | Admin | List writeups by moderation status. |
| `/functions/moderate_writeup.php` | POST | Admin | Publish or reject a pending writeup. |

Implementation helper files in `WebApp/functions/` such as `common.php`, `app_logging.php`, `mail_config.php`, and `mailer.php` are included by endpoints and are not browser APIs.

### Common response format

Success:

```json
{
  "success": true,
  "message": "Operation completed."
}
```

Validation or operation error:

```json
{
  "success": false,
  "error": "Invalid input."
}
```

Unauthenticated:

```json
{
  "success": false,
  "error": "Unauthorized"
}
```

Rate limited:

```json
{
  "success": false,
  "error": "Too many attempts. Please wait and try again."
}
```

### Current HTTP status patterns

| Code | Meaning |
| ---: | --- |
| `200` | Successful read or operation. |
| `201` | Writeup created. |
| `400` | Bad request or upload error. |
| `401` | Authentication required or invalid credentials/code. |
| `403` | Forbidden, HTTPS failure, CSRF failure, same-origin failure, or unverified login. |
| `404` | Resource not found. |
| `405` | Wrong HTTP method. |
| `409` | Duplicate username/email or already-verified verification resend. |
| `413` | Uploaded file too large. |
| `415` | Unsupported or mismatched upload type. |
| `422` | Validation error. |
| `429` | Rate limit or account lockout. |
| `500` | Server-side error. |

## Database schema reference

### `users`

Stores account, authentication, verification, reset, lockout, email-change, admin, and profile-photo data.

Important columns:

```text
id                                      INT UNSIGNED PRIMARY KEY
username                                VARCHAR(50) UNIQUE
email                                   VARCHAR(255) UNIQUE
password                                VARCHAR(255)
token                                   CHAR(64)
token_expires_at                        TIMESTAMP NULL
created_at                              TIMESTAMP
photo_path                              VARCHAR(255), defaults to assets/nightwatch_symbol.png
is_admin                                TINYINT(1)
pending_email                           VARCHAR(255)
email_change_code                       CHAR(64)
email_change_code_expires_at            TIMESTAMP NULL
login_failures                          INT UNSIGNED
locked_until                            TIMESTAMP NULL
last_login_at                           TIMESTAMP NULL
email_verified                          TINYINT(1)
email_verification_code                 CHAR(64)
email_verification_code_expires_at      TIMESTAMP NULL
reset_code                              CHAR(64)
reset_code_expires_at                   TIMESTAMP NULL
```

Notes:

- `password` stores an Argon2id hash.
- `token` stores a SHA-256 hash of the browser auth token.
- `email_verification_code`, `reset_code`, and `email_change_code` store SHA-256 hashes of 6-digit codes.
- `photo_path` stores either `assets/nightwatch_symbol.png` for the default symbol or `profile_photos/<filename>` for uploaded private photos. Public API responses convert stored private-photo paths into controlled delivery URLs.

### `writeups`

Stores Markdown writeups in published, draft, pending, or rejected states.

Important columns:

```text
id               BIGINT UNSIGNED PRIMARY KEY
user_id          INT UNSIGNED
title            VARCHAR(140)
content_md       MEDIUMTEXT
excerpt          VARCHAR(280)
status           ENUM('published', 'draft', 'pending', 'rejected')
category         VARCHAR(40)
tags             VARCHAR(255)
moderated_by     INT UNSIGNED
moderated_at     TIMESTAMP NULL
rejection_reason VARCHAR(500)
created_at       TIMESTAMP
updated_at       TIMESTAMP
published_at     TIMESTAMP NULL
```

Current indexes and constraints include:

```text
FOREIGN KEY user_id -> users(id) ON DELETE CASCADE
FOREIGN KEY moderated_by -> users(id) ON DELETE SET NULL
INDEX idx_writeups_feed (status, published_at, id)
INDEX idx_writeups_user (user_id, created_at)
INDEX idx_writeups_moderation (status, created_at)
INDEX idx_writeups_category (category)
FULLTEXT INDEX idx_writeups_search (title, excerpt, content_md)
```

### `rate_limits`

Stores one row per rate-limit attempt/window. The current PHP code counts active rows for a scope and IP; it does not update a unique per-IP counter row.

Important columns:

```text
id              INT UNSIGNED PRIMARY KEY
scope           VARCHAR(50)
ip_address      VARCHAR(45)
attempt_count   INT DEFAULT 1
window_start    TIMESTAMP
window_end      TIMESTAMP
created_at      TIMESTAMP
```

Current indexes:

```text
INDEX idx_rate_limits_scope_ip (scope, ip_address, window_end)
INDEX idx_rate_limits_window_end (window_end)
```

Expired rows are deleted opportunistically during normal rate-limit checks.

## Operational commands

### Check PHP syntax

```bash
find WebApp/functions DB ops tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Check JavaScript syntax

```bash
find WebApp tests -name '*.js' -print0 | xargs -0 -n1 node --check
```

### Check shell syntax

```bash
find scripts -name '*.sh' -print0 | xargs -0 -n1 bash -n
```

### Run automated tests

```bash
php tests/run_tests.php
```

### Run browser E2E tests against Docker Compose

```bash
npm install
bash scripts/e2e-docker.sh
```

This builds the Docker Compose stack, waits for `http://127.0.0.1:8080`, installs Playwright Chromium if needed, and runs `tests/e2e/`.

### Run database migrations for an existing install

```bash
php DB/migrate.php
```

Fresh installs should import `DB/DB.sql` first. `DB/migrations/001_initial_schema.sql` is a bootstrap marker and the migration runner applies the incremental migration files to the configured database.

### Local Docker development

```bash
docker compose up --build
# Web: http://localhost:8080
# Mailpit: http://localhost:8025
```

Docker Compose uses MariaDB and Mailpit. SMTP auth is disabled for local Mailpit via `SMTP_REQUIRE_AUTH=0`.

### Check Apache configuration

```bash
sudo apache2ctl configtest
```

### Reload Apache

```bash
sudo systemctl reload apache2
```

### Tail Apache logs

```bash
sudo tail -f /var/log/apache2/nightwatch_error.log
sudo tail -f /var/log/apache2/nightwatch_access.log
```

### Tail application security logs

```bash
sudo tail -f /var/www/NightWatch/logs/security_*.jsonl
```

### Check upload permissions

```bash
sudo -u www-data test -w /var/www/NightWatch/storage/photos && echo "Writable"
```

### Check database connection manually

```bash
mysql -u nightwatch_app -p NightWatchDB -e "SHOW TABLES;"
```

### Backup database and private runtime files

```bash
export NIGHTWATCH_DB_PASSWORD='replace-with-app-db-password'
bash scripts/backup.sh
```

The script writes a timestamped directory under `NIGHTWATCH_BACKUP_DIR` containing a compressed SQL dump, private runtime files such as profile photos/logs, and a SHA-256 manifest.

### Run a restore drill

```bash
export NIGHTWATCH_RESTORE_DB_USER='root'
export NIGHTWATCH_RESTORE_DB_PASSWORD='replace-with-restore-user-password'
bash scripts/restore-drill.sh /var/backups/nightwatch/20260516T120000Z/NightWatchDB.sql.gz
```

The restore-drill script restores into a temporary database, verifies tables exist, runs incremental migrations against that temporary database, and drops it afterward unless `NIGHTWATCH_KEEP_RESTORE_DB=1`.

### Forward security logs to an alerting webhook

```bash
export NIGHTWATCH_ALERT_WEBHOOK_URL='https://alerts.example.com/nightwatch'
php ops/security-log-forwarder.php --once
```

Run this from cron or a systemd timer. It forwards only configured levels by default: `WARNING,ERROR,AUTH_FAIL,MAIL_FAIL`.

## Testing and validation

### Static validation checklist

Run before deployment:

```bash
php -v
php -m
find WebApp/functions -name '*.php' -print0 | xargs -0 -n1 php -l
find WebApp tests -name '*.js' -print0 | xargs -0 -n1 node --check
php tests/run_tests.php
sudo apache2ctl configtest
```

### Manual browser test checklist

Test these flows after deploying:

- Open `/`.
- Open `/register`.
- Register a new account.
- Receive verification email.
- Verify account. Also refresh `/register/verify` once and confirm you can type the email manually if session storage is empty.
- Confirm redirect to `/panel`.
- Log out.
- Log in again.
- Create a writeup.
- Confirm writeup appears on the public feed when moderation is disabled, or publish it from `/admin` first when `NIGHTWATCH_REQUIRE_MODERATION=1`.
- Search writeups.
- Upload a JPG profile photo.
- Upload a PNG profile photo.
- Confirm `.php` upload is rejected.
- Request an email change from `/panel#account-settings` with a wrong current password and confirm the error appears without logging you out.
- Request an email change with the correct password, enter the emailed code, and confirm the panel email updates.
- Request password reset.
- Reset password.
- Confirm old session is invalidated.
- Sign in as an admin and open `/admin`.
- Publish or reject a pending writeup from the admin dashboard.
- Run `bash scripts/e2e-docker.sh` before larger releases.

### Security smoke tests

Run from a client machine:

```bash
curl -I https://example.com/
curl -I https://example.com/functions/session.php
```

Confirm headers include:

```text
X-Content-Type-Options
X-Frame-Options
Referrer-Policy
Content-Security-Policy
Strict-Transport-Security
```

Test wrong method:

```bash
curl -i https://example.com/functions/login.php
```

Expected: method error, not a PHP warning or raw stack trace.

Test upload execution blocking:

```bash
curl -I https://example.com/uploads/photos/test.php
```

Expected: denied or not found, never executed.

---

## Troubleshooting

### Site loads, but CSS/JS/assets are missing

Most likely cause: wrong document root.

Correct:

```text
DocumentRoot /var/www/NightWatch/WebApp
```

Incorrect:

```text
DocumentRoot /var/www/NightWatch
```

Confirm these URLs work:

```text
/assets/nightwatch_symbol.png
/js/auth.js
/favicon.ico
```

### Login or register returns HTTPS error

Production endpoints require HTTPS.

Fix:

1. Install a TLS certificate.
2. Use HTTPS URL.
3. If behind a reverse proxy, pass:

```text
X-Forwarded-Proto: https
```

4. Confirm Apache/PHP receives HTTPS status correctly.

### Registration works, but no email arrives

Check:

```bash
sudo tail -f /var/www/NightWatch/logs/security_*.jsonl
```

Common causes:

- Wrong `SMTP_HOST`.
- Wrong `SMTP_PORT`.
- Wrong `SMTP_USERNAME`.
- Wrong `SMTP_PASSWORD`.
- Gmail normal password used instead of app password.
- Provider blocks SMTP.
- Firewall blocks outbound port `587`.
- Certificate verification failure.

Try port `465` with `SMTP_ENCRYPTION=ssl` if TLS on `587` fails.

### User cannot log in after registration

This is expected until email verification succeeds.

Check:

```sql
SELECT id, username, email, email_verified, email_verification_code_expires_at
FROM users
ORDER BY id DESC
LIMIT 5;
```

`email_verified` must be `1`.

If the code expired, use the resend verification flow.

### Panel redirects back to login

Possible causes:

- Cookie missing.
- Cookie blocked by browser.
- Token expired.
- Database token mismatch.
- Server time is wrong.
- HTTPS/proxy headers are wrong.
- User is not verified.

Check server time:

```bash
timedatectl
```

Check user token:

```sql
SELECT id, username, token IS NOT NULL AS has_token, token_expires_at
FROM users
ORDER BY id DESC
LIMIT 5;
```

### "Too many attempts" error

The IP has hit a rate limit.

Check rows:

```sql
SELECT scope, ip_address, attempt_count, window_start, window_end
FROM rate_limits
ORDER BY window_end DESC
LIMIT 20;
```

Clear expired rows:

```sql
DELETE FROM rate_limits WHERE window_end < NOW();
```

Do not disable rate limiting in production.

### Profile photo upload fails

Check directory:

```bash
sudo ls -la /var/www/NightWatch/storage/photos
sudo -u www-data test -w /var/www/NightWatch/storage/photos && echo "Writable"
```

Check PHP upload settings:

```bash
php -i | grep -E 'upload_max_filesize|post_max_size|file_uploads'
```

Recommended:

```ini
file_uploads = On
upload_max_filesize = 1M
post_max_size = 2M
```

The app-level max photo size should remain stricter, typically `500 KB`.

### Uploaded image path appears broken

Confirm `photo_path` is relative and public-safe:

```sql
SELECT id, username, photo_path
FROM users
WHERE photo_path IS NOT NULL
ORDER BY id DESC
LIMIT 5;
```

Expected database/public examples:

```text
Database photo_path: profile_photos/profile_1_abcd1234.png
Public API value:    functions/photo.php?file=profile_1_abcd1234.png
Default photo_path:  assets/nightwatch_symbol.png
```

### Writeup search returns no results

Check published writeups:

```sql
SELECT COUNT(*) FROM writeups WHERE status = 'published';
```

Check full-text index:

```sql
SHOW INDEX FROM writeups WHERE Key_name = 'idx_writeups_search';
```

Try API directly:

```bash
curl 'https://example.com/functions/get_writeups.php?limit=10&q=test'
```

### Security logs are missing

Create log directory:

```bash
sudo mkdir -p /var/www/NightWatch/logs
sudo chown www-data:www-data /var/www/NightWatch/logs
sudo chmod 700 /var/www/NightWatch/logs
```

Then reproduce the issue and check again.

### Apache returns 500

Check:

```bash
sudo apache2ctl configtest
sudo tail -n 100 /var/log/apache2/nightwatch_error.log
sudo tail -n 100 /var/log/apache2/error.log
```

Common causes:

- Syntax error in `.htaccess`.
- Missing Apache `headers` module.
- Missing Apache `rewrite` module if your deployment relies on route rewrites or you are using the default setup script.
- Wrong file permissions.
- Missing PHP extension.
- DB config not readable by Apache user.

---

## Production checklist

Before going live:

```text
[ ] Apache document root points to WebApp/.
[ ] HTTPS certificate is installed and valid.
[ ] Apache headers and ssl modules are enabled; rewrite is enabled when using the default setup script or route compatibility is needed.
[ ] DB password is changed from CHANGE_ME_STRONG_PASSWORD.
[ ] DB credentials are provided through environment variables or secure config.
[ ] SMTP credentials are configured through environment variables.
[ ] /var/www/config/db_config.php is root:www-data with 0640 permissions.
[ ] logs/ is outside the public document root and owned by www-data.
[ ] storage/photos/ is writable by www-data and not publicly served.
[ ] Legacy uploads/ is non-executable and storage/ is denied by Apache.
[ ] .htaccess files are enabled with AllowOverride All.
[ ] PHP errors are not displayed to users in production.
[ ] Security headers are present.
[ ] Registration email arrives.
[ ] Password reset email arrives.
[ ] Profile-photo upload works.
[ ] Invalid upload types are rejected.
[ ] Login, logout, and session expiry work.
[ ] Public writeup feed works.
[ ] Database backups are scheduled with `scripts/backup.sh`.
[ ] Restore drills are scheduled with `scripts/restore-drill.sh`.
[ ] JSONL security log forwarding/alerting is configured with `ops/security-log-forwarder.php`.
[ ] Log rotation/retention is configured.
```

---

## Production operations follow-up

The application-code and project-scaffolding backlog is complete in this build. The remaining items below are not missing NightWatch features; they require real production infrastructure, credentials, monitoring targets, and recurring operational practice.

```text
[ ] Provision and monitor the real production backup destination.
[ ] Connect ops/security-log-forwarder.php to your SIEM, Slack, PagerDuty, or webhook gateway.
[ ] Schedule restore drills with scripts/restore-drill.sh and record evidence of successful restores.
[ ] Move high-volume profile-photo storage to the selected object store/CDN when traffic justifies it.
```

---

## Maintenance notes

### Safe deployment habit

Before updating production:

```bash
mysqldump -u root -p NightWatchDB > nightwatch_predeploy_$(date +%F_%H%M).sql
sudo tar -czf nightwatch_files_predeploy_$(date +%F_%H%M).tar.gz /var/www/NightWatch
```

Then deploy, run syntax checks, reload Apache, and test the main user flows.

### Secrets policy

Never commit these to Git:

```text
Database passwords
SMTP passwords
Session tokens
API keys
Private TLS keys
Production .env files
Security logs
Database dumps
```

Suggested `.gitignore` additions:

```gitignore
.env
*.env
logs/
*.log
*.jsonl
*.sql
*.sql.gz
storage/photos/*
!storage/photos/.htaccess
WebApp/uploads/photos/*
!WebApp/uploads/photos/.htaccess
```

### Error display policy

Production PHP should not display errors to users.

Recommended production settings:

```ini
display_errors = Off
log_errors = On
error_log = /var/log/php/nightwatch_php_errors.log
```

During development only:

```ini
display_errors = On
error_reporting = E_ALL
```

---

## Quick start summary

For a fresh production-style Apache install:

```bash
sudo apt update
sudo apt install -y apache2 mysql-server php php-cli php-mysql php-mbstring php-gd php-curl php-xml unzip rsync openssl python3
sudo a2enmod rewrite headers ssl

sudo mkdir -p /var/www/NightWatch
sudo rsync -av ./NightWatch/ /var/www/NightWatch/

cd /var/www/NightWatch

DB_PASS="$(openssl rand -base64 32)"
python3 - <<'PY'
import os
from pathlib import Path
Path('/tmp/nightwatch-db.sql').write_text(Path('DB/DB.sql').read_text().replace('CHANGE_ME_STRONG_PASSWORD', os.environ['DB_PASS']))
PY
mysql -u root -p < /tmp/nightwatch-db.sql
rm -f /tmp/nightwatch-db.sql

sudo \
  SERVER_NAME='example.com' \
  PROJECT_DIR='/var/www/NightWatch' \
  WEB_ROOT='/var/www/NightWatch/WebApp' \
  CONFIG_DIR='/var/www/config' \
  LOG_DIR='/var/www/NightWatch/logs' \
  NIGHTWATCH_PHOTO_STORAGE_DIR='/var/www/NightWatch/storage/photos' \
  NIGHTWATCH_DB_HOST='localhost' \
  NIGHTWATCH_DB_USER='nightwatch_app' \
  NIGHTWATCH_DB_PASSWORD="$DB_PASS" \
  NIGHTWATCH_DB_NAME='NightWatchDB' \
  ENABLE_HTTPS='0' \
  ./setup.sh

sudo apache2ctl configtest
sudo systemctl reload apache2
```

For a public domain, issue a certificate with Certbot, re-run `setup.sh` with `ENABLE_HTTPS=1`, configure SMTP, then open:

```text
https://example.com/
```

Register a test user, verify email delivery, upload a small JPG/PNG profile photo, create a writeup, and confirm the public feed displays it.
