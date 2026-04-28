# NightWatch Webapp - Complete Documentation

## Overview
NightWatch is a user authentication and dashboard system with a Game of Thrones theme. It features user registration, login, session management, and a command center dashboard.

---

## 🏗️ Architecture Overview

### Technology Stack
- **Frontend:** HTML5, CSS3, JavaScript (Vanilla JS)
- **Backend:** PHP 7.4+
- **Database:** MySQL/MariaDB
- **Authentication:** Session-based with tokens

### Project Structure
```
NightWatch/
├── index.html              # Home page
├── animation.js            # Canvas animations (stars, particles)
├── style.css               # Global styles
├── login/                  # Login page
│   ├── index.html
│   ├── animation.js
│   └── style.css
├── register/               # Registration page
│   ├── index.html
│   ├── animation.js
│   └── style.css
├── panel/                  # User dashboard
│   ├── index.html
│   ├── animation.js
│   └── style.css
├── forgetpassword/         # Password recovery (placeholder)
├── js/
│   └── auth.js            # Authentication utilities
├── functions/             # Backend PHP APIs
│   ├── common.php         # Shared utilities
│   ├── login.php          # Login endpoint
│   ├── register.php       # Registration endpoint
│   ├── panel.php          # Dashboard data endpoint
│   ├── logout.php         # Logout endpoint
│   └── session.php        # Session check endpoint
└── DB/
    ├── database.sql       # Database schema
    ├── db_config.php      # Database credentials
    ├── db_connect.php     # Connection wrapper
    └── setup.sh           # Setup script
```

---

## 📊 Database Schema

### Users Table
```sql
CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,        -- 3-50 chars, letters/numbers/underscore
    email VARCHAR(255) NOT NULL UNIQUE,          -- Valid email format
    password VARCHAR(255) NOT NULL,              -- Argon2ID hashed
    token VARCHAR(255) DEFAULT NULL,             -- Session token
    token_expires_at TIMESTAMP NULL,             -- Token expiration
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Key Security Features:**
- Passwords hashed with Argon2ID (resistant to GPU/ASIC attacks)
- Unique username and email constraints
- Token-based session management
- Expiration timestamps prevent token reuse

---

## 🔐 Authentication Flow

### 1. Registration Process (`/register`)

**User Actions:**
1. Enter username, email, password
2. Frontend validates input locally
3. Submit form to `/functions/register.php`

**Server Processing:**
```
├─ Rate limit check (5 attempts per 30 minutes)
├─ Input validation:
│  ├─ Username: 3-50 chars, alphanumeric + underscore
│  ├─ Email: Valid format
│  └─ Password: Minimum 8 characters
├─ Check for duplicate username/email
├─ Hash password with Argon2ID
├─ Generate session token
├─ Create database record
├─ Create PHP session
└─ Return success response with redirect to /panel
```

**Response:**
```json
{
    "success": true,
    "message": "Registration successful!",
    "expires_in": 600,
    "expires_at": "2026-04-27T15:30:00Z",
    "expires_at_ms": 1735000000000,
    "redirect": "/panel"
}
```

### 2. Login Process (`/login`)

**User Actions:**
1. Enter username/email and password
2. Frontend validates input
3. Submit to `/functions/login.php`

**Server Processing:**
```
├─ Rate limit check (8 attempts per 5 minutes)
├─ Input validation
├─ Search database for user by username OR email
├─ Verify password using password_verify()
├─ Generate random token
├─ Update user record with token and expiration
├─ Create PHP session
└─ Return success with user data
```

**Key Security Points:**
- Doesn't reveal if username or email doesn't exist (prevents user enumeration)
- Rate limiting prevents brute force attacks
- Token stored in database for server-side validation

### 3. Session Validation (`/functions/session.php`)

**When Called:**
- Page load to verify login status
- Can be called anytime to check if session still valid

**Server Processing:**
```
├─ Check if PHP session exists
├─ Verify expiration hasn't passed
├─ Return user data or 401 Unauthorized
```

**Used By:**
- `auth.js` on every protected page
- `requireAuth()` checks before allowing access

### 4. Logout (`/functions/logout.php`)

**User Actions:**
1. Click "Abandon Post" button on dashboard

**Server Processing:**
```
├─ Clear all $_SESSION variables
├─ Delete session cookie from client
├─ Destroy session file on server
└─ Return success response
```

**Frontend:**
1. Clears local session cache
2. Redirects to `/login`

---

## 🔄 Request Flow Examples

### Example 1: User Visits Dashboard

```
1. Browser loads /panel/index.html
   ↓
2. JavaScript runs: initPanel()
   ↓
3. Calls requireAuth('/login')
   ↓
4. requireAuth() → getSession(true) → fetch /functions/session.php
   ↓
5. Session check:
   ├─ If valid → Return session data
   ├─ If expired → Return 401
   └─ If no session → Return 401
   ↓
6. If not authenticated → redirect to /login
   If authenticated → Continue loading dashboard
   ↓
7. Fetch /functions/panel.php
   ├─ Returns user data
   ├─ Total user count
   ├─ Server uptime
   └─ Session expiration
   ↓
8. JavaScript displays dashboard with data
```

### Example 2: Failed Login Attempt

```
1. User submits login form
   ↓
2. Frontend validates input
   ├─ Username/email required
   ├─ Password required
   └─ Email format check if applicable
   ↓
3. POST to /functions/login.php
   ↓
4. Server rate limit check
   ├─ If exceeded → 429 Too Many Requests
   └─ If OK → Continue
   ↓
5. Database query for user
   ├─ If not found → Return 401 Invalid credentials
   ├─ If password wrong → Return 401 Invalid credentials
   └─ (No indication which is wrong - prevents user enumeration)
   ↓
6. Frontend shows error message: "Invalid credentials"
```

---

## 🛡️ Security Features

### 1. **Input Validation**
- Frontend: Quick feedback
- Backend: Strict validation before database operations
- Rate limiting: Prevents brute force attacks

### 2. **Password Security**
- Hashed with Argon2ID (modern, GPU-resistant)
- Minimum 8 characters required
- Never stored or logged in plaintext

### 3. **Session Management**
- HTTPOnly cookies (can't be accessed by JavaScript)
- Secure flag (only sent over HTTPS)
- SameSite=Lax (prevents CSRF attacks)
- Automatic expiration (10 minutes = 600 seconds)
- Session ID regenerated on login

### 4. **SQL Injection Prevention**
- All queries use prepared statements
- Parameters bound separately from SQL code
- No string concatenation in queries

### 5. **CSRF Protection**
- Session ID regenerated after login
- SameSite cookie attribute set
- POST method required for state-changing operations

### 6. **Rate Limiting**
- Login: 8 attempts per 300 seconds (5 min)
- Registration: 5 attempts per 1800 seconds (30 min)
- Based on IP address (stored in temp files)

### 7. **Error Handling**
- Generic error messages to users
- Detailed logging on server (not exposed)
- 401/429/422 HTTP status codes for different failures

---

## 📱 Frontend Components

### auth.js - Client-Side Authentication Manager

**Key Functions:**
```javascript
requireAuth(redirectTo)     // Verify logged in, redirect if not
getSession(forceRefresh)    // Get session data, use cache if available
clearAuth(redirectTo)       // Logout: clear cache, call logout.php, redirect
authFetch(url, options)     // Wrapper for fetch() that handles 401
getTokenExpiry()            // Get session expiration time
```

**How It Works:**
1. Caches session data in memory
2. Makes subsequent checks instant (no server call)
3. Can force refresh to check if session expired
4. Automatically handles 401 responses by logging out

### Login Page (`/login/index.html`)

```javascript
// Form submit handler
1. Get username and password
2. Validate locally:
   - Username/email not empty
   - Password not empty
3. POST to /functions/login.php
4. On success:
   - Show green success message
   - Wait 700ms
   - Redirect to /panel
5. On error:
   - Display red error message
   - Stay on page for retry
```

### Registration Page (`/register/index.html`)

```javascript
// Form submit handler
1. Get username, email, password, confirm_password
2. Validate locally:
   - All fields not empty
   - Passwords match
3. POST to /functions/register.php
4. Same success/error handling as login
```

### Dashboard (`/panel/index.html`)

**Features:**
- Real-time session countdown
- User profile display (username as avatar)
- Statistics dashboard:
  - Total registered users
  - Honor level (placeholder)
  - Server uptime (auto-updating)
- Logout button

**Session Expiry Countdown:**
```javascript
- Initial display: "Expires in 10m 0s"
- Updates every second: "Expires in 9m 59s"
- At 60 seconds: "Expires in 59s"
- When expired: "EXPIRED - Redirecting..."
- Auto-logout after 600ms
```

**Animated Statistics Display:**

The dashboard implements animated counter displays for statistics:

1. **data-target Attribute System**
   - Each stat element has a `data-target` attribute with the final value
   - Example: `<div class="stat-value" id="registered-users-value" data-target="42">0</div>`
   - Initial value shown is 0, then animates to data-target value

2. **Animation Process (animation.js)**
   - DOMContentLoaded event triggers counter animation
   - Reads `data-target` attribute from each `.stat-value` element
   - Smoothly increments from 0 to target value over ~3 seconds
   - Updates every 15ms for smooth visual effect
   - 600ms initial delay to sync with CSS animations

3. **Dynamic Value Updates (renderStats function)**
   - When panel data loads, `renderStats()` is called with server response
   - Updates `data-target` attribute dynamically based on database values
   - Sets text content to display current value immediately
   - Preserves animation on value changes

4. **Stat Values Explained**
   - **All Registered Users**: Count from database query `SELECT COUNT(*) FROM users`
   - **Honor Level**: Placeholder for future features (currently always 0)
   - **Server Uptime**: System uptime in seconds, formatted to human-readable (e.g., "5d 2h 30m")

**Server Uptime Detection:**

The server uptime calculation is implemented in `functions/panel.php`:

- **Linux Systems**: Reads from `/proc/uptime` file (first number = system uptime in seconds)
- **Windows Systems**: Multiple fallback methods:
  1. Primary: `wmic os get lastbootuptime` command - parses boot timestamp and calculates elapsed time
  2. Fallback: `systeminfo` command - extracts "System Boot Time" and calculates uptime
  3. Final fallback: Returns null if both methods fail
- Returns uptime in seconds, which frontend formats and displays
- Automatically updates every second on the frontend using `setInterval()`

---

## 🚀 API Endpoints

### POST `/functions/register.php`
**Request:**
```
POST /functions/register.php
Content-Type: application/x-www-form-urlencoded

username=john_doe&email=john@example.com&password=MyPassword123
```

**Response (Success - 200):**
```json
{
    "success": true,
    "message": "Registration successful!",
    "expires_in": 600,
    "expires_at": "2026-04-27T15:30:00Z",
    "expires_at_ms": 1735000000000,
    "redirect": "/panel"
}
```

**Response (Error - 422):**
```json
{
    "success": false,
    "error": "Username must be between 3 and 50 characters."
}
```

### POST `/functions/login.php`
**Request:**
```
POST /functions/login.php
Content-Type: application/x-www-form-urlencoded

username=john_doe&password=MyPassword123
```

**Response (Success - 200):**
```json
{
    "success": true,
    "message": "Login successful!",
    "user": {
        "username": "john_doe",
        "email": "john@example.com"
    },
    "expires_in": 600,
    "expires_at": "2026-04-27T15:30:00Z",
    "expires_at_ms": 1735000000000,
    "redirect": "/panel"
}
```

### GET `/functions/session.php`
**Request:**
```
GET /functions/session.php
```

**Response (Success - 200):**
```json
{
    "success": true,
    "username": "john_doe",
    "email": "john@example.com",
    "expires_at": "2026-04-27T15:30:00Z",
    "expires_at_ms": 1735000000000
}
```

**Response (Unauthorized - 401):**
```json
{
    "success": false,
    "error": "Unauthorized"
}
```

### GET `/functions/panel.php`
**Request:**
```
GET /functions/panel.php
(Must have valid session)
```

**Response (Success - 200):**
```json
{
    "success": true,
    "username": "john_doe",
    "email": "john@example.com",
    "expires_at": "2026-04-27T15:30:00Z",
    "expires_at_ms": 1735000000000,
    "registered_users": 42,
    "honor_level": 0,
    "server_uptime_seconds": 345600
}
```

### POST `/functions/logout.php`
**Request:**
```
POST /functions/logout.php
```

**Response (Success - 200):**
```json
{
    "success": true
}
```

---

## ⚙️ Setup Instructions

### 1. Database Setup
```bash
# Connect to MySQL
mysql -u root -p

# Run the SQL from database.sql
source DB/database.sql;
```

### 2. Configure Database Connection
```bash
# Edit DB/db_config.php
nano DB/db_config.php
```

Update:
```php
define('DB_HOST', 'localhost');      // Your DB server
define('DB_USER', 'nightwatch_app'); // DB user
define('DB_PASSWORD', 'STRONG_PASSWORD'); // Change this!
define('DB_NAME', 'Registration');   // DB name
```

### 3. Set File Permissions
```bash
# Make config file readable only by web server
chmod 600 DB/db_config.php

# Make rate limit directory writable
mkdir -p /tmp/nightwatch_rate_limits
chmod 700 /tmp/nightwatch_rate_limits
```

### 4. Configure Web Server
```apache
# For Apache, ensure mod_rewrite is enabled
a2enmod rewrite

# Create .htaccess (if needed for URL rewriting)
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
</IfModule>
```

### 5. PHP Configuration
```ini
; php.ini settings
session.name = "nightwatch_session"
session.use_cookies = 1
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.gc_maxlifetime = 600
```

---

## 🐛 Troubleshooting

### "Database connection failed"
- Check DB_HOST, DB_USER, DB_PASSWORD in db_config.php
- Verify MySQL is running
- Check user permissions: `GRANT ALL PRIVILEGES ON Registration.* TO 'nightwatch_app'@'localhost';`

### "Rate limit exceeded"
- Wait the specified time before retrying
- Check /tmp/nightwatch_rate_limits/ folder (delete files to reset)

### Session expires too quickly
- Check `NIGHTWATCH_SESSION_TTL` in common.php (default 600 = 10 min)
- Verify server time is correct
- Check session.gc_maxlifetime in php.ini

### "Unauthorized" on dashboard
- Clear browser cookies and reload
- Check if browser is sending cookies (credentials: 'same-origin')
- Verify HTTPS/HTTP consistency (check is_https_request())

### Dashboard statistics resetting to 0
**Problem**: "All Registered Users" count shows correct value initially, then resets to 0 after ~1 second

**Root Cause**: The `animation.js` file had a bug where it would check for the `data-target` attribute incorrectly:
```javascript
// OLD (BUGGY):
const target = Number(stat.getAttribute('data-target'));  // Returns 0 if null
if (!Number.isFinite(target)) { return; }  // 0 is finite, doesn't return early!
```
This caused it to overwrite stat values with 0 after the page loaded.

**Solution Applied**:
- Fixed attribute check to verify attribute exists first
- Added `data-target` attributes to all stat elements in HTML
- Updated `renderStats()` to dynamically set `data-target` when data loads

**Fix Details**:
```javascript
// NEW (FIXED):
const dataTargetAttr = stat.getAttribute('data-target');  // Returns null if missing
if (!dataTargetAttr) { return; }  // Exits early if no attribute
const target = Number(dataTargetAttr);
```

### Server uptime always showing 0 or "Unavailable"
**Problem**: Server uptime displays as 0 or "Unavailable" on Windows systems

**Root Cause**: The `wmic` command used to get Windows boot time might fail if:
- `shell_exec()` is disabled in php.ini
- `wmic` command is unavailable on the system
- Command syntax issues

**Solution Applied**:
- Added proper error handling with `@` operator
- Implemented fallback method using `systeminfo` command
- Improved timestamp parsing for Windows format
- Added checks for command availability before execution

**Windows Uptime Detection Methods** (in priority order):
1. **Primary**: `wmic os get lastbootuptime` - Parses boot timestamp (YYYYMMDDHHMMSS format)
2. **Fallback**: `systeminfo | find "System Boot Time"` - Extracts boot time from system info
3. **Final**: Returns null if both methods fail (displays "Unavailable")

### Password verification fails
- Ensure password_hash() uses PASSWORD_ARGON2ID
- Don't modify password in database directly
- Verify plaintext password length (min 8 chars)

---

## 🔧 Code Walk-through

### How Login Works (Step-by-Step)

**Frontend (login/index.html):**
```javascript
1. User enters username/email and password
2. Form submit event triggered
3. Get form data and validate:
   - Not empty
   - Email format if applicable
4. Make POST request to /functions/login.php
5. Wait for response
6. If successful → Show success message → Redirect after 700ms
7. If failed → Show error message → Stay on page
```

**Backend (functions/login.php):**
```php
1. Check request method is POST (security)
2. Check rate limit: 8 attempts per 5 minutes
3. Get username and password from POST
4. Validate input:
   - Not empty
   - Correct length
   - Valid format (email if contains @)
5. Connect to database
6. Prepare SQL query to find user by username OR email
7. Execute query with prepared statement
8. Fetch result
9. Verify password with password_verify()
10. If credentials correct:
    - Generate random token
    - Create user session
    - Update database with token
    - Return success response
11. If credentials wrong:
    - Return generic "Invalid credentials" message
12. Close database connections
13. Send JSON response to frontend
```

### How Session Management Works

**Creating Session (After Login/Register):**
```php
1. start_app_session() - Initialize PHP session
2. session_regenerate_id(true) - New ID after login (security)
3. Set session variables:
   - $_SESSION['user_id'] = user id
   - $_SESSION['username'] = username
   - $_SESSION['email'] = email
   - $_SESSION['expires_at'] = time() + 600
4. Return expiration timestamp
```

**Checking Session (On Every Protected Page):**
```php
1. start_app_session() - Resume existing session
2. Get user_id and expires_at from $_SESSION
3. Check if both exist and are numeric
4. Check if time() >= expires_at (expired?)
5. If expired:
   - destroy_app_session()
   - Return null
6. If valid:
   - Return user data array
```

**Destroying Session (On Logout):**
```php
1. Clear all $_SESSION variables: $_SESSION = []
2. Delete session cookie from client (set to past time)
3. Destroy server-side session file
```

### How Dashboard Data is Fetched (`/functions/panel.php`)

**Frontend Trigger:**
```javascript
1. User authenticated and logged in
2. initPanel() calls loadPanelData()
3. authFetch('/functions/panel.php') makes GET request
4. Response parsed and stats rendered
```

**Backend Processing (panel.php):**
```php
1. Verify authenticated session exists
   - Check $_SESSION['user_id']
   - Check token hasn't expired
   
2. Connect to database
   
3. Fetch current user details
   - Query user record by ID
   - Ensure user still exists (not deleted)
   
4. Count total registered users
   - SELECT COUNT(*) FROM users
   - Return count or 0 if query fails
   
5. Calculate server uptime using getServerUptimeSeconds()
   - Try Linux /proc/uptime file (reads system uptime directly)
   - If Windows, try wmic command (primary method):
     * Executes: wmic os get lastbootuptime /value
     * Parses boot timestamp in YYYYMMDDHHMMSS format
     * Calculates: current_time - boot_time
   - If wmic fails, try systeminfo command (fallback):
     * Executes: systeminfo | find "System Boot Time"
     * Extracts time in m/d/YYYY h:i:s A format
     * Calculates uptime from boot time
   - If all methods fail, returns null
   
6. Build response object with:
   - username: Current user's name
   - email: Current user's email
   - expires_at: Session expiration in ISO 8601 format
   - expires_at_ms: Session expiration in milliseconds (for JS)
   - registered_users: Total user count from database
   - honor_level: Placeholder for future features (always 0)
   - server_uptime_seconds: System uptime in seconds (or null if unavailable)
   
7. Close database connection
8. Send JSON response
```

**Response Data:**
```json
{
    "success": true,
    "username": "john_doe",
    "email": "john@example.com",
    "expires_at": "2026-04-28T14:35:00Z",
    "expires_at_ms": 1735000500000,
    "registered_users": 42,
    "honor_level": 0,
    "server_uptime_seconds": 345600
}
```

**Frontend Display Processing:**
```javascript
1. Receive response data from panel.php
2. Update username display:
   - Set user name text
   - Create avatar with first 2 letters in uppercase
3. Call renderStats(data) to update statistics:
   - Get registered_users count from response
   - Set data-target attribute on registered users element
   - Set text content immediately to count value
   - Set honor_level data-target to "0"
   - Initialize server uptime counter with startServerUptimeCounter()
4. Start session countdown timer with startTokenCountdown()
5. animation.js detects new data-target values and animates counters
6. Server uptime counter updates every second automatically
```

---

## 📈 Performance Considerations

### Optimizations Included
1. **Session caching** - Frontend caches session in memory
2. **Prepared statements** - Database queries are optimized
3. **Rate limit file cleanup** - Old attempts automatically removed
4. **Server uptime caching** - Not recalculated on every request

### Potential Improvements
1. Add database indexes on username and email
2. Implement session table instead of files (for distributed systems)
3. Add CORS headers for API access
4. Implement refresh token rotation
5. Add activity logging

---

## 📝 Common Tasks

### Change Session Timeout
```php
// In functions/common.php
const NIGHTWATCH_SESSION_TTL = 600;  // Change 600 to desired seconds
```

### Add New User Role
```php
// 1. Add role column to users table:
ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'user';

// 2. Add role to session variables:
$_SESSION['role'] = (string) $user['role'];

// 3. Check role in functions:
if ($_SESSION['role'] !== 'admin') {
    send_json(['error' => 'Admin only'], 403);
}
```

### Enable HTTPS Requirements
```php
// In functions/common.php - require HTTPS for all requests
if (!is_https_request()) {
    send_json(['error' => 'HTTPS required'], 400);
}
```

### Add Email Verification
```php
// 1. Add verified column:
ALTER TABLE users ADD COLUMN verified BOOLEAN DEFAULT 0;

// 2. After registration, send verification email
// 3. Check verified status before allowing login
```

---

## 🎓 Learning Path

If you're learning this codebase, here's the recommended order:

1. **Database** - Understand the schema in `DB/database.sql`
2. **Common Functions** - Learn utilities in `functions/common.php`
3. **Registration** - Simplest flow: `functions/register.php` + `register/index.html`
4. **Login** - Similar flow: `functions/login.php` + `login/index.html`
5. **Session Management** - How sessions work: `functions/session.php`
6. **Frontend Auth** - JavaScript handling: `js/auth.js`
7. **Dashboard** - Complex frontend: `panel/index.html`
8. **Frontend Animations** - Canvas/CSS: `animation.js` and `style.css`

---

## 📞 Support & Debugging

### Enable Debug Mode
```php
// Add to common.php for development only:
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log queries:
$conn->query_log = true;
```

### Check Server Logs
```bash
# PHP-FPM errors
tail -f /var/log/php-fpm.log

# Apache errors
tail -f /var/log/apache2/error.log

# MySQL errors
tail -f /var/log/mysql/error.log
```

### Browser Developer Tools
```javascript
// In browser console:
getSession()          // Check current session
getTokenExpiry()      // Check expiration time
clearAuth('/login')   // Manual logout
```

---

## 📚 References

- PHP MySQLi: https://www.php.net/manual/en/book.mysqli.php
- Password Hashing: https://www.php.net/manual/en/function.password-hash.php
- Sessions: https://www.php.net/manual/en/book.session.php
- OWASP: https://owasp.org/

