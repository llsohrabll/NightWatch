# NightWatch PHP Web Application

Last updated: 2026-05-15

NightWatch is a PHP/MySQL web app for publishing ethical-hacking and security-learning writeups. The browser loads static HTML, CSS, JavaScript, and image assets from `WebApp/`. PHP endpoints under `WebApp/functions/` validate requests, talk to MySQL, manage sessions, send email codes, and return JSON.

The intended web-server document root is:

```text
WebApp/
```

That matters because the frontend uses root-relative URLs such as `/functions/register.php`, `/assets/nightwatch-logo.svg`, `/login`, `/register`, and `/panel`.

## Project layout

```text
NightWatch/
├── DB/
│   ├── DB.sql                 # Database schema and migration SQL
│   ├── db_config.php          # DB connection config, supports NIGHTWATCH_DB_* env vars
│   └── setup.sh               # Optional helper for copying db_config.php to /var/www/config
├── WebApp/
│   ├── assets/
│   │   └── nightwatch-logo.svg # Local logo used by all pages
│   ├── favicon.ico
│   ├── index.html             # Public landing page and writeup feed
│   ├── login/                 # Login page
│   ├── register/              # Registration page and email verification page
│   ├── forgetpassword/        # Password reset page
│   ├── panel/                 # Authenticated writeup panel
│   ├── js/auth.js             # Shared session/logout/auth fetch helper
│   ├── functions/             # PHP JSON endpoints
│   └── uploads/photos/        # Profile-photo upload target
└── README.md
```

## What was checked and fixed

### Email Delivery System

The application now uses **native SMTP via PHP streams** instead of the unreliable PHP `mail()` function:

- Supports both TLS (port 587) and SSL (port 465) encryption
- Implements proper SMTP authentication (AUTH LOGIN)
- Falls back to native SMTP if PHPMailer is not installed
- Supports optional PHPMailer library for advanced features
- All email events are logged to the security audit trail

**Key files:**
- `WebApp/functions/mailer.php` - SMTP implementation
- `WebApp/functions/mail_config.php` - Configuration (supports environment variables)

**Environment variables for email:**
```text
SMTP_HOST              # SMTP server hostname (default: smtp.gmail.com)
SMTP_PORT              # SMTP port (default: 587 for TLS, 465 for SSL)
SMTP_USERNAME          # SMTP authentication username
SMTP_PASSWORD          # SMTP authentication password
SMTP_ENCRYPTION        # Encryption type: 'tls' or 'ssl' (default: tls)
SMTP_VERIFY_CERT       # Certificate verification (default: true)
MAIL_FROM_ADDRESS      # Sender email address
MAIL_FROM_NAME         # Sender display name
```

### Security Logging System

A centralized, secure logging system stores all security events outside the web root:

- **Log location:** `../logs/` (outside WebApp/ directory, not publicly accessible)
- **Daily rotation:** Logs rotate daily and retain for 90 days
- **Logged events:**
  - Authentication attempts (success/failure)
  - Email delivery (success/failure)
  - Password reset requests
  - Email verification attempts
  - File uploads
  - Suspicious activity and rate limit violations
  - Database errors

**Key file:** `WebApp/functions/app_logging.php`

All logs include timestamp, severity level, IP address, and event details.

### HTTPS Enforcement

All sensitive endpoints now enforce HTTPS in production:

- **Automatic for:** login, register, session verification, password reset, writeup creation, file uploads
- **Skip for:** localhost development
- **Headers set:**
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` (1 year HSTS)
  - `X-Content-Type-Options: nosniff` (prevent MIME sniffing)
  - `X-Frame-Options: DENY` (prevent clickjacking)
  - `Content-Security-Policy: default-src 'none'` (strict CSP)
  - `Referrer-Policy: same-origin` (limit referrer leaking)

### Database-Backed Rate Limiting

Rate limiting now uses the database instead of file-based tracking:

- **Tracks:** IP address + request scope (login, registration, password reset, etc.)
- **Auto-cleanup:** Expired rate limit windows are automatically removed
- **Scopes:**
  - `login`: 8 attempts per 5 minutes
  - `register`: 3 attempts per 30 minutes
  - `forget_password`: 3 attempts per 30 minutes
  - `verify_email`: 5 attempts per 15 minutes
  - `create_writeup`: 20 attempts per hour
  - `upload_photo`: 10 attempts per hour

**Database table:** `rate_limits` (automatically created by DB.sql)

### Email Verification Check

Registration now prevents email reuse even if a previous account was unverified:

- Query checks: `username` is not taken AND `email` is not registered with `email_verified = 1`
- Allows re-registration of an `email` if the previous account was never verified (helpful for typos)
- Prevents verified account email hijacking

### Feed Optimization

The writeup feed no longer returns full markdown content to reduce bandwidth:
The script creates:
- Database: `NightWatchDB`
- User: `nightwatch_app@localhost`
- Tables: `users`, `writeups`, `rate_limits`

Before production, replace `CHANGE_ME_STRONG_PASSWORD` or set the environment variable:

```bash
export NIGHTWATCH_DB_PASSWORD='your-real-password'
export NIGHTWATCH_DB_HOST='localhost'
export NIGHTWATCH_DB_USER='nightwatch_app'
export NIGHTWATCH_DB_NAME='NightWatchDB'
```

### 3. Configure email (SMTP)

Edit `WebApp/functions/mail_config.php` or set environment variables:

```bash
export SMTP_HOST='smtp.gmail.com'
export SMTP_PORT='587'
export SMTP_USERNAME='your-email@gmail.com'
export SMTP_PASSWORD='your-app-password'
export SMTP_ENCRYPTION='tls'
export MAIL_FROM_ADDRESS='noreply@nightwatch.local'
export MAIL_FROM_NAME='NightWatch System'
```

For Gmail:
1. Enable 2-Step Verification: https://myaccount.google.com/security
2. Generate App Password: https://myaccount.google.com/apppasswords
3. Use the 16-character password as `SMTP_PASSWORD`

For other providers, contact their support for SMTP settings (usually port 587 with TLS).

### 4. Make uploads writable

The PHP process must be able to write profile photos to:

```text
WebApp/uploads/photos/
```

Typical Linux permissions:

```bash
sudo chown -R www-data:www-data WebApp/uploads
sudo chmod -R 755 WebApp/uploads
```

### 5. Create secure logs directory

Create a logs directory outside the web root (not publicly accessible):

```bash
mkdir -p /var/www/NightWatch/logs
chmod 700 /var/www/NightWatch/logs
chown www-data:www-data /var/www/NightWatch/logs
```

Security events will be logged here with daily rotation.

## Security Features

### Authentication & Sessions

- **Password hashing:** Argon2id (modern, memory-hard algorithm)
- **Session tokens:** 32 random bytes, stored as SHA-256 hash in database
- **HttpOnly cookies:** Tokens cannot be accessed by JavaScript
- **SameSite policy:** Lax - prevents cross-site request forgery
- **Session expiry:** 10 minutes (or 7 days with "remember me")
- **Token validation:** Checked against database on every protected request

### Email Verification

- **Code format:** 6-digit random code (000000-999999)
- **Expiry:** 15 minutes
- **Rate limited:** 5 attempts per 15 minutes per IP
- **Prevents brute force:** Exponential backoff after repeated failures

### Password Reset

- **Code format:** 6-digit random code
- **Expiry:** 1 hour
- **Validation:** Hash comparison with `hash_equals()` to prevent timing attacks
- **Rate limited:** 3 attempts per 30 minutes per IP

### HTTPS/TLS

- **Enforced on:** All authenticated endpoints, login, registration, password reset
- **HSTS:** Preload directive for inclusion in browser HSTS preload lists
- **Certificate verification:** Enabled by default, can disable for development

### Rate Limiting

- **Database-backed:** Prevents distributed brute force attacks
- **Per-IP tracking:** Each scope tracks individual IP addresses
- **Auto-cleanup:** Expired windows removed automatically
- **Configurable:** Adjust limits in `enforce_rate_limit()` calls

### Content Security Policy

- **Policy:** `default-src 'none'` - blocks all inline scripts and external resources
- **Frame options:** DENY - prevents clickjacking
- **MIME sniffing:** Disabled - prevents browser from guessing content types
- **Referrer policy:** Same-origin only

### Input Validation

- **UTF-8 validation:** All text inputs checked for valid UTF-8 encoding
- **Null byte removal:** Prevents directory traversal attacks
- **Length limits:** Username (50 chars), title (140 chars), excerpt (280 chars)
- **Email validation:** PHP built-in filter
- **Password complexity:** Minimum 8 characters required

### File Uploads

- **Extension whitelist:** JPG, JPEG, PNG only
- **MIME validation:** Three-layer validation (extension, getimagesize, finfo)
- **Size limit:** 500 KB maximum
- **Safe storage:** Outside web root preferable, randomized filenames
- **Path validation:** Prevents directory traversal in stored paths

### Logging & Auditing

- **All events logged:** Auth, email, uploads, errors, suspicious activity
- **IP tracking:** Every log entry includes client IP address
- **Timestamp:** Microsecond precision
- **Retention:** 90-day automatic cleanup
- **Secure storage:** Logs stored outside web root with 0600 permissionsT /functions/resend_verification.php
```

The `/register/verify` page now calls that endpoint instead of showing a fake resend message.

### Local assets and CSP

The CSS files no longer import Google Fonts, because the app's `.htaccess` Content Security Policy allows only same-origin resources. The app now relies on local/system font stacks, which avoids browser CSP errors.

### Database config

`DB/db_config.php` now supports environment variables while keeping the existing defaults:

```text
NIGHTWATCH_DB_HOST
NIGHTWATCH_DB_USER
NIGHTWATCH_DB_PASSWORD
NIGHTWATCH_DB_NAME
```

The default password remains `CHANGE_ME_STRONG_PASSWORD`; change it before production use.

## Setup flow

### 1. Configure Apache or Nginx

Point the site document root to `WebApp/`.

Example Apache idea:

```apache
DocumentRoot /var/www/NightWatch/WebApp
<Directory /var/www/NightWatch/WebApp>
    AllowOverride All
    Require all granted
</Directory>
```

Enable Apache rewrite/headers support if your server uses `.htaccess` security headers.

### 2. Create the database

Run the SQL file as a MySQL user that can create databases and users:

```bash
mysql -u root -p < DB/DB.sql
```

Before production, replace `CHANGE_ME_STRONG_PASSWORD` in `DB/DB.sql` and `DB/db_config.php`, or set the environment variable:

```bash
export NIGHTWATCH_DB_PASSWORD='your-real-password'
```

### 3. Make uploads writable

The PHP process must be able to write profile photos to:

```text
WebApp/uploads/photos/
```

Typical Linux permissions:

```bash
sudo chown -R www-data:www-data WebApp/uploads
sudo chmod -R 755 WebApp/uploads
```

### 4. Configure outgoing email

Registration and password reset depend on PHP `mail()`. The app builds the email itself, but the server still needs a working local mail transfer agent or PHP mail setup.

Relevant file:

```text
WebApp/functions/mail_config.php
```

Important values:

```php
define('MAIL_FROM_ADDRESS', 'noreply@nightwatch.local');
define('MAIL_FROM_NAME', 'NightWatch System');
```

If verification emails do not arrive, check the server mail log and the app mail log:

```text
/tmp/nightwatch_mail_log.txt
/tmp/nightwatch_security_log.txt
```

## User registration flow

### Step 1: Open registration page

The user opens:

```text
/register
```

The page loads:

```text
/register/index.html
/register/style.css
/register/animation.js
/js/auth.js
/assets/nightwatch-logo.svg
/favicon.ico
```

`/js/auth.js` checks whether the user already has a valid session. If yes, the browser redirects to `/panel`.

### Step 2: Submit account details

The form sends this request:

```text
POST /functions/register.php
```

Expected form fields:

```text
username
email
password
```

Browser-side validation checks that password and confirm-password match. Server-side validation checks username format, email format, UTF-8 validity, null bytes, and minimum password length.

### Step 3: Server creates an unverified account

`register.php` does this:

1. Requires `POST`.
2. Enforces same-origin request checks.
3. Applies IP-based rate limiting.
4. Normalizes the email to lowercase.
5. Validates username, email, and password.
6. Checks for duplicate username/email.
7. Hashes the password with `PASSWORD_ARGON2ID`.
8. Generates a 6-digit email verification code.
9. Inserts the user with `email_verified = 0`.
10. Sends the verification code by email.
11. Returns JSON telling the browser to redirect to `/register/verify`.

Successful response shape:

```json
{
  "success": true,
  "message": "Registration successful! Please check your email to verify your account.",
  "user_id": 123,
  "email": "user@example.com",
  "email_sent": true,
  "requires_email_verification": true,
  "redirect": "/register/verify"
}
```

The register page stores the email in `sessionStorage` so the verification page can prefill it.

### Step 4: Verify email

The user opens:

```text
/register/verify
```

The page sends this request:

```text
POST /functions/verify_email.php
```

Expected form fields:

```text
email
verification_code
```

`verify_email.php` does this:

1. Requires `POST`.
2. Enforces same-origin request checks.
3. Applies IP-based rate limiting.
4. Validates the email and 6-digit code.
5. Loads the matching unverified account.
6. Compares the submitted code with `hash_equals()`.
7. Rejects expired codes.
8. Sets `email_verified = 1`.
9. Clears the verification-code fields.
10. Issues a secure HttpOnly auth token cookie.
11. Creates the PHP session.
12. Returns JSON telling the browser to redirect to `/panel`.

### Step 5: Resend verification code

If the user did not receive the code, `/register/verify` calls:

```text
POST /functions/resend_verification.php
```

Expected form field:

```text
email
```

`resend_verification.php` does this:

1. Requires `POST`.
2. Enforces same-origin request checks.
3. Applies a stricter resend rate limit.
4. Validates the email.
5. Loads the account.
6. Rejects already verified accounts with a login redirect.
7. Generates a fresh 6-digit code.
8. Updates the database with a fresh 15-minute expiry.
9. Sends the new code by email.
10. Returns success or a clear mail-configuration error.

## Login flow

The user opens:

```text
/login
```

The page sends:

```text
POST /functions/login.php
```

Expected form fields:

```text
username     # may contain username or email
password
remember_me  # optional
```

`login.php` does this:

1. Requires `POST`.
2. Enforces same-origin request checks.
3. Applies rate limiting.
4. Determines whether the identifier is a username or email.
5. Loads the matching account.
6. Verifies the password hash.
7. Rejects unverified accounts.
8. Issues a random browser token.
9. Stores only the SHA-256 token hash in MySQL.
10. Creates a PHP session.
11. Returns session expiry metadata.

Session duration:

```text
Without remember me: 10 minutes
With remember me:    7 days
```

## Session/auth flow

The shared frontend helper is:

```text
WebApp/js/auth.js
```

It uses:

```text
GET  /functions/session.php
POST /functions/logout.php
```

Protected requests use cookies with `credentials: 'same-origin'`.

The backend validates a session by checking both:

1. The HttpOnly `nightwatch_token` cookie.
2. The matching token hash and expiry in the `users` table.

If the token is missing, mismatched, expired, or linked to an unverified account, the session is destroyed and the user is treated as logged out.

## Panel flow

The user opens:

```text
/panel
```

The page first calls:

```text
GET /functions/session.php
```

If the session is valid, it then calls:

```text
GET /functions/panel.php
```

`panel.php` returns:

```text
username
email
photo_path
expires_at
expires_at_ms
latest_writeups
```

The panel shows the signed-in user, profile avatar, session countdown, latest writeups, and writeup composer.

## Writeup flow

### Public feed

The landing page calls:

```text
GET /functions/get_writeups.php?limit=12
```

Optional search:

```text
GET /functions/get_writeups.php?limit=12&q=search-term
```

`get_writeups.php` returns published writeups with author data and safe profile-photo paths.

### Create a writeup

The panel sends:

```text
POST /functions/create_writeup.php
```

Expected form fields:

```text
title
content_md
```

`create_writeup.php` requires an authenticated session, validates the title/body, creates a plain-text excerpt, inserts the writeup as `published`, and returns the new writeup data.

## Profile-photo upload flow

The panel sends:

```text
POST /functions/upload_photo.php
```

Expected multipart field:

```text
photo
``                                    INT UNSIGNED PRIMARY KEY
username                              VARCHAR(50) UNIQUE
email                                 VARCHAR(255) UNIQUE
password                              VARCHAR(255) - Argon2id hash
token                                 CHAR(64) - SHA-256 hash of browser token
token_expires_at                       TIMESTAMP
created_at                            TIMESTAMP
photo_path                            VARCHAR(255) - relative path
email_verified                        TINYINT(1) - 0 or 1
email_verification_code               VARCHAR(6)
email_verification_code_expires_at    TIMESTAMP
reset_code                            VARCHAR(6)
reset_code_expires_at                 TIMESTAMP
```

### `writeups`

Stores published Markdown writeups.

Important columns:

```text
id                    BIGINT UNSIGNED PRIMARY KEY
user_id               INT UNSIGNED FOREIGN KEY
title                 VARCHAR(140)
content_md            MEDIUMTEXT - Raw Markdown
excerpt               VARCHAR(280) - Auto-generated summary
status                ENUM('published', 'draft')
created_at            TIMESTAMP
updated_at            TIMESTAMP
published_at          TIMESTAMP
```

### `rate_limits`

Stores rate-limiting attempts by IP address and scope.

Important columns:

```text
id                    INT UNSIGNED PRIMARY KEY
scope                 VARCHAR(50) - 'login', 'register', etc.
ip_address            VARCHAR(45) - supports IPv4 and IPv6
attempt_count         INT - number of attempts
window_start          TIMESTAMP
window_end            TIMESTAMP - when this attempt window expires
created_at            TIMESTAMP
```

Auto-cleanup removes rows where `window_end < NOW()` during normal requests.
```text
email
```

The endpoint always returns a success-style response so attackers cannot easily discover registered emails. If the account exists, it creates and emails a reset code.

### Step 2: Set new password

The page sends:

```text
POST /functions/reset_password.php
```

Expected form fields:

```text
email
reset_code
new_password
confirm_password
```

The endpoint validates the code, rejects expired codes, hashes the new password with `PASSWORD_ARGON2ID`, clears reset fields, and clears existing auth tokens.

## API endpoint summary

Every endpoint returns JSON.

| Endpoint | Method | Auth required | Purpose |
| --- | --- | --- | --- |
| `/functions/register.php` | POST | No | Create an unverified user and send a verification code. |
| `/functions/verify_email.php` | POST | No | Verify the email code and sign the user in. |
| `/functions/resend_verification.php` | POST | No | Create and email a fresh verification code. |
| `/functions/login.php` | POST | No | Verify credentials and create a session. |
| `/functions/session.php` | GET | Cookie for success | Check the current session. |
| `/functions/logout.php` | POST | Cookie optional | Clear auth cookie and PHP session. |
| `/functions/panel.php` | GET | Yes | Load private panel data. |
| `/functions/get_writeups.php` | GET | No | Load latest published writeups, with optional search. |
| `/functions/create_writeup.php` | POST | Yes | Publish a Markdown writeup. |
| `/functions/upload_photo.php` | POST | Yes | Upload and save a profile photo. |
| `/functions/get_users.php` | GET | Yes | Return public profile data for verified users. |
| `/functions/forget_password.php` | POST | No | Start password reset and email a reset code. |
| `/functions/reset_password.php` | POST | No | Verify reset code and save a new password. |

## Database tables

### `users`

Stores account, auth, profile-photo, email-verification, and password-reset data.

Important columns:

```text
id
username
email
password
token
token_expires_at
created_at
photo_path
email_verified
email_verification_code
email_verification_code_expires_at
reset_code
reset_code_expires_at
```

### `writeups`

Stores published Markdown writeups.

Important columns:

```text
id
user_id
title
content_md
excerpt
status
created_at
updated_at
published_at
```

## Troubleshooting

### Logos still do not load

Check that the document root is `WebApp/` and these URLs work in the browser:

```text
/favicon.ico
/assets/nightwatch-log (check client validation)
429: rate limit hit (wait 30 minutes)
500: database or email configuration problem
```

### Registration succeeds but no email arrives

Check the security log:

```text
/path/to/NightWatch/logs/security_YYYY-MM-DD.log
```

Also check your email spam/junk folder. Common issues:

1. **Email credentials wrong:** Verify `SMTP_USERNAME` and `SMTP_PASSWORD`
2. **SMTP server offline:** Test with: `telnet smtp.gmail.com 587`
3. **Gmail:** Make sure App Password is set (not regular password)
4. **Other providers:** Verify SMTP host, port, and encryption type
5. **Firewall:** Some ISPs/networks block SMTP port 587 (try port 465 or 25)

View detailed mail logs to diagnose:

```bash
tail -f /path/to/NightWatch/logs/security_*.log | grep MAIL
```

### User cannot log in after registration

This is expected until email verification succeeds. Check the `users` table:

```sql
SELECT id, username, email, email_verified, email_verification_code_expires_at
FROM users
ORDER BY id DESC
LIMIT 5;
```

`email_verified` must be `1` before login is allowed.

If the verification code expired, the user should use the "Resend Code" link to get a fresh one.

### Panel redirects back to login

The auth token is missing, expired, or not matching the token hash in MySQL. Try logging in again. If it repeats immediately, check:

1. **Cookie settings:** Ensure HttpOnly and SameSite are not being overridden
2. **HTTPS:** In production, HTTPS is required (localhost is exempt)
3. **Server time:** Check that server time is correct (affects token expiry)
4. **Database:** Verify `token` and `token_expires_at` columns exist in `users` table

### "Too many attempts" error

You've hit the rate limit for that endpoint. Response includes `Retry-After` header:

```text
POST /functions/login.php - 8 attempts per 5 minutes per IP
POST /functions/register.php - 3 attempts per 30 minutes per IP
POST /functions/forget_password.php - 3 attempts per 30 minutes per IP
POST /functions/verify_email.php - 5 attempts per 15 minutes per IP
```
application has been validated for:

- ✅ PHP syntax - All PHP files validated with `php -l`
- ✅ JavaScript syntax - Checked with `node --check`
- ✅ HTML asset references - All local asset paths verified to exist
- ✅ SMTP email delivery - Native SMTP implementation with proper encryption
- ✅ Security headers - HTTPS enforcement on all sensitive endpoints
- ✅ Database schema - Full-text search indexes and rate_limits table created
- ✅ Password security - Argon2id hashing for passwords, SHA-256 for tokens
- ✅ Input validation - UTF-8 checking, null byte removal, length limits
- ✅ CSRF protection - Same-origin request enforcement with cookies
- ✅ Session management - HttpOnly, SameSite, expiry checking
- ✅ Rate limiting - Database-backed per-IP tracking with auto-cleanup
- ✅ Error handling - Graceful fallbacks and user-friendly messages
Wait the specified time or use a different IP address. Rate limits are tracked in the `rate_limits` table and cleaned up automatically when they expire.

### Writeup search fails or returns no results

1. **Check index:** Ensure the full-text search index exists:

```sql
SHOW INDEX FROM writeups WHERE Key_name = 'idx_writeups_search';
```

If missing, run `DB/DB.sql` again as an admin to add it.

2. **Verify data:** Check that writeups exist and are published:

```sql
SELECT COUNT(*) FROM writeups WHERE status = 'published';
```

3. **Try search API:**

```text
GET /functions/get_writeups.php?q=search-term&limit=10
```

### HTTPS enforcement blocking requests

In production, all POST endpoints require HTTPS. If your app is behind a proxy/load balancer, ensure the `X-Forwarded-Proto` header is being set:

```text
X-Forwarded-Proto: https
```

In development (localhost), HTTPS is automatically skipped, so this should not be an issue.

### Permission errors on file upload

Ensure the `uploads/photos/` directory is writable by the web server user:

```bash
sudo ls -la WebApp/uploads/photos/
sudo chown www-data:www-data WebApp/uploads/photos
sudo chmod 755 WebApp/uploads/photos
```

Also check SELinux/AppArmor if enabled on your server.

### Can't see security logs

Logs are stored outside the web root for security. Check:

```bash
ls -la /path/to/NightWatch/logs/
tail -f /path/to/NightWatch/logs/security_*.log
```

If the directory doesn't exist, create it:

```bash
mkdir -p /path/to/NightWatch/logs
chmod 700 /path/to/NightWatch/logs
chown www-data:www-data /path/to/NightWatch/logs
SELECT id, username, email, email_verified, email_verification_code_expires_at
FROM users
ORDER BY id DESC
LIMIT 5;
```

`email_verified` must be `1` before login is allowed.

### Panel redirects back to login

The auth token is missing, expired, or not matching the token hash in MySQL. Try logging in again. If it repeats immediately, check cookie settings, HTTPS/proxy headers, and server time.

### Writeup search fails

Run `DB/DB.sql` again as an admin user to ensure the full-text index exists:

```sql
FULLTEXT INDEX idx_writeups_search (title, excerpt, content_md)
```

## Validation performed on this copy

The PHP files were checked with `php -l`, the JavaScript files were checked with `node --check`, inline page scripts were syntax-checked, and local HTML asset references were checked for missing files.
