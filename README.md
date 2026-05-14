# NightWatch PHP Web Application

Last updated: 2026-05-14

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

### Logo loading

All old logo references pointed to a missing directory like:

```text
../logo/icon_png/nightwatch_icon_128x128_transparent.png
../../logo/favicon/nightwatch_favicon_transparent.ico
```

The app now uses files that actually exist inside `WebApp/`:

```text
/favicon.ico
/assets/nightwatch-logo.svg
```

### Broken auth script paths

The register and password-reset pages were loading:

```html
<script src="js/auth.js"></script>
```

From `/register` or `/forgetpassword`, that resolves to missing paths such as `/register/js/auth.js`. They now load:

```html
<script src="../js/auth.js"></script>
```

### Registration and verification flow

Registration now handles case-insensitive duplicates cleanly and avoids a generic insert failure when someone registers an existing username/email with different letter casing.

A real resend endpoint was added:

```text
POST /functions/resend_verification.php
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
```

Rules:

```text
Allowed extensions: jpg, jpeg, png
Allowed MIME types: image/jpeg, image/jpg, image/png
Maximum file size: 500 KB
Upload folder: WebApp/uploads/photos/
Stored DB path format: uploads/photos/<filename>
```

The backend validates the file extension, PHP upload status, file size, `getimagesize()` result, and `finfo` MIME result. Old photos are deleted only after confirming they are inside the uploads directory.

## Password reset flow

The user opens:

```text
/forgetpassword
```

### Step 1: Request reset code

The page sends:

```text
POST /functions/forget_password.php
```

Expected form field:

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
/assets/nightwatch-logo.svg
```

If the document root is the project parent instead of `WebApp/`, root-relative paths like `/functions/register.php` will also fail. Fix the document root rather than changing every URL.

### Register button shows network error

Open the browser dev tools Network tab and check:

```text
POST /functions/register.php
```

Common causes:

```text
404: document root is wrong
405: request is not POST
422: invalid form data
429: rate limit hit
500: database config, database connection, or mail setup problem
```

### Registration succeeds but no email arrives

Check:

```text
/tmp/nightwatch_mail_log.txt
/tmp/nightwatch_security_log.txt
```

Also confirm that PHP `mail()` works on the server. On many local machines and fresh VPS deployments, `mail()` does not work until a mail transfer agent or relay is configured.

### User cannot log in after registration

This is expected until email verification succeeds. Check the `users` table:

```sql
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
