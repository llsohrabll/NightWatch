# NightWatch Web Application Documentation

Last updated: 2026-05-02

## 1. Purpose

NightWatch is a small PHP web application that provides:

- a public landing page
- login and registration
- email verification during registration
- password reset by emailed code
- a protected user panel
- a public user directory that is visible to authenticated users
- profile photo upload

The application uses server-side PHP sessions as the active authentication mechanism. The `users.token` and `users.token_expires_at` columns are still written for compatibility and audit value, but they are not the source of truth for authorization.

## 2. Current Feature Set

### Public pages

- `/`
  Landing page with Night Watch branding and a single `Login` button.
- `/login`
  Login form for username or email plus password.
- `/register`
  Registration form with username, email, password, and password confirmation.
- `/register/verify`
  Verification page that accepts the 6-digit email verification code after registration.
- `/forgetpassword`
  Two-step password reset page:
  1. request reset code by email
  2. submit reset code and new password

### Protected page

- `/panel`
  Main authenticated experience. It shows:
  - username and avatar
  - registered user count
  - fixed honor level placeholder
  - server uptime
  - session expiry countdown
  - profile photo upload modal
  - authenticated public user directory
  - public profile modal for any registered user

### Backend APIs

- `GET /functions/session.php`
- `POST /functions/login.php`
- `POST /functions/register.php`
- `POST /functions/verify_email.php`
- `POST /functions/forget_password.php`
- `POST /functions/reset_password.php`
- `GET /functions/panel.php`
- `GET /functions/get_users.php`
- `POST /functions/upload_photo.php`
- `POST /functions/logout.php`

## 3. Technology Stack

### Frontend

- static HTML pages
- CSS for styling
- vanilla JavaScript
- shared auth runtime in `js/auth.js`

### Backend

- PHP
- MySQLi for database access
- PHP sessions for authentication
- PHP `mail()` for outbound mail delivery

### Database

- MySQL or MariaDB
- bootstrap schema in `DB/database.sql`

## 4. Runtime Requirements

The current codebase requires:

- PHP with `mysqli`
- PHP session support
- PHP JSON support
- PHP `mail()` configured if email features must work
- writable temp directory for rate-limit and security logs
- writable `uploads/photos/` directory for profile images

Optional capabilities:

- `shell_exec()` on Windows if uptime detection should use `wmic` or `systeminfo`
- `/proc/uptime` on Linux for direct uptime detection

Observed limitation in this workspace:

- `php -l` succeeds for all PHP files
- `node --check` succeeds for JavaScript files
- `php DB/test_db.php` cannot complete here because the local CLI PHP build does not include `mysqli`

## 5. Project Structure

```text
NightWatch/
|-- .gitignore
|-- .htaccess
|-- WEBAPP_DOCUMENTATION.md
|-- Website.xmind
|-- index.html
|-- style.css
|-- animation.js
|-- js/
|   `-- auth.js
|-- login/
|   |-- index.html
|   |-- style.css
|   `-- animation.js
|-- register/
|   |-- index.html
|   |-- style.css
|   |-- animation.js
|   `-- verify/
|       `-- index.html
|-- forgetpassword/
|   |-- index.html
|   |-- style.css
|   `-- animation.js
|-- panel/
|   |-- index.html
|   |-- style.css
|   `-- animation.js
|-- functions/
|   |-- common.php
|   |-- login.php
|   |-- register.php
|   |-- verify_email.php
|   |-- forget_password.php
|   |-- reset_password.php
|   |-- session.php
|   |-- panel.php
|   |-- get_users.php
|   |-- upload_photo.php
|   |-- logout.php
|   |-- mailer.php
|   `-- mail_config.php
|-- DB/
|   |-- .htaccess
|   |-- database.sql
|   |-- db_config.php
|   |-- db_connect.php
|   |-- migrate_add_photo.php
|   |-- setup.sh
|   `-- test_db.php
`-- uploads/
    |-- .htaccess
    |-- .placeholder
    `-- photos/
        |-- .htaccess
        `-- .placeholder
```

## 6. Authentication Model

### Active authentication mechanism

The app authenticates requests with a PHP session cookie named `nightwatch_session`.

Session fields stored in `$_SESSION`:

- `user_id`
- `username`
- `email`
- `expires_at`

### Session lifecycle

- session starts through `start_app_session()` in `functions/common.php`
- cookie params are set before `session_start()`
- session ID is regenerated with `session_regenerate_id(true)` on authenticated session creation
- server-side expiry is enforced by `get_current_session_user()`
- expiry default is `600` seconds (10 minutes)
- expiry can be extended to `604800` seconds (7 days) if user checks "Remember me" during login

### Cookie flags

- `HttpOnly = true`
- `SameSite = Lax`
- `Secure = true` when the request is HTTPS
- HTTPS detection also checks `HTTP_X_FORWARDED_PROTO=https`

### Token columns

`login.php` and `register.php` still generate and store:

- `token`
- `token_expires_at`

These values are not consulted by `session.php`, `panel.php`, `get_users.php`, or `upload_photo.php`. Authorization is based on PHP session state only.

## 6a. Session and Cookie Mechanism (Detailed Flow)

### How the Session Cookie Works

The authentication system uses **PHP server-side sessions** with a browser cookie:

#### 1. **Cookie Creation (What Gets Sent to Browser)**
```
HTTP Set-Cookie: nightwatch_session=abc123def456 
  Path=/
  HttpOnly
  SameSite=Lax
  Secure (on HTTPS)
  (no explicit Expires/Max-Age = session cookie)
```

The cookie contains only a **random session ID** — no sensitive data.

#### 2. **Session Storage (Server-Side)**
- Session data stored in PHP session files on the server
- Location: typically `/tmp` (Linux) or `%TEMP%` (Windows)
- File name: `sess_abc123def456` (matches cookie session ID)
- Contents: serialized PHP array with user data

```
File: /tmp/sess_abc123def456
Content: user_id|i:42;username|s:8:"john_doe";email|s:16:"john@example.com";expires_at|i:1714816200;
```

#### 3. **On Each Request**
1. Browser sends cookie: `nightwatch_session=abc123def456`
2. PHP reads the session file: `/tmp/sess_abc123def456`
3. PHP populates `$_SESSION` array
4. Application checks `$_SESSION['expires_at']` against current `time()`
5. If expired, session is destroyed and user is logged out

#### 4. **Cookie Lifetime vs Session Expiry**

| Aspect | Default | With Remember Me |
|--------|---------|------------------|
| **Cookie** | Session (until browser closes) | Session (until browser closes) |
| **Server Expiry** | 600 seconds (10 min) | 604800 seconds (7 days) |
| **Result** | User logged out in 10 min | User stays logged in for 7 days |

**Key**: The "Remember Me" feature does NOT change the cookie lifetime. It changes the **server-side validation time**. The cookie persists across browser restarts (session cookie behavior varies by browser), but the server only accepts it if the 7-day timer hasn't expired.

### Why Token Columns Exist But Aren't Used

The `users.token` and `users.token_expires_at` columns are written for:
- **Audit trail**: Track who was issued tokens and when
- **Legacy compatibility**: Older code or external systems might expect them
- **Recovery**: Historical record of token issuance

But they are **NOT checked** during authentication. The real source of truth is the PHP session file on the server.

### Complete Session Flow Example

#### Login with "Remember Me":

```
1. User submits: POST /login
   - username: "john_doe"
   - password: "secret123"
   - remember_me: "on"  ← checkbox checked

2. Backend (login.php):
   - Validates credentials
   - Detects remember_me checkbox
   - Sets expiresIn = 604800 (7 days)
   - Calls create_user_session($user, 604800)

3. create_user_session() in common.php:
   - Calls start_app_session()
   - Regenerates session ID with session_regenerate_id(true)
   - Stores in $_SESSION:
     {
       user_id: 42,
       username: "john_doe",
       email: "john@example.com",
       expires_at: 1714816200  ← Current time + 604800 seconds
     }
   - PHP writes to: /tmp/sess_<random>

4. Server responds:
   - Set-Cookie: nightwatch_session=<random>
   - Returns: expires_at_ms (for frontend countdown)

5. User closes browser and returns tomorrow:
   - Browser sends saved cookie: nightwatch_session=<random>
   - PHP reads /tmp/sess_<random>
   - get_current_session_user() checks:
     if (time() >= expires_at) → NOT YET (7 days haven't passed)
   - User is still authenticated ✅

6. Seven days later:
   - Browser sends cookie: nightwatch_session=<random>
   - PHP reads /tmp/sess_<random>
   - get_current_session_user() checks:
     if (time() >= expires_at) → YES (7 days passed)
   - destroy_app_session() is called
   - User is logged out and redirected to /login
```

#### Login WITHOUT "Remember Me":

- Same as above, but expiresIn = 600 (10 minutes)
- User must log in again after 10 minutes of inactivity or browser restart

### Session Destruction

`destroy_app_session()` does:
1. Clears `$_SESSION = []`
2. Deletes the browser cookie with expired date
3. Calls `session_destroy()` to remove server-side session file
4. User is completely logged out

## 7. Registration Flow

### Complete Registration Process

Registration is a **two-step process**: register → verify email

### Client flow

`/register/index.html`:

1. User enters: username, email, password, confirm_password
2. Client-side validation:
   - Checks `password === confirm_password`
   - If mismatch, shows error and prevents submission
3. On valid submission:
   - Removes `confirm_password` from `FormData` (not sent to server)
   - Sends `POST /functions/register.php`
4. On success:
   - Stores `registration_email` in `sessionStorage` (for verify page)
   - Shows green success message
   - Redirects to `/register/verify` after short delay (700ms)
5. On error:
   - Shows red error message from server
   - User can try again

### Server flow

`/functions/register.php`:

1. requires `POST` method only
2. enforces same-origin POST validation (CSRF protection)
3. rate limits to `5` attempts per `1800` seconds per IP address
4. validates input:
   - username: 3-50 chars, alphanumeric + underscore only
   - email: valid email format
   - password: minimum length enforced (varies by config)
5. database checks:
   - Verifies username is unique
   - Verifies email is unique
   - Returns 409 if duplicate
6. password hashing:
   - Hashes password with `PASSWORD_ARGON2ID` algorithm
   - Generates random salt automatically
   - Hash stored in `users.password` column
7. generates temporary data:
   - `token`: 32-byte random hex (for legacy compatibility)
   - `email_verification_code`: 6-digit random code
   - `email_verification_code_expires_at`: current time + 900 seconds (15 minutes)
8. creates user record:
   - Inserts into `users` table
   - Sets `email_verified = 0` (account locked until verification)
   - Sets `created_at` to current timestamp
9. sends verification email via `functions/mailer.php`:
   - Recipient: submitted email address
   - Body: contains 6-digit verification code
   - Subject: "Email Verification"
10. logs event to security log:
    - File: `/tmp/nightwatch_security_log.txt`
    - Info: timestamp, event, username, email, result
11. response:
    - HTTP 200
    - JSON: `{ "success": true, "redirect": "/register/verify" }`

### Important behavior

- Registration does **NOT** create a logged-in session
- Account is locked (`email_verified = 0`) until email is verified
- Even if user tries to login before verification, password verification will succeed but login is blocked with message: "Please verify your email first"
- The 6-digit code expires after 15 minutes
- User must complete `/functions/verify_email.php` to activate account
- After verification, user must then login normally (registration doesn't auto-login)

## 8. Email Verification Flow

### Complete Email Verification Process

After registration, user must verify their email address before account is usable.

### Client flow

`/register/verify/index.html`:

1. Page loads:
   - Reads `registration_email` from `sessionStorage` (set by register page)
   - If empty, redirects back to `/register` (prevents direct URL access)
2. Displays email input:
   - Pre-filled with email from `sessionStorage` (read-only to prevent tampering)
   - User cannot edit this field
3. User enters verification code:
   - 6-digit code received via email
   - Input limited to numbers only
4. On code entry:
   - Shows loading state
   - Submits `POST /functions/verify_email.php` with:
     - `email`: from sessionStorage
     - `verification_code`: entered by user
5. On success:
   - Clears `sessionStorage` (removes stored email)
   - Shows green success message: "Email verified! Redirecting..."
   - Redirects to `/panel` after delay
   - Note: User is now authenticated (session created)
6. On error:
   - Shows red error with reason:
     - "Invalid or expired verification code"
     - "Email not found"
     - "Code has expired"
   - User can enter correct code and retry (up to rate limit)
7. Resend code link:
   - Currently shows guidance to check spam folder or register again
   - No automated resend endpoint implemented yet

### Server flow

`/functions/verify_email.php`:

1. requires `POST` method only
2. enforces same-origin POST validation (CSRF protection)
3. rate limits to `10` attempts per `300` seconds per IP
4. validates input format:
   - email: valid email format
   - verification_code: exactly 6 digits
5. database lookup:
   - Loads user by email address
   - Returns generic error if not found (doesn't leak account existence)
6. code validation:
   - Uses `hash_equals()` for timing-safe comparison
   - Protects against timing attacks
   - Compares submitted code with `users.email_verification_code`
7. expiry check:
   - Compares `users.email_verification_code_expires_at` with current time
   - Rejects if code has expired (15 minutes)
8. activation:
   - Sets `users.email_verified = 1`
   - Clears verification code fields:
     - `email_verification_code = NULL`
     - `email_verification_code_expires_at = NULL`
9. creates authenticated session:
   - Calls `create_user_session($user, NIGHTWATCH_SESSION_TTL)`
   - Session expiry: 600 seconds (10 minutes)
   - Regenerates session ID
   - Stores user data in `$_SESSION`
10. logs event to security log:
    - Event: "Email verification success"
    - Username, email, timestamp
11. response:
    - HTTP 200
    - JSON includes:
      - `success: true`
      - `expires_in`: session TTL in seconds
      - `expires_at`: ISO 8601 timestamp
      - `expires_at_ms`: milliseconds for JavaScript countdown
      - `redirect`: "/panel"

### Important behavior

- Account is **locked** until email is verified
- Verification code is valid for **15 minutes** only
- After successful verification, user is **immediately logged in**
- No need to login again after verification (automatic redirect to panel)
- Can attempt verification up to 10 times per 5 minutes
- Timing-safe comparison prevents brute force code guessing

### Gap in Current Implementation

- The "Resend code" link exists on the UI but has no backend endpoint
- No way to request a new code programmatically
- User must register again to get a new code
- Future enhancement: implement `POST /functions/resend_verification_code.php`

## 9. Login Flow

### Complete Login Process

### Client flow

`/login/index.html`:

1. Page load:
   - Calls `getSession()` to check if already logged in
   - If valid session found: redirects to `/panel` immediately
   - If no session: shows login form
2. Form display:
   - Username field: accepts username OR email
   - Password field: masked input
   - "Remember me for one week" checkbox: optional
3. Form submission:
   - User enters credentials and optionally checks "Remember me"
   - Sends `POST /functions/login.php` with:
     - `username`: username or email address
     - `password`: plaintext password
     - `remember_me`: "on" if checkbox checked, otherwise omitted
4. Request handling:
   - Clears previous error messages
   - Disables submit button to prevent double-submission
   - Shows loading state
5. Response handling:
   - On success (200 + `success: true`):
     - Shows green message: "✅ Login successful! Redirecting..."
     - Waits 700ms
     - Redirects to `/panel`
   - On error (any non-success response):
     - Shows red error message from server
     - Re-enables submit button
     - User can try again

### Server flow

`/functions/login.php`:

1. requires `POST` method only
2. enforces same-origin POST validation (CSRF protection)
3. rate limits to `8` attempts per `300` seconds per IP address
4. input validation:
   - `username` field can be username OR email (auto-detected)
   - If contains `@`: treated as email, validated with `FILTER_VALIDATE_EMAIL`
   - If no `@`: treated as username, validated as 3-50 alphanumeric + underscore
   - `password`: checked that it's not empty
5. database lookup:
   - Searches `users` table:
     - If email format: `WHERE email = ?`
     - If username format: `WHERE username = ?`
   - Returns generic error if user not found
6. password verification:
   - Uses `password_verify($submitted_password, $stored_hash)`
   - Compares plaintext against Argon2ID hash
   - Returns generic error if mismatch
7. email verification check:
   - Only after password verified (important for security)
   - Checks `users.email_verified = 1`
   - Returns specific error if unverified
8. "Remember me" processing:
   - Checks if `$_POST['remember_me']` exists
   - If checked: `$expiresIn = 604800` seconds (7 days)
   - If unchecked: `$expiresIn = 600` seconds (10 minutes)
9. creates authenticated session with `create_user_session($user, $expiresIn)`
10. updates legacy token columns for audit
11. response: success flag, user data, expires info, redirect to `/panel`

### Security notes

- Password verified before email status is revealed (prevents account enumeration)
- Generic error messages hide whether username exists or password is wrong
- Rate limiting: max 8 attempts per 5 minutes per IP

## 10. Session Validation and Logout

### Complete Session Checking Process

### `GET /functions/session.php`

Checks whether the current browser session is still valid.

**Request flow:**

- requires `GET` method only
- no POST data (stateless check)
- sends the session cookie automatically via browser
- called on every page load or when frontend needs fresh session data

**Server processing:**

1. calls `get_current_session_user()`
2. checks if `$_SESSION` has valid user_id, username, email, expires_at
3. checks if current `time() >= expires_at`:
   - If YES: session expired, calls `destroy_app_session()`, returns 401
   - If NO: session valid, continues
4. calculates expiry in both ISO 8601 and milliseconds format

**Response on valid session:**

- HTTP 200
- JSON:
  ```json
  {
    "success": true,
    "username": "john_doe",
    "email": "john@example.com",
    "expires_at": "2026-05-04T12:15:30+00:00",
    "expires_at_ms": 1714816530000
  }
  ```

**Response on invalid/expired session:**

- HTTP 401
- JSON:
  ```json
  {
    "success": false,
    "error": "Unauthorized"
  }
  ```
- Browser cookie is NOT deleted (client must detect 401)

### `POST /functions/logout.php`

Completely removes the user's session.

**Request flow:**

- requires `POST` method only
- enforces same-origin POST validation (CSRF protection)
- sends session cookie automatically

**Server processing:**

1. calls `destroy_app_session()` which:
   - Clears `$_SESSION = []`
   - Sets cookie expiration to past date (forces browser deletion)
   - Calls `session_destroy()` to remove server-side session file
2. returns success response

**Response:**

- HTTP 200
- JSON: `{ "success": true }`

**After logout:**

- Browser deletes `nightwatch_session` cookie
- Server session file is deleted
- Frontend redirects to `/login`

### Frontend Session Runtime

`js/auth.js` provides these public functions:

#### `getSession(forceRefresh = false)`

- Returns cached session data or fetches fresh from server
- If `forceRefresh = true`: bypasses cache and fetches new data
- Returns `null` if session invalid or network error
- Does NOT redirect automatically

#### `requireAuth(redirectTo = '/login')`

- Forces a fresh session check
- If session invalid: redirects to login page
- Used by protected pages (e.g., `/panel`)
- Must be called before rendering protected content

#### `clearAuth(redirectTo = null)`

- Calls `POST /functions/logout.php`
- Clears browser-side session cache
- If `redirectTo` provided: redirects after logout
- Used by logout button

#### `authFetch(url, options)`

- Makes a `fetch()` request with session cookie automatically included
- Sets `credentials: 'same-origin'` automatically
- On HTTP 401: calls `clearAuth()` and redirects to login
- Returns fetch response object

#### `getTokenExpiry()`

- Returns session expiry time in milliseconds
- Used by panel for countdown timer
- Returns `null` if no valid session

### Session Timeout Flow Example

```
User logs in with "Remember me" checked
  ↓
Server creates session with expires_at = now + 604800 seconds
  ↓
Session cookie sent to browser
  ↓
[User closes browser, comes back tomorrow]
  ↓
Browser sends cookie (it persists across browser restart)
  ↓
getSession() called on page load
  ↓
Server checks: time() < expires_at? YES (6 days left)
  ↓
Session valid, user is logged in! ✅
  ↓
[User waits 7 days]
  ↓
User returns and opens page
  ↓
getSession() called
  ↓
Server checks: time() < expires_at? NO (7 days passed)
  ↓
destroy_app_session() called
  ↓
HTTP 401 returned
  ↓
Frontend clearAuth() called, redirects to /login
  ↓
User must login again ❌
```

## 11. Password Reset Flow

### Complete Two-Step Password Reset Process

Users who forget their password can reset it by email verification.

### Client flow - Step 1: Request Reset Code

`/forgetpassword/index.html` - Form 1:

1. Page load:
   - Displays "Forgot your password?" section
   - Email input field
   - Submit button
2. User enters email:
   - Any email address (real or fake)
3. Form submission:
   - Sends `POST /functions/forget_password.php`
   - With `email` parameter
4. Server response handling:
   - Shows success message for ANY email (intentional)
   - Message: "If this email exists, you will receive a reset code"
   - This prevents account enumeration
5. Step 1 → Step 2 transition:
   - On success, hides Step 1 form
   - Shows Step 2: "Enter reset code and new password"

### Client flow - Step 2: Reset Password

`/forgetpassword/index.html` - Form 2:

1. Display:
   - Email field (pre-filled from Step 1, read-only)
   - Reset code field (6 digits)
   - New password field
   - Confirm password field
2. User enters data:
   - 6-digit code received via email
   - New password (must match confirmation)
3. Form validation:
   - Verifies password === confirm_password
   - Removes confirm_password from submission
   - Removes password from form (only hashed value sent)
4. Form submission:
   - Sends `POST /functions/reset_password.php`
   - With email, reset_code, password
5. Response handling:
   - On success:
     - Shows green message: "Password reset successful!"
     - After delay, redirects to `/login`
   - On error:
     - Shows red error message
     - User can retry with correct code or new password

### Server flow - Step 1: Request Reset Code

`/functions/forget_password.php`:

1. requires `POST` method only
2. enforces same-origin POST validation (CSRF protection)
3. rate limits to `3` attempts per `1800` seconds per IP (30 min window)
4. input validation:
   - Validates email format
   - Returns error if not valid email format
5. database lookup:
   - Searches for user by email
   - Important: Does NOT leak whether email exists
6. generates reset data:
   - `reset_code`: 6-digit random code
   - `reset_code_expires_at`: current time + 3600 seconds (1 hour)
7. updates database:
   - Stores reset_code and expiry in users table
   - Only updates if user exists
8. sends email via `functions/mailer.php`:
   - Recipient: submitted email
   - Subject: "Password Reset Code"
   - Body: includes the 6-digit code
   - Email sent only if user found
9. logs event to security log:
   - Event: "Password reset requested"
   - Email, timestamp, IP address
10. response (ALWAYS success, even if email not found):
    - HTTP 200
    - JSON: `{ "success": true, "message": "If this email exists..." }`
    - This is intentional to prevent account enumeration

### Server flow - Step 2: Reset Password

`/functions/reset_password.php`:

1. requires `POST` method only
2. enforces same-origin POST validation (CSRF protection)
3. rate limits to `5` attempts per `600` seconds per IP (10 min window)
4. input validation:
   - email: valid format
   - reset_code: exactly 6 digits
   - password: minimum length (e.g., 6+ chars)
   - password_confirm: must match password
   - Returns errors if any validation fails
5. database lookup:
   - Searches: `WHERE email = ? AND reset_code IS NOT NULL`
   - Ensures user exists and has active reset code
6. reset code validation:
   - Uses `hash_equals()` for timing-safe comparison
   - Protects against timing attacks
   - Compares submitted code with `users.reset_code`
7. expiry check:
   - Compares `users.reset_code_expires_at` with current time
   - Rejects if expired (1 hour limit)
   - Returns: "Reset code has expired"
8. password update:
   - Hashes new password with `PASSWORD_ARGON2ID`
   - Generates new random salt
   - Updates `users.password` column
9. clears reset data:
   - Sets `reset_code = NULL`
   - Sets `reset_code_expires_at = NULL`
   - Prevents code reuse
10. logs event to security log:
    - Event: "Password reset success"
    - Email, timestamp, IP address
11. response:
    - HTTP 200
    - JSON: 
      ```json
      {
        "success": true,
        "message": "Password reset successful! Redirecting to login..."
      }
      ```

### Security features

- **Account enumeration protection**: Success response sent even if email doesn't exist
- **Timing-safe comparison**: `hash_equals()` used for code validation
- **Rate limiting**: 
  - Step 1: 3 resets per 30 minutes (prevents spam)
  - Step 2: 5 attempts per 10 minutes (prevents brute force)
- **Code expiry**: Codes valid only 1 hour
- **One-time use**: Code cleared after successful reset
- **No auto-login**: User must login again after reset

### Reset Flow Example

```
1. User visits /forgetpassword
   ↓
2. Enters email: john@example.com, clicks "Request Code"
   ↓
3. POST /forget_password.php
   Backend checks: user exists? NO
   Sends success anyway (no email sent)
   Frontend shows: "Check your email"
   ↓
4. [Real scenario] User enters correct email: verified@example.com
   ↓
5. POST /forget_password.php
   Backend checks: user exists? YES
   Generates code: 123456
   Stores: expires in 1 hour
   Sends email with code
   ↓
6. User checks email, finds code: 123456
   ↓
7. Enters in Step 2 form:
   - Email: verified@example.com
   - Code: 123456
   - New password: NewPass123
   ↓
8. POST /reset_password.php
   Backend validates code and expiry: OK
   Hashes new password: Argon2ID hash
   Clears reset_code and reset_code_expires_at
   ↓
9. Response: success
   Frontend shows: "Password reset!"
   Redirects to /login
   ↓
10. User logs in with new password ✅
```

## 12. Panel Behavior

`/panel/index.html` is the authenticated single-page dashboard layer.

### Data loading

1. calls `requireAuth('/login')`
2. calls `GET /functions/panel.php`
3. renders username and avatar
4. starts token countdown
5. starts uptime counter

### Visual sections

- sidebar with avatar and logout
- top command header
- stat cards:
  - registered users
  - honor level
  - server uptime
- notification list with session expiry
- photo upload modal
- authenticated public directory modal

`/panel/index.html` is the authenticated single-page dashboard layer.

### Data loading

1. calls `requireAuth('/login')`
2. calls `GET /functions/panel.php`
3. renders username and avatar
4. starts token countdown
5. starts uptime counter

### Visual sections

- sidebar with avatar and logout
- top command header
- stat cards:
  - registered users
  - honor level
  - server uptime
- notification list with session expiry
- photo upload modal
- authenticated public directory modal
- public profile modal

### Panel API

`/functions/panel.php`:

- requires authenticated session
- reloads the current user by ID
- returns `401` and destroys the session if the user row no longer exists
- returns:
  - `username`
  - `email`
  - `photo_path`
  - `expires_at`
  - `expires_at_ms`
  - `registered_users`
  - `honor_level`
  - `server_uptime_seconds`

### Uptime logic

`getServerUptimeSeconds()` in `panel.php`:

- Linux:
  reads `/proc/uptime`
- Windows primary:
  runs `wmic os get lastbootuptime /value` if `shell_exec()` is available
- Windows fallback:
  runs `systeminfo | find "System Boot Time"` if `shell_exec()` is available
- fallback:
  returns `null`

## 13. Public User Directory

Authenticated users can open a modal that displays all registered users.

### Data source

`GET /functions/get_users.php`:

- requires authenticated session
- loads all users ordered by `created_at DESC`
- returns:
  - `id`
  - `username`
  - `photo_path`
  - `total`

### Frontend behavior

- cards show either profile photo or username initials
- clicking a card opens a public profile modal
- the modal shows username and numeric ID

### Privacy note

Any authenticated user can currently enumerate all registered users and their photo paths. That is intentional in the current implementation but should be treated as a product-level exposure decision.

## 14. Profile Photo Upload

### Client behavior

`/panel/index.html`:

- opens upload modal from the avatar edit button
- validates extension, MIME type, and max size before upload
- submits `multipart/form-data` to `/functions/upload_photo.php`
- updates the avatar on success

### Server behavior

`/functions/upload_photo.php`:

1. requires `POST`
2. enforces same-origin POST validation
3. requires authenticated session
4. validates upload success
5. allows only `jpg`, `jpeg`, and `png`
6. enforces max size `500 KB`
7. verifies actual image content with `getimagesize()`
8. verifies MIME is one of:
   - `image/jpeg`
   - `image/jpg`
   - `image/png`
9. writes into `uploads/photos/`
10. names the file as:
    `{user_id}_{32-hex-random}.{ext}`
11. updates `users.photo_path`
12. deletes the previous photo only if the resolved path is still under the upload directory

### Current limitations

- no resizing or recompression
- no EXIF stripping
- no content moderation or malware scanning
- no quota enforcement beyond file size

## 15. Database Schema

### Database name

- `Registration`

### Bootstrap user

`DB/database.sql` creates:

- database `Registration`
- MySQL user `nightwatch_app`
- placeholder password `CHANGE_ME_STRONG_PASSWORD`

That placeholder must be replaced before running the script.

### Users table

```sql
CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    token VARCHAR(255) DEFAULT NULL,
    token_expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    photo_path VARCHAR(255) DEFAULT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    email_verification_code VARCHAR(6) DEFAULT NULL,
    email_verification_code_expires_at TIMESTAMP NULL,
    reset_code VARCHAR(6) DEFAULT NULL,
    reset_code_expires_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 16. Configuration Files

### `DB/db_config.php`

Database config can come from environment variables:

- `NIGHTWATCH_DB_HOST`
- `NIGHTWATCH_DB_USER`
- `NIGHTWATCH_DB_PASSWORD`
- `NIGHTWATCH_DB_NAME`

If an environment variable is missing, the file falls back to its local default constant value.

### `functions/mail_config.php`

The file defines:

- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_USERNAME`
- `SMTP_PASSWORD`
- `SMTP_ENCRYPTION`
- `SMTP_VERIFY_CERT`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`
- `PASSWORD_RESET_TIMEOUT`
- `EMAIL_VERIFICATION_TIMEOUT`

Important implementation note:

`functions/mailer.php` currently uses PHP `mail()` and only applies:

- `SMTP_HOST`
- `SMTP_PORT`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

It does not perform authenticated SMTP login and it does not currently use:

- `SMTP_USERNAME`
- `SMTP_PASSWORD`
- `SMTP_ENCRYPTION`
- `SMTP_VERIFY_CERT`
- `PASSWORD_RESET_TIMEOUT`
- `EMAIL_VERIFICATION_TIMEOUT`

The actual reset and verification expiries are hard-coded in the endpoint files:

- registration verification: `900` seconds
- password reset: `3600` seconds

## 17. Shared Backend Utilities

`functions/common.php` contains the shared behavior used across the API layer:

- `send_json()`
- `is_https_request()`
- `request_origin_is_same_site()`
- `enforce_same_origin_post()`
- `start_app_session()`
- `destroy_app_session()`
- `create_user_session()`
- `get_current_session_user()`
- `require_authenticated_session()`
- `iso8601_from_timestamp()`
- `require_db_config_file()`
- `request_ip_address()`
- `rate_limit_storage_dir()`
- `enforce_rate_limit()`

## 18. HTTP and Security Headers

### JSON responses

`send_json()` sets:

- `Content-Type: application/json`
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
- `Pragma: no-cache`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: same-origin`

### Apache `.htaccess`

Root `.htaccess`:

- disables directory indexes
- sets:
  - `X-Content-Type-Options`
  - `X-Frame-Options`
  - `Referrer-Policy`
  - `Permissions-Policy`
- denies direct access to:
  - `.sql`
  - `.sh`
  - `.xmind`

`DB/.htaccess`:

- denies all direct access

`uploads/.htaccess` and `uploads/photos/.htaccess`:

- disable directory indexes
- deny execution of PHP-family files
- remove PHP handlers and types

## 19. Rate Limiting

Rate limiting is file-based and stored in:

`sys_get_temp_dir()/nightwatch_rate_limits`

Scopes:

- `login`: `8` attempts per `300` seconds
- `register`: `5` attempts per `1800` seconds
- `verify_email`: `10` attempts per `300` seconds
- `forget_password`: `3` attempts per `1800` seconds
- `reset_password`: `5` attempts per `600` seconds

When exceeded, the server:

- sends HTTP `429`
- adds `Retry-After`
- returns a JSON error

## 20. Logging

### Security log

Written to:

`sys_get_temp_dir()/nightwatch_security_log.txt`

Logged events include:

- new registration
- verification success
- invalid verification code attempt
- password reset request
- invalid reset code attempt
- password reset success

### Mail log

Written to:

`sys_get_temp_dir()/nightwatch_mail_log.txt`

Logged fields:

- recipient
- subject
- send success flag

## 21. Deployment Notes

### Apache helper script

`DB/setup.sh`:

1. copies `db_config.php` to `/var/www/config/`
2. locks file ownership and permissions
3. overwrites Apache `000-default.conf`
4. denies all routes by default
5. re-allows:
   - `/`
   - `/index.html`
   - `/style.css`
   - `/animation.js`
   - `/login`
   - `/register`
   - `/forgetpassword`
   - `/panel`
   - `/js`
   - `/functions`
   - `/uploads/photos`
6. enables `authz_core`
7. restarts Apache

### Deployment cautions

- `setup.sh` is opinionated and overwrites the default Apache site config
- if the deployed asset paths differ from this repo layout, the allow-list must be adjusted
- direct API routes under `/functions` remain publicly reachable by path, with access control enforced inside PHP

## 22. Diagnostics

### `DB/test_db.php`

CLI-only diagnostic that:

- locates the config file
- verifies DB constants
- attempts a `mysqli` connection
- runs a simple query

### `DB/migrate_add_photo.php`

Legacy helper that adds `photo_path` if the column is missing in an older database.

## 23. Git and Local Hygiene

`.gitignore` currently ignores:

- `DB/db_config.php`
- `functions/mail_config.php`
- uploaded photos under `uploads/photos/*`
- log files matching `*.log`

This reduces accidental commit risk for credentials and generated files, but it does not retroactively scrub secrets from any external history.

## 24. Known Limitations and Follow-Up Work

- `functions/mailer.php` is documented as SMTP-oriented but currently relies on `mail()` rather than authenticated SMTP transport.
- `functions/mail_config.php` contains fields that are not fully wired into runtime behavior.
- `/register/verify` has no resend-verification endpoint.
- any authenticated user can list all registered users through `get_users.php`.
- uploaded images are stored as provided without normalization or sanitization beyond image validation.
- session protection relies on SameSite plus same-origin POST checks, not per-form CSRF tokens.
- the panel uptime feature depends on platform-specific behavior and may return `null`.

## 25. Verification Summary For This Snapshot

Repository verification performed during this documentation update:

- all PHP files linted successfully with `php -l`
- JavaScript files passed `node --check`
- route, auth, mail, upload, and config behavior reviewed against source
- `DB/test_db.php` could not be fully exercised in this workspace because CLI PHP lacks the `mysqli` extension

This document now matches the current repository behavior rather than the older architecture notes.
