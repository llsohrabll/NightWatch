# NightWatch PHP Web Application

**Last updated:** 2026-05-16  
**Stack:** PHP 8.x, MySQL/MariaDB, Apache, HTML/CSS/JavaScript  
**Document root:** `WebApp/`

NightWatch is a PHP/MySQL web application for publishing ethical-hacking and security-learning writeups. The browser serves static pages and assets from `WebApp/`, while PHP JSON endpoints under `WebApp/functions/` handle authentication, sessions, email verification, password reset, writeup publishing, profile-photo uploads, rate limiting, security logging, and database access.

> **Important:** The Apache/Nginx document root must point to `WebApp/`, not the repository root. The frontend uses root-relative paths such as `/functions/login.php`, `/assets/nightwatch-logo.svg`, `/login`, `/register`, and `/panel`.

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
19. [Recommended next improvements](#recommended-next-improvements)

---

## Project status

This README describes the hardened NightWatch project layout and deployment model.

The current implementation is designed around:

- Apache serving `WebApp/` as the public document root.
- PHP endpoints returning JSON.
- MySQL/MariaDB storing users, writeups, session tokens, reset codes, verification codes, and rate-limit windows.
- SMTP-based outgoing mail for verification and password-reset codes.
- Profile photo uploads to `WebApp/uploads/photos/`.
- Logs stored outside the public web root.
- Environment variables for secrets and deployment-specific values.

The earlier README had duplicated sections, broken code blocks, contradictory email notes, and mixed old/new security claims. This version removes those conflicts and documents the intended production setup clearly.

---

## Production assumptions

The instructions below assume:

```text
Application path:      /var/www/NightWatch
Public document root:  /var/www/NightWatch/WebApp
Apache user/group:     www-data:www-data
Config directory:      /var/www/config
Log directory:         /var/www/NightWatch/logs
Upload directory:      /var/www/NightWatch/WebApp/uploads/photos
Database name:         NightWatchDB
Database app user:     nightwatch_app
```

If your paths differ, update `APP_ROOT`, Apache virtual host paths, and environment variables accordingly.

---

## Directory layout

```text
NightWatch/
├── DB/
│   ├── DB.sql
│   │   └── Creates/updates database, app user, tables, and indexes.
│   ├── db_config.php
│   │   └── Reads NIGHTWATCH_DB_* environment variables with safe defaults.
│   └── setup.sh
│       └── Optional deployment helper for config/permissions/Apache setup.
│
├── WebApp/
│   ├── .htaccess
│   │   └── Apache rewrites, security headers, and public routing rules.
│   ├── assets/
│   │   └── Static images/icons such as nightwatch-logo.svg.
│   ├── favicon.ico
│   ├── index.html
│   │   └── Public landing page and writeup feed.
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
│   ├── functions/
│   │   ├── common.php
│   │   ├── app_logging.php
│   │   ├── db.php
│   │   ├── mail_config.php
│   │   ├── mailer.php
│   │   ├── register.php
│   │   ├── verify_email.php
│   │   ├── resend_verification.php
│   │   ├── login.php
│   │   ├── logout.php
│   │   ├── session.php
│   │   ├── panel.php
│   │   ├── get_writeups.php
│   │   ├── create_writeup.php
│   │   ├── upload_photo.php
│   │   ├── get_users.php
│   │   ├── forget_password.php
│   │   └── reset_password.php
│   └── uploads/
│       └── photos/
│           └── User profile-photo upload target.
│
├── logs/
│   └── Security logs. Must stay outside public document root.
│
└── README.md
```

---

## Application architecture

### Request flow

```text
Browser
  │
  ├── GET /, /login, /register, /panel
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
- Database connection logic lives in `WebApp/functions/db.php`.
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

Recommended production baseline:

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
  openssl
```

Optional but recommended:

```bash
sudo apt install -y certbot python3-certbot-apache
```

### PHP extensions

Required or strongly recommended:

```text
mysqli / pdo_mysql    Database access
mbstring              UTF-8-safe string handling
fileinfo              MIME detection for uploads
gd                    Image inspection/processing support
openssl               SMTP TLS/SSL and secure random functions
json                  JSON responses
session               PHP sessions
```

Check loaded modules:

```bash
php -m
```

### Apache modules

Enable these modules:

```bash
sudo a2enmod rewrite headers ssl
sudo systemctl reload apache2
```

Required modules:

```text
rewrite    Clean routes and fallback rules
headers    Security headers
ssl        HTTPS virtual host support
```

---

## Environment variables

Use environment variables for secrets and deployment-specific values. Do not hard-code production secrets into PHP files.

### Database variables

```bash
export NIGHTWATCH_DB_HOST='localhost'
export NIGHTWATCH_DB_USER='nightwatch_app'
export NIGHTWATCH_DB_PASSWORD='replace-with-a-strong-password'
export NIGHTWATCH_DB_NAME='NightWatchDB'
```

### SMTP variables

```bash
export SMTP_HOST='smtp.example.com'
export SMTP_PORT='587'
export SMTP_USERNAME='noreply@example.com'
export SMTP_PASSWORD='replace-with-your-smtp-password'
export SMTP_ENCRYPTION='tls'
export SMTP_VERIFY_CERT='true'
export MAIL_FROM_ADDRESS='noreply@example.com'
export MAIL_FROM_NAME='NightWatch System'
```

### Deployment variables used by `setup.sh`

```bash
export APP_ROOT='/var/www/NightWatch'
export WEB_ROOT='/var/www/NightWatch/WebApp'
export CONFIG_DIR='/var/www/config'
export LOG_DIR='/var/www/NightWatch/logs'
export UPLOAD_DIR='/var/www/NightWatch/WebApp/uploads/photos'
export APACHE_USER='www-data'
export APACHE_GROUP='www-data'
export SERVER_NAME='example.com'
export ENABLE_HTTPS='1'
```

### Apache environment file option

For Apache + PHP, a practical approach is to store variables in a root-owned environment file:

```bash
sudo mkdir -p /etc/nightwatch
sudo nano /etc/nightwatch/nightwatch.env
sudo chown root:www-data /etc/nightwatch/nightwatch.env
sudo chmod 640 /etc/nightwatch/nightwatch.env
```

Example `/etc/nightwatch/nightwatch.env`:

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
SetEnv MAIL_FROM_ADDRESS "noreply@example.com"
SetEnv MAIL_FROM_NAME "NightWatch System"
```

Then include it from your Apache virtual host:

```apache
IncludeOptional /etc/nightwatch/nightwatch.env
```

---

## Production deployment with Apache

### 1. Place project files

Recommended location:

```bash
sudo mkdir -p /var/www/NightWatch
sudo rsync -av --delete ./NightWatch/ /var/www/NightWatch/
```

Set base ownership:

```bash
sudo chown -R root:root /var/www/NightWatch
```

The web server should not own your application code. Only writable runtime directories should be owned by `www-data`.

### 2. Copy secure database config

The app supports a config file outside the web root:

```bash
sudo mkdir -p /var/www/config
sudo cp /var/www/NightWatch/DB/db_config.php /var/www/config/db_config.php
sudo chown root:www-data /var/www/config/db_config.php
sudo chmod 640 /var/www/config/db_config.php
```

### 3. Create Apache virtual host

Example HTTP virtual host:

```apache
<VirtualHost *:80>
    ServerName example.com

    DocumentRoot /var/www/NightWatch/WebApp

    IncludeOptional /etc/nightwatch/nightwatch.env

    <Directory /var/www/NightWatch/WebApp>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <Directory /var/www/NightWatch/WebApp/functions>
        Options -Indexes
        Require all granted
    </Directory>

    <Directory /var/www/NightWatch/WebApp/uploads>
        Options -Indexes
        AllowOverride All
        Require all granted

        <FilesMatch "\.php$">
            Require all denied
        </FilesMatch>
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/nightwatch_error.log
    CustomLog ${APACHE_LOG_DIR}/nightwatch_access.log combined
</VirtualHost>
```

Save as:

```text
/etc/apache2/sites-available/nightwatch.conf
```

Enable it:

```bash
sudo a2ensite nightwatch.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### 4. Enable HTTPS

With Certbot:

```bash
sudo certbot --apache -d example.com
```

After HTTPS is active, keep production HTTPS enforcement enabled in the application.

### 5. Run setup script

The hardened `setup.sh` should handle:

- Required directory creation.
- Secure ownership.
- Upload directory permissions.
- Log directory permissions.
- Config file placement.
- Apache module enablement.
- Apache virtual host generation or validation.
- Upload `.htaccess` hardening.
- Basic syntax checks.

Typical run:

```bash
cd /var/www/NightWatch

sudo \
  SERVER_NAME='example.com' \
  APP_ROOT='/var/www/NightWatch' \
  WEB_ROOT='/var/www/NightWatch/WebApp' \
  CONFIG_DIR='/var/www/config' \
  LOG_DIR='/var/www/NightWatch/logs' \
  UPLOAD_DIR='/var/www/NightWatch/WebApp/uploads/photos' \
  ENABLE_HTTPS='1' \
  NIGHTWATCH_DB_PASSWORD='replace-with-a-strong-password' \
  SMTP_HOST='smtp.example.com' \
  SMTP_PORT='587' \
  SMTP_USERNAME='noreply@example.com' \
  SMTP_PASSWORD='replace-with-your-smtp-password' \
  SMTP_ENCRYPTION='tls' \
  MAIL_FROM_ADDRESS='noreply@example.com' \
  MAIL_FROM_NAME='NightWatch System' \
  ./setup.sh
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

### 2. Create/update database

Run:

```bash
mysql -u root -p < DB/DB.sql
```

### 3. Confirm database user and tables

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

Expected tables:

```text
users
writeups
rate_limits
```

### 4. Confirm search index

```sql
SHOW INDEX FROM writeups WHERE Key_name = 'idx_writeups_search';
```

If the full-text index is missing, rerun `DB/DB.sql` or apply the missing migration manually.

---

## File and directory permissions

### Recommended ownership model

```text
Application code:      root:root, not writable by Apache
Database config:       root:www-data, 0640
Logs directory:        www-data:www-data, 0700
Upload directory:      www-data:www-data, 0755
Uploaded files:        www-data:www-data, 0644
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

sudo mkdir -p /var/www/NightWatch/WebApp/uploads/photos
sudo chown -R www-data:www-data /var/www/NightWatch/WebApp/uploads
sudo find /var/www/NightWatch/WebApp/uploads -type d -exec chmod 755 {} \;
sudo find /var/www/NightWatch/WebApp/uploads -type f -exec chmod 644 {} \;
```

### Upload directory hardening

Create `WebApp/uploads/.htaccess`:

```apache
Options -Indexes

<FilesMatch "\.php$">
    Require all denied
</FilesMatch>

<FilesMatch "\.(phtml|phar|php[0-9]?)$">
    Require all denied
</FilesMatch>
```

Create `WebApp/uploads/photos/.htaccess`:

```apache
Options -Indexes

<FilesMatch "\.(php|phtml|phar|php[0-9]?)$">
    Require all denied
</FilesMatch>
```

This prevents uploaded files from being executed as PHP even if an attacker bypasses extension validation.

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

Passwords are hashed with `PASSWORD_ARGON2ID`.

Operational notes:

- Never store plaintext passwords.
- Do not log passwords.
- Do not send passwords by email.
- Consider increasing Argon2id cost parameters only after measuring server performance.

### Session tokens

Session tokens use this pattern:

```text
Browser cookie:   random raw token
Database value:   SHA-256 hash of token
```

Benefits:

- Database leak does not directly expose active browser tokens.
- Tokens can be invalidated by clearing the database hash.
- Expiry is enforced on every protected request.

Cookie requirements:

```text
HttpOnly: true
SameSite: Lax
Secure: true in HTTPS production
Path: /
```

### Email verification codes

Verification codes are 6-digit numeric codes.

Recommended storage:

```text
Database: SHA-256 hash of code
Expiry:   15 minutes
Compare:  hash_equals()
```

Do not store production verification codes in plaintext.

### Password reset codes

Reset codes are 6-digit numeric codes.

Recommended storage:

```text
Database: SHA-256 hash of code
Expiry:   1 hour
Compare:  hash_equals()
```

Successful password reset should:

- Hash the new password with Argon2id.
- Clear reset-code fields.
- Clear active session tokens.
- Log the event without logging the code or password.

### Rate limiting

The application uses a database-backed per-IP rate limiter.

Typical limits:

| Scope | Limit | Window |
| --- | ---: | --- |
| `login` | 8 attempts | 5 minutes |
| `register` | 3 attempts | 30 minutes |
| `verify_email` | 5 attempts | 15 minutes |
| `resend_verification` | 3 attempts | 30 minutes |
| `forget_password` | 3 attempts | 30 minutes |
| `reset_password` | 5 attempts | 30 minutes |
| `create_writeup` | 20 attempts | 1 hour |
| `upload_photo` | 10 attempts | 1 hour |

The server should return HTTP `429` with a `Retry-After` header when a limit is hit.

### CSRF and same-origin checks

State-changing endpoints should enforce:

- `POST` method.
- Same-origin request checks using `Origin` and/or `Referer`.
- Cookie credentials only for same-origin requests.
- HTTPS in production.

SameSite cookies reduce CSRF risk, but do not replace server-side checks.

### HTTPS enforcement

Sensitive endpoints should reject insecure production requests.

Production HTTPS headers:

```text
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: same-origin
```

Only enable HSTS preload after confirming all subdomains support HTTPS.

### Content Security Policy

A practical CSP for this static frontend is same-origin only:

```apache
Header always set Content-Security-Policy "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; connect-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'"
```

Notes:

- Remove `'unsafe-inline'` from `style-src` after moving all inline styles into CSS files.
- Avoid external fonts/scripts unless CSP is updated intentionally.
- Do not use broad wildcards such as `*`.

### File upload security

Profile-photo uploads should enforce:

```text
Allowed extensions: jpg, jpeg, png
Maximum size:       500 KB
Validation layers:  extension + getimagesize() + finfo MIME check
Filename:           random server-generated name
Storage path:       fixed upload directory
Database path:      relative safe path only
PHP execution:      blocked by Apache config and .htaccess
```

Never trust the original uploaded filename.

### Logging and audit trail

Security logs should include:

- Timestamp.
- Severity.
- Event type.
- IP address.
- User ID when available.
- Safe metadata.

Security logs must not include:

- Passwords.
- SMTP passwords.
- Session tokens.
- Reset codes.
- Verification codes.
- Full uploaded file contents.

Recommended location:

```text
/var/www/NightWatch/logs/security_YYYY-MM-DD.log
```

Recommended permissions:

```text
Directory: 0700
Files:     0600
Owner:     www-data:www-data
```

---

## User flows

### Registration flow

1. User opens `/register`.
2. Browser loads registration page assets.
3. User submits `username`, `email`, and `password`.
4. `POST /functions/register.php` validates input.
5. Server rate-limits by IP.
6. Server checks username and verified email uniqueness.
7. Server hashes password with Argon2id.
8. Server generates a 6-digit verification code.
9. Server stores only the hashed verification code.
10. Server sends code by SMTP.
11. Browser redirects user to `/register/verify`.

Successful response example:

```json
{
  "success": true,
  "message": "Registration successful. Please check your email to verify your account.",
  "email": "user@example.com",
  "requires_email_verification": true,
  "redirect": "/register/verify"
}
```

### Email verification flow

1. User opens `/register/verify`.
2. User enters email and verification code.
3. `POST /functions/verify_email.php` validates input.
4. Server rate-limits attempts.
5. Server hashes submitted code and compares with stored hash using `hash_equals()`.
6. Server rejects expired codes.
7. Server marks `email_verified = 1`.
8. Server clears verification code fields.
9. Server creates an authenticated session.
10. Browser redirects to `/panel`.

### Resend verification flow

1. User clicks resend code.
2. Browser sends `POST /functions/resend_verification.php`.
3. Server rate-limits resend attempts.
4. Server validates email.
5. Server rejects already verified accounts.
6. Server creates a fresh code and expiry.
7. Server stores the hashed code.
8. Server sends the code by SMTP.

### Login flow

1. User opens `/login`.
2. Browser submits username/email and password to `POST /functions/login.php`.
3. Server rate-limits login attempts.
4. Server loads user by username or email.
5. Server verifies password hash.
6. Server rejects unverified accounts.
7. Server creates random session token.
8. Server stores token hash in database.
9. Server sets secure HttpOnly cookie.
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
2. Token hash matches database.
3. Token has not expired.
4. User account still exists.
5. User email is verified.

If validation fails, the backend clears the session and returns logged-out status.

### Logout flow

```text
POST /functions/logout.php
```

Logout should:

- Clear database token fields.
- Clear browser cookie.
- Destroy PHP session.
- Return JSON success response.

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

- Always return a generic success-style message to avoid account enumeration.
- If email exists, create reset code, store hashed code, set expiry, and send email.
- Log the event safely.

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

- Validate code format.
- Compare hashed submitted code using `hash_equals()`.
- Reject expired code.
- Hash new password with Argon2id.
- Clear reset fields.
- Clear active session token.
- Return JSON success response.

### Public writeup feed

```text
GET /functions/get_writeups.php?limit=12
GET /functions/get_writeups.php?limit=12&q=search-term
```

Behavior:

- Returns published writeups.
- Supports optional search.
- Includes safe author/profile-photo data.
- Should not expose private user fields.

### Create writeup flow

```text
POST /functions/create_writeup.php
```

Expected fields:

```text
title
content_md
```

Behavior:

- Requires valid session.
- Rate-limits writeup creation.
- Validates title and Markdown body length.
- Creates plain-text excerpt.
- Saves as published writeup.
- Returns new writeup metadata.

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
- Rate-limits upload attempts.
- Enforces size limit.
- Validates image extension and MIME.
- Generates random filename.
- Writes file to upload directory.
- Updates database with new relative path.
- Deletes old profile photo only after database update succeeds.
- Returns safe relative path.

---

## API endpoint reference

All endpoints return JSON.

| Endpoint | Method | Auth required | Purpose |
| --- | --- | --- | --- |
| `/functions/register.php` | POST | No | Create unverified account and send verification code. |
| `/functions/verify_email.php` | POST | No | Verify email code and sign user in. |
| `/functions/resend_verification.php` | POST | No | Send a fresh verification code. |
| `/functions/login.php` | POST | No | Verify credentials and create session. |
| `/functions/session.php` | GET | Cookie for success | Check current login state. |
| `/functions/logout.php` | POST | Cookie optional | Clear auth token and PHP session. |
| `/functions/panel.php` | GET | Yes | Load authenticated panel data. |
| `/functions/get_writeups.php` | GET | No | Load published writeups and optional search results. |
| `/functions/create_writeup.php` | POST | Yes | Publish a new writeup. |
| `/functions/upload_photo.php` | POST | Yes | Upload profile photo. |
| `/functions/get_users.php` | GET | Yes | Return safe public user profile data. |
| `/functions/forget_password.php` | POST | No | Start password reset flow. |
| `/functions/reset_password.php` | POST | No | Verify reset code and set new password. |

### Common response format

Success:

```json
{
  "success": true,
  "message": "Operation completed."
}
```

Validation error:

```json
{
  "success": false,
  "message": "Invalid input."
}
```

Unauthenticated:

```json
{
  "success": false,
  "message": "Authentication required."
}
```

Rate limited:

```json
{
  "success": false,
  "message": "Too many attempts. Please try again later."
}
```

### Recommended HTTP status codes

| Code | Meaning |
| ---: | --- |
| `200` | Successful read or operation. |
| `201` | Resource created. |
| `400` | Bad input. |
| `401` | Authentication required. |
| `403` | Forbidden or same-origin/HTTPS failure. |
| `404` | Resource not found. |
| `405` | Wrong HTTP method. |
| `413` | Uploaded file too large. |
| `415` | Unsupported upload type. |
| `429` | Rate limit reached. |
| `500` | Server-side error. |

---

## Database schema reference

### `users`

Stores account, authentication, verification, reset, and profile-photo data.

Important columns:

```text
id                                      INT UNSIGNED PRIMARY KEY
username                                VARCHAR(50) UNIQUE
email                                   VARCHAR(255) UNIQUE
password                                VARCHAR(255)
token                                   CHAR(64)
token_expires_at                        TIMESTAMP NULL
created_at                              TIMESTAMP
photo_path                              VARCHAR(255)
email_verified                          TINYINT(1)
email_verification_code                 VARCHAR(64)
email_verification_code_expires_at      TIMESTAMP NULL
reset_code                              VARCHAR(64)
reset_code_expires_at                   TIMESTAMP NULL
```

Notes:

- `password` stores an Argon2id hash.
- `token` stores a SHA-256 hash of the browser token.
- `email_verification_code` should store a hash, not plaintext.
- `reset_code` should store a hash, not plaintext.
- `photo_path` should store a safe relative path.

### `writeups`

Stores published Markdown writeups.

Important columns:

```text
id              BIGINT UNSIGNED PRIMARY KEY
user_id         INT UNSIGNED
title           VARCHAR(140)
content_md      MEDIUMTEXT
excerpt         VARCHAR(280)
status          ENUM('published', 'draft')
created_at      TIMESTAMP
updated_at      TIMESTAMP
published_at    TIMESTAMP
```

Recommended indexes:

```text
INDEX user_id
INDEX status
FULLTEXT INDEX idx_writeups_search (title, excerpt, content_md)
```

### `rate_limits`

Stores per-IP rate-limit windows.

Important columns:

```text
id              INT UNSIGNED PRIMARY KEY
scope           VARCHAR(50)
ip_address      VARCHAR(45)
attempt_count   INT
window_start    TIMESTAMP
window_end      TIMESTAMP
created_at      TIMESTAMP
```

Recommended unique key:

```text
UNIQUE(scope, ip_address)
```

Expired rows should be cleaned automatically during normal requests.

---

## Operational commands

### Check PHP syntax

```bash
find WebApp/functions -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Check JavaScript syntax

```bash
node --check WebApp/js/auth.js
find WebApp -name '*.js' -print0 | xargs -0 -n1 node --check
```

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
sudo tail -f /var/www/NightWatch/logs/security_*.log
```

### Check upload permissions

```bash
sudo -u www-data test -w /var/www/NightWatch/WebApp/uploads/photos && echo "Writable"
```

### Check database connection manually

```bash
mysql -u nightwatch_app -p NightWatchDB -e "SHOW TABLES;"
```

### Backup database

```bash
mysqldump -u root -p NightWatchDB > nightwatch_backup_$(date +%F).sql
```

### Restore database

```bash
mysql -u root -p NightWatchDB < nightwatch_backup_YYYY-MM-DD.sql
```

---

## Testing and validation

### Static validation checklist

Run before deployment:

```bash
php -v
php -m
find WebApp/functions -name '*.php' -print0 | xargs -0 -n1 php -l
find WebApp -name '*.js' -print0 | xargs -0 -n1 node --check
sudo apache2ctl configtest
```

### Manual browser test checklist

Test these flows after deploying:

- Open `/`.
- Open `/register`.
- Register a new account.
- Receive verification email.
- Verify account.
- Confirm redirect to `/panel`.
- Log out.
- Log in again.
- Create a writeup.
- Confirm writeup appears on public feed.
- Search writeups.
- Upload a JPG profile photo.
- Upload a PNG profile photo.
- Confirm `.php` upload is rejected.
- Request password reset.
- Reset password.
- Confirm old session is invalidated.

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
/assets/nightwatch-logo.svg
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
sudo tail -f /var/www/NightWatch/logs/security_*.log
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
sudo ls -la /var/www/NightWatch/WebApp/uploads/photos
sudo -u www-data test -w /var/www/NightWatch/WebApp/uploads/photos && echo "Writable"
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

Expected example:

```text
/uploads/photos/profile_abc123.png
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
- Missing Apache `rewrite` module.
- Wrong file permissions.
- Missing PHP extension.
- DB config not readable by Apache user.

---

## Production checklist

Before going live:

```text
[ ] Apache document root points to WebApp/.
[ ] HTTPS certificate is installed and valid.
[ ] Apache rewrite, headers, and ssl modules are enabled.
[ ] DB password is changed from CHANGE_ME_STRONG_PASSWORD.
[ ] DB credentials are provided through environment variables or secure config.
[ ] SMTP credentials are configured through environment variables.
[ ] /var/www/config/db_config.php is root:www-data with 0640 permissions.
[ ] logs/ is outside the public document root and owned by www-data.
[ ] uploads/photos/ is writable by www-data.
[ ] PHP execution is blocked inside uploads/.
[ ] .htaccess files are enabled with AllowOverride All.
[ ] PHP errors are not displayed to users in production.
[ ] Security headers are present.
[ ] Registration email arrives.
[ ] Password reset email arrives.
[ ] Profile-photo upload works.
[ ] Invalid upload types are rejected.
[ ] Login, logout, and session expiry work.
[ ] Public writeup feed works.
[ ] Database backups are scheduled.
[ ] Log rotation/retention is configured.
```

---

## Recommended next improvements

These are not required for basic deployment, but they would improve maintainability and security.

### High priority

- Add CSRF tokens in addition to same-origin checks.
- Add database migrations instead of a single all-in-one SQL file.
- Add automated tests for each endpoint.
- Add structured JSON logging.
- Add upload image re-encoding to strip metadata and normalize content.
- Move profile photos outside the document root and serve through a controlled endpoint.
- Add admin moderation for public writeups.
- Add account lockout or progressive delay for repeated login failures.
- Add email change flow with verification.

### Medium priority

- Add Composer autoloading.
- Split shared helpers into smaller files/classes.
- Add a `.env.example` file.
- Add Docker Compose for local development.
- Add CI checks for PHP lint, JS lint, and shell syntax.
- Add a migration for legacy plaintext verification/reset codes if older data exists.
- Add pagination metadata for writeup feed.
- Add Markdown sanitization preview rules and a clear allowed-Markdown policy.

### Lower priority

- Add profile-photo cropping.
- Add user profile pages.
- Add draft writeup support in UI.
- Add tags/categories for writeups.
- Add sitemap and robots.txt.
- Add Open Graph metadata for public writeups.

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
*.sql
*.sql.gz
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
sudo apt install -y apache2 mysql-server php php-cli php-mysql php-mbstring php-gd php-curl php-xml unzip openssl
sudo a2enmod rewrite headers ssl

sudo mkdir -p /var/www/NightWatch
sudo rsync -av ./NightWatch/ /var/www/NightWatch/

cd /var/www/NightWatch

openssl rand -base64 32
# Put the generated value into DB/DB.sql and NIGHTWATCH_DB_PASSWORD.

mysql -u root -p < DB/DB.sql

sudo ./setup.sh

sudo apache2ctl configtest
sudo systemctl reload apache2
```

Then open:

```text
https://example.com/
```

Register a test user, verify email delivery, upload a small JPG/PNG profile photo, create a writeup, and confirm the public feed displays it.

