# NightWatch

NightWatch is a framework-free PHP and MySQL web application for publishing and reading security writeups. Apache serves the browser UI from `WebApp/`, and JSON endpoints under `WebApp/functions/` handle authentication, account recovery, email verification, profile-photo uploads, writeup publishing, moderation, and security logging.

The public document root must be `WebApp/`, not the repository root.

## Current Project Shape

This repository intentionally avoids extra scaffolding:

- No Composer project file is required for the current runtime.
- No `.env.example` file is kept in the project. Configure secrets through Apache, PHP-FPM, shell, systemd, or your hosting panel.
- A single importable schema file is kept at `DB/nightwatch_schema.sql`. Incremental production migrations should still be managed through your normal database process.
- `DB/db_config.php` remains because the PHP endpoints load it to read database environment variables.

## Stack

- PHP 8.1 or newer
- MySQL 8 or MariaDB with InnoDB and `utf8mb4`
- Apache with `headers`, `rewrite`, and optionally `ssl`
- Browser UI written in plain HTML, CSS, and JavaScript
- Native PHP `mysqli`, `json`, `fileinfo`, `gd`, `session`, `openssl`, and preferably `mbstring`

## Directory Layout

```text
NightWatch/
|-- .htaccess                         Root deny rule if the wrong document root is used
|-- README.md                         Project guide and database schema reference
|-- setup.sh                          Apache hardening and virtual-host setup helper
|-- DB/
|   |-- db_config.php                 Runtime DB constants from NIGHTWATCH_DB_* variables
|   `-- nightwatch_schema.sql         Fresh MySQL schema for this app
|-- ops/
|   `-- security-log-forwarder.php    JSONL security-log forwarder for cron/systemd
|-- storage/
|   |-- .htaccess                     Denies direct web access
|   `-- photos/
|       `-- .htaccess                 Private profile-photo storage deny rule
`-- WebApp/
    |-- .htaccess                     Public app headers and deny rules
    |-- index.html                    Homepage, search, and public writeup feed
    |-- style.css
    |-- animation.js
    |-- favicon.ico
    |-- robots.txt
    |-- sitemap.xml
    |-- assets/
    |   `-- nightwatch_symbol.png
    |-- js/
    |   `-- auth.js                  Shared session, CSRF, logout, and auth fetch helper
    |-- login/
    |-- register/
    |   `-- verify/
    |-- forgetpassword/
    |-- panel/                       Authenticated member dashboard
    |-- admin/                       Admin writeup moderation UI
    |-- users/                       Public user profile page
    |-- uploads/                     Legacy upload compatibility, PHP execution denied
    `-- functions/                   PHP JSON endpoints and shared helpers
```

## Runtime Model

The app is intentionally small and direct:

- Static pages call JSON endpoints with `fetch()`.
- `WebApp/js/auth.js` fetches CSRF tokens, validates sessions, retries stale CSRF requests once, and sends logout requests.
- State-changing endpoints require POST, same-origin requests, HTTPS outside localhost, and `X-CSRF-Token`.
- Authentication uses an HttpOnly `nightwatch_token` cookie. The database stores only a SHA-256 hash of that token.
- PHP sessions store user metadata and the token hash, but the database token is still authoritative.
- Passwords use PHP password hashing. New passwords are hashed with Argon2id where available.
- Registration, password reset, and email-change flows use 6-digit codes whose SHA-256 hashes are stored in the database.
- Profile photos are re-encoded to PNG, center-cropped to 512 by 512, stored outside the public document root, and served through `/functions/photo.php`.
- Security and audit events are written as daily JSONL files outside `WebApp/`.

## Public Routes

| Route | Purpose |
| --- | --- |
| `/` | Public homepage, latest writeup, feed, and search |
| `/login` | Login form |
| `/register` | Account registration |
| `/register/verify` | Email verification code form |
| `/forgetpassword` | Password reset request and reset form |
| `/panel` | Authenticated member panel |
| `/admin` | Admin moderation dashboard |
| `/users/?username=<name>` | Public user profile and recent published writeups |

## API Endpoints

All JSON endpoints live under `/functions/` when `WebApp/` is the document root.

| Endpoint | Method | Auth | Purpose |
| --- | --- | --- | --- |
| `/functions/csrf.php` | GET | No | Issue CSRF token |
| `/functions/register.php` | POST | No | Create unverified account and send verification code |
| `/functions/verify_email.php` | POST | No | Verify email and sign in |
| `/functions/resend_verification.php` | POST | No | Send a fresh verification code |
| `/functions/login.php` | POST | No | Validate credentials and create session |
| `/functions/session.php` | GET | Cookie | Validate current session |
| `/functions/logout.php` | POST | Optional | Clear token, cookie, and PHP session |
| `/functions/panel.php` | GET | Yes | Return current user and latest writeups |
| `/functions/get_writeups.php` | GET | No | Public published writeup feed with search and pagination |
| `/functions/create_writeup.php` | POST | Yes | Save draft or publish/submit writeup |
| `/functions/upload_photo.php` | POST | Yes | Validate, crop, re-encode, and store profile photo |
| `/functions/photo.php` | GET | No | Serve private or legacy profile-photo file |
| `/functions/get_users.php` | GET | Yes | Authenticated directory of verified users |
| `/functions/get_user_profile.php` | GET | No | Public profile and recent published writeups |
| `/functions/forget_password.php` | POST | No | Start password reset |
| `/functions/reset_password.php` | POST | No | Complete password reset |
| `/functions/request_email_change.php` | POST | Yes | Start verified email-change flow |
| `/functions/verify_email_change.php` | POST | Yes | Complete email-change flow |
| `/functions/admin_writeups.php` | GET | Admin | List writeups by moderation status |
| `/functions/moderate_writeup.php` | POST | Admin | Publish or reject pending writeup |

Shared helper files in `WebApp/functions/` are not browser APIs:

- `common.php`: JSON responses, sessions, auth cookies, DB connection, CSRF, HTTPS checks, rate limits, profile-photo path safety, writeup input helpers
- `app_logging.php`: JSONL audit/security/mail/rate-limit logging
- `mail_config.php`: SMTP settings from environment variables
- `mailer.php`: verification, password reset, and email-change messages through native SMTP, with optional PHPMailer if `vendor/autoload.php` exists

## Required Environment Variables

Set secrets in your server environment. Do not hard-code production secrets into the repository.

### Database

```bash
NIGHTWATCH_DB_HOST=localhost
NIGHTWATCH_DB_USER=nightwatch_app
NIGHTWATCH_DB_PASSWORD=replace-with-a-strong-password
NIGHTWATCH_DB_NAME=NightWatchDB
NIGHTWATCH_ALLOW_INSECURE_DEFAULTS=0
```

`DB/db_config.php` falls back to `localhost`, `nightwatch_app`, `CHANGE_ME_STRONG_PASSWORD`, and `NightWatchDB`. Production requests fail if the password is still `CHANGE_ME_STRONG_PASSWORD` unless `NIGHTWATCH_ALLOW_INSECURE_DEFAULTS=1`.

### SMTP

```bash
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=noreply@example.com
SMTP_PASSWORD=replace-with-your-smtp-password
SMTP_ENCRYPTION=tls
SMTP_VERIFY_CERT=true
SMTP_REQUIRE_AUTH=true
SMTP_ALLOW_INSECURE_AUTH=false
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=NightWatch System
EMAIL_VERIFICATION_TIMEOUT=900
PASSWORD_RESET_TIMEOUT=3600
```

`SMTP_ENCRYPTION` accepts `tls`, `ssl`, or `none`. Keep certificate verification enabled in production.
If SMTP authentication is enabled, `none` is rejected unless `SMTP_ALLOW_INSECURE_AUTH=true`; that exception should only be used for isolated local mail catchers.

### Runtime

```bash
NIGHTWATCH_REQUIRE_MODERATION=0
NIGHTWATCH_TRUSTED_PROXIES=
NIGHTWATCH_PHOTO_STORAGE_DIR=/var/www/NightWatch/storage/photos
NIGHTWATCH_PHOTO_PUBLIC_BASE_URL=
NIGHTWATCH_ALLOW_INSECURE_PHOTO_BASE_URL=0
NIGHTWATCH_LOG_DIR=/var/www/NightWatch/logs
```

`NIGHTWATCH_TRUSTED_PROXIES` is a comma-separated list of direct proxy IP addresses. Only those peers are trusted for `X-Forwarded-Proto` and `X-Forwarded-For`.

`NIGHTWATCH_PHOTO_PUBLIC_BASE_URL` is optional. If set, the app returns `https://your-photo-host/<filename>` for uploaded profile photos. The app does not upload to that service for you.

### Log Forwarder

```bash
NIGHTWATCH_ENV=production
NIGHTWATCH_ALERT_WEBHOOK_URL=https://alerts.example.com/nightwatch
NIGHTWATCH_ALERT_LEVELS=WARNING,ERROR,AUTH_FAIL,MAIL_FAIL
NIGHTWATCH_LOG_FORWARDER_STATE_DIR=/var/lib/nightwatch/log-forwarder
NIGHTWATCH_ALLOW_INSECURE_WEBHOOK=0
```

## Fresh Database Schema

Create the database and schema before serving the app. Replace the password in `DB/nightwatch_schema.sql` first, then import it:

```bash
mysql -u root -p < DB/nightwatch_schema.sql
```

The schema file contains this structure:

```sql
CREATE DATABASE IF NOT EXISTS NightWatchDB
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'nightwatch_app'@'localhost'
  IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE ON NightWatchDB.* TO 'nightwatch_app'@'localhost';
FLUSH PRIVILEGES;

USE NightWatchDB;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    token CHAR(64) DEFAULT NULL,
    token_expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    photo_path VARCHAR(255) NOT NULL DEFAULT 'assets/nightwatch_symbol.png',
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    pending_email VARCHAR(255) DEFAULT NULL,
    email_change_code CHAR(64) DEFAULT NULL,
    email_change_code_expires_at TIMESTAMP NULL,
    login_failures INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    email_verification_code CHAR(64) DEFAULT NULL,
    email_verification_code_expires_at TIMESTAMP NULL,
    reset_code CHAR(64) DEFAULT NULL,
    reset_code_expires_at TIMESTAMP NULL,
    INDEX idx_users_token (token, token_expires_at),
    INDEX idx_users_email_verified (email, email_verified),
    INDEX idx_users_pending_email (pending_email),
    INDEX idx_users_locked_until (locked_until),
    INDEX idx_users_admin (is_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS writeups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(140) NOT NULL,
    content_md MEDIUMTEXT NOT NULL,
    excerpt VARCHAR(280) DEFAULT NULL,
    status ENUM('published', 'draft', 'pending', 'rejected') NOT NULL DEFAULT 'published',
    category VARCHAR(40) DEFAULT NULL,
    tags VARCHAR(255) DEFAULT NULL,
    moderated_by INT UNSIGNED DEFAULT NULL,
    moderated_at TIMESTAMP NULL,
    rejection_reason VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_writeups_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_writeups_moderator
        FOREIGN KEY (moderated_by) REFERENCES users(id)
        ON DELETE SET NULL,
    INDEX idx_writeups_feed (status, published_at, id),
    INDEX idx_writeups_user (user_id, created_at),
    INDEX idx_writeups_moderation (status, created_at),
    INDEX idx_writeups_category (category),
    FULLTEXT INDEX idx_writeups_search (title, excerpt, content_md)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_count INT NOT NULL DEFAULT 1,
    window_start TIMESTAMP NOT NULL,
    window_end TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_limits_scope_ip (scope, ip_address, window_end),
    INDEX idx_rate_limits_window_end (window_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

For existing databases, compare your live schema against the block above and apply a controlled migration through your normal database process. Take a dump before changing production.

## Admin Account

Create a normal user through the UI, verify the email address, then promote that user from MySQL:

```sql
UPDATE users SET is_admin = 1 WHERE username = 'your_username';
```

Admin-only endpoints also verify the session server-side. Hiding or showing the `/admin` link in the UI is not the security boundary.

## Local Apache Deployment

Example Ubuntu package baseline:

```bash
sudo apt update
sudo apt install -y apache2 mysql-server php php-cli php-mysql php-mbstring php-curl php-xml php-gd php-zip unzip rsync openssl
sudo a2enmod rewrite headers ssl
sudo systemctl reload apache2
```

Copy the project and create private runtime directories:

```bash
sudo mkdir -p /var/www/NightWatch
sudo rsync -av ./NightWatch/ /var/www/NightWatch/
sudo chown -R root:root /var/www/NightWatch
```

Create the database using the schema above. Generate a real database password:

```bash
openssl rand -base64 32
```

Run the Apache setup helper after the database exists:

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
  NIGHTWATCH_DB_PASSWORD='replace-with-the-real-password' \
  NIGHTWATCH_DB_NAME='NightWatchDB' \
  NIGHTWATCH_REQUIRE_MODERATION='0' \
  NIGHTWATCH_ALLOW_INSECURE_DEFAULTS='0' \
  ENABLE_HTTPS='0' \
  ./setup.sh
```

Then enable the site and reload:

```bash
sudo a2dissite 000-default.conf
sudo a2ensite nightwatch.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Open:

```text
http://localhost/
```

For production, set `ENABLE_HTTPS=1`, provide certificate paths or use Certbot, set SMTP variables, and serve the site at HTTPS. Non-localhost write endpoints require HTTPS.

## What setup.sh Does

`setup.sh` configures the web server and filesystem only. It does not create or migrate the database.

It performs these actions:

- Confirms `WebApp/` and `DB/db_config.php` exist.
- Enables Apache `rewrite`, `headers`, and `ssl`.
- Copies `DB/db_config.php` to a private config directory.
- Creates private log and profile-photo directories.
- Writes deny rules for root and private storage.
- Makes uploaded legacy photo directories non-executable.
- Writes an Apache virtual host that points at `WebApp/`.
- Writes Apache `SetEnv` values when environment variables are supplied.
- Runs `apache2ctl configtest` and reloads Apache.

## File Permissions

Recommended production ownership:

```text
/var/www/NightWatch                 root:root
/var/www/NightWatch/WebApp          root:www-data, directories 0755, files 0644
/var/www/NightWatch/storage/photos  www-data:www-data, directory 0750
/var/www/NightWatch/logs            www-data:www-data, directory 0700
/var/www/config/db_config.php       root:www-data, file 0640
```

Only `logs/`, `storage/photos/`, and legacy `WebApp/uploads/` should be writable by the web server.

## Security Controls

Implemented controls include:

- Root `.htaccess` denies access if the repository root is accidentally served.
- `WebApp/.htaccess` sets CSP, frame, MIME, referrer, permissions, and resource-policy headers.
- State-changing endpoints enforce POST, CSRF, same-origin checks, and HTTPS outside local development.
- Session cookies are HttpOnly, SameSite=Lax, and Secure when HTTPS is active.
- Browser auth tokens are stored raw only in the cookie; the database stores SHA-256 token hashes.
- Login has IP rate limits and progressive account lockout.
- Registration, verification, reset, upload, writeup, and email-change endpoints are rate limited.
- Profile photos are MIME-checked, size-limited, re-encoded, cropped, metadata-stripped, and stored outside `WebApp/`.
- Public profile and user-list responses never expose email addresses.
- Admin actions require `is_admin = 1` in the authenticated session.
- Logs sanitize control characters and avoid passwords, tokens, and verification codes.

## User Flows

### Registration

1. User submits username, email, password, and confirmation from `/register`.
2. The server validates input, rate limit, CSRF, origin, HTTPS, and uniqueness.
3. A 6-digit verification code is generated, hashed, stored, and emailed.
4. User enters the code at `/register/verify`.
5. The server verifies the code, marks the account verified, creates a session, and redirects to `/panel`.

### Login

1. User submits username or email and password.
2. The server checks rate limits, account lockout, password hash, and email verification.
3. A random token is issued, hashed in the DB, and set as an HttpOnly cookie.
4. Session metadata is stored in PHP session state.

### Password Reset

1. User enters email at `/forgetpassword`.
2. Existing accounts get a reset code by email. Unknown emails receive the same generic response.
3. User submits email, code, new password, and confirmation.
4. The password is rehashed, reset fields are cleared, lockout fields are reset, and existing token is invalidated.

### Writeups

1. Authenticated users create writeups from `/panel`.
2. The server validates title, Markdown body, category, tags, and blocked raw HTML.
3. Drafts remain private. Published writeups appear in the public feed unless moderation is required.
4. If `NIGHTWATCH_REQUIRE_MODERATION=1`, publish requests become `pending` until an admin approves them.

### Email Change

1. Authenticated user submits a new email and current password from `/panel`.
2. The server stores `pending_email`, hashes a code, and emails the new address.
3. User enters the code. The server checks availability again and updates `users.email`.

### Profile Photos

1. Authenticated user uploads a JPG or PNG under 500 KB.
2. PHP GD decodes it, center-crops it to a square, resizes to 512 by 512, and writes a PNG.
3. The database stores `profile_photos/<filename>`.
4. The browser receives either `/functions/photo.php?file=<filename>` or the configured public base URL.

## Writeup Search

`GET /functions/get_writeups.php` supports:

```text
limit=1..30
page=1..n
q=search text up to 80 characters
```

Search uses the `idx_writeups_search` FULLTEXT index on title, excerpt, and Markdown content. Without a search query, results are ordered by `published_at DESC, id DESC`.

## Logging And Forwarding

Runtime logs are JSONL files named:

```text
security_YYYY-MM-DD.jsonl
```

Default log location is `logs/` beside `WebApp/`, or the directory configured by `NIGHTWATCH_LOG_DIR`.

Forward selected events to stdout or an HTTPS webhook:

```bash
php ops/security-log-forwarder.php --once
php ops/security-log-forwarder.php --dry-run
```

The forwarder stores offsets under `storage/log-forwarder/` by default or `NIGHTWATCH_LOG_FORWARDER_STATE_DIR` when set. Run it from cron or a systemd timer.

## Validation

Run PHP syntax checks:

```bash
find WebApp/functions DB ops -name '*.php' -print0 | xargs -0 -n1 php -l
```

Check Apache:

```bash
sudo apache2ctl configtest
```

Check required PHP modules:

```bash
php -m | grep -E 'mysqli|json|fileinfo|gd|session|openssl|mbstring'
```

Manual smoke test after deployment:

- Open `/`.
- Register a user.
- Confirm the SMTP verification email arrives.
- Verify the email and confirm redirect to `/panel`.
- Log out and log in again.
- Create a draft and a published writeup.
- Search for the writeup on `/`.
- Upload JPG and PNG profile photos.
- Request and complete a password reset.
- Request and complete an email change.
- Promote an admin and test `/admin`.

## Troubleshooting

### CSS, JS, or images return 404

Apache is probably serving the wrong directory. `DocumentRoot` must be the `WebApp/` directory. These paths should return content:

```text
/style.css
/js/auth.js
/assets/nightwatch_symbol.png
```

### Login or registration says HTTPS is required

Production endpoints require HTTPS. Use TLS, or for a reverse proxy set `X-Forwarded-Proto: https` only from a proxy IP listed in `NIGHTWATCH_TRUSTED_PROXIES`.

### Database password default error

Set `NIGHTWATCH_DB_PASSWORD` to the real password. Do not set `NIGHTWATCH_ALLOW_INSECURE_DEFAULTS=1` outside isolated local testing.

### Emails do not arrive

Check the security log for `MAIL_FAIL`. Confirm SMTP host, port, username, password, encryption, and certificate verification. For Gmail-style accounts, use an app password instead of the account password.

### Profile-photo upload fails

Confirm PHP GD is installed and the storage directory is writable:

```bash
php -m | grep gd
sudo -u www-data test -w /var/www/NightWatch/storage/photos && echo writable
```

Recommended PHP limits:

```ini
file_uploads = On
upload_max_filesize = 1M
post_max_size = 2M
```

### Public writeups do not load

Check database credentials, confirm the schema exists, and verify published rows:

```sql
SELECT COUNT(*) FROM writeups WHERE status = 'published';
SHOW INDEX FROM writeups WHERE Key_name = 'idx_writeups_search';
```

### Admin page redirects or denies access

Confirm the user is signed in, email is verified, and `is_admin = 1`:

```sql
SELECT id, username, email_verified, is_admin FROM users WHERE username = 'your_username';
```

## Production Checklist

- Apache document root points to `WebApp/`.
- HTTPS certificate is installed and valid.
- Apache `headers`, `rewrite`, and `ssl` modules are enabled.
- Database schema exists and matches this README.
- Database password is not the placeholder.
- DB and SMTP credentials are environment variables.
- `NIGHTWATCH_ALLOW_INSECURE_DEFAULTS=0`.
- `NIGHTWATCH_TRUSTED_PROXIES` is set only when a real reverse proxy is present.
- `storage/photos/` is outside `WebApp/` and writable by the web server.
- `logs/` is outside `WebApp/` and writable by the web server.
- PHP errors are logged, not displayed to users.
- Security headers are present in `curl -I` output.
- Registration, verification, login, logout, password reset, email change, writeup creation, photo upload, and admin moderation have been tested.
- Database backups are scheduled and restore-tested.
- JSONL log retention and forwarding are configured.

## Maintenance Notes

Before production changes:

```bash
mysqldump -u root -p NightWatchDB > nightwatch_predeploy_$(date +%F_%H%M).sql
sudo tar -czf nightwatch_files_predeploy_$(date +%F_%H%M).tar.gz /var/www/NightWatch
```

Keep these out of shared archives and version control:

```text
real environment files
database dumps
SMTP credentials
session tokens
verification/reset codes
TLS private keys
logs
uploaded profile photos
```
