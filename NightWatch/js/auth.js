(function () {
  // ============================================================================
  // AUTH.JS - CLIENT-SIDE AUTHENTICATION MANAGER
  // ============================================================================
  // This IIFE (Immediately Invoked Function Expression) provides authentication utilities
  // for the frontend: login checks, session management, protected API calls
  // Uses closure to keep sessionCache private and prevent direct access
  
  // ============================================================================
  // PRIVATE: Configuration constants
  // ============================================================================
  // URL to redirect to if session expires or user not authenticated
  const DEFAULT_REDIRECT = '/login';
  
  // Cache for session data (stored in memory, cleared on page reload)
  let sessionCache = null;
  
  // Flag to track if session has been fetched from server
  let sessionLoaded = false;

  // ============================================================================
  // PRIVATE: readJsonSafe() - Safely parse JSON response
  // ============================================================================
  // Handles case where response is not valid JSON
  // Returns parsed object or null if parsing fails
  async function readJsonSafe(response) {
    try {
      // Try to parse response as JSON
      return await response.json();
    } catch (error) {
      // If JSON parsing fails, return null
      return null;
    }
  }

  // ============================================================================
  // PRIVATE: getSession() - Fetch session from server or return cached
  // ============================================================================
  // First call fetches from server, subsequent calls return cache
  // forceRefresh=true bypasses cache and fetches fresh session
  async function getSession(forceRefresh = false) {
    // If session already loaded and not forcing refresh, return cache
    if (sessionLoaded && !forceRefresh) {
      return sessionCache;
    }

    // Prepare to fetch session from server
    let response;
    try {
      // Send GET request to session.php endpoint
      response = await fetch('/functions/session.php', {
        method: 'GET',
        // Send cookies with request (needed for session authentication)
        credentials: 'same-origin',
        // Don't cache this response - always get fresh data
        cache: 'no-store',
        headers: {
          // Tell server we expect JSON response
          Accept: 'application/json',
        },
      });
    } catch (error) {
      // Network error - mark as loaded and return null
      sessionCache = null;
      sessionLoaded = true;
      return null;
    }

    // Check if response was successful (status 200)
    if (!response.ok) {
      // Not authenticated or server error
      sessionCache = null;
      sessionLoaded = true;
      return null;
    }

    // Parse response as JSON
    const data = await readJsonSafe(response);
    
    // Cache the result (data if success, null if not)
    sessionCache = data && data.success ? data : null;
    
    // Mark session as loaded
    sessionLoaded = true;
    
    // Return cached session
    return sessionCache;
  }

  // ============================================================================
  // PUBLIC: requireAuth() - Verify user is logged in or redirect to login
  // ============================================================================
  // Used at start of protected pages to verify authentication
  // If not authenticated, redirects to login page
  async function requireAuth(redirectTo = DEFAULT_REDIRECT) {
    // Force fetch fresh session from server
    const session = await getSession(true);
    
    // If no valid session returned
    if (!session) {
      // Redirect to login page
      window.location.replace(redirectTo);
      return null;
    }

    // Return session data
    return session;
  }

  // ============================================================================
  // PUBLIC: clearAuth() - Logout user and clear session
  // ============================================================================
  // Called on logout - clears local cache and server session
  async function clearAuth(redirectTo = null) {
    // Clear local session cache
    sessionCache = null;
    // Mark as loaded to prevent fetching expired session
    sessionLoaded = true;

    // Attempt to tell server to destroy session
    try {
      await fetch('/functions/logout.php', {
        method: 'POST',
        // Send cookies (needed for server to identify session to destroy)
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
        },
      });
    } catch (error) {
      // Logout network error is not critical - clear local state anyway
    }

    // If redirect URL provided, redirect after logout
    if (redirectTo) {
      window.location.replace(redirectTo);
    }
  }

  // ============================================================================
  // PUBLIC: getTokenExpiry() - Get session expiration time
  // ============================================================================
  // Returns expiration timestamp in milliseconds, or null if no session
  function getTokenExpiry() {
    // Get expiration from cached session (in milliseconds)
    const expiresAtMs = sessionCache && Number(sessionCache.expires_at_ms);
    // Return if valid number, null otherwise
    return Number.isFinite(expiresAtMs) ? expiresAtMs : null;
  }

  // ============================================================================
  // PUBLIC: authFetch() - Make API request with authentication
  // ============================================================================
  // Wrapper around fetch() that handles 401 (unauthorized) responses
  // Automatically logs out user if token expired
  async function authFetch(url, options = {}) {
    // Make request with merged options
    const response = await fetch(url, {
      ...options,
      // Send cookies for authentication
      credentials: 'same-origin',
      // Merge with provided headers
      headers: new Headers(options.headers || {}),
    });

    // Check if response is 401 Unauthorized (session expired)
    if (response.status === 401) {
      // Clear session and redirect to login
      await clearAuth(DEFAULT_REDIRECT);
      // Return null to indicate authentication failed
      return null;
    }

    // Return response for caller to handle
    return response;
  }

  // ============================================================================
  // EXPORT: Make functions globally available to frontend
  // ============================================================================
  // These are accessed as window.requireAuth(), window.clearAuth(), etc.
  window.clearAuth = clearAuth;
  window.getSession = getSession;
  window.getTokenExpiry = getTokenExpiry;
  window.requireAuth = requireAuth;
  window.authFetch = authFetch;
  
  // ============================================================================
  // END: All functions are now globally accessible via window object
  // ============================================================================
})();
