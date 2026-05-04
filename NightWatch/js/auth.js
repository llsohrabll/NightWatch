(function () {
  // ============================================================================
  // AUTH.JS - CLIENT-SIDE AUTHENTICATION MANAGER
  // ============================================================================
  // This file gives the browser a small set of auth helpers:
  // check the session, call protected endpoints, and log out safely.
  // The wrapper function keeps the cache variables private.
  
  // ============================================================================
  // PRIVATE: Configuration constants
  // ============================================================================
  // Where the browser should go when no valid session exists.
  const DEFAULT_REDIRECT = '/login';
  
  // Small in-memory cache for the current session response.
  let sessionCache = null;
  
  // Track whether we already asked the server for session data.
  let sessionLoaded = false;

  // ============================================================================
  // PRIVATE: readJsonSafe() - Safely parse JSON response
  // ============================================================================
  // If the server returns invalid JSON, return null instead of throwing.
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
  // First call fetches from the server. Later calls can reuse the cached result.
  async function getSession(forceRefresh = false) {
    // If session already loaded and not forcing refresh, return cache
    if (sessionLoaded && !forceRefresh) {
      return sessionCache;
    }

    // Ask the backend whether this browser still has a valid session.
    let response;
    try {
      // Send GET request to session.php endpoint
      response = await fetch('/functions/session.php', {
        method: 'GET',
        // Send the session cookie with the request.
        credentials: 'same-origin',
        // Session state should always be fresh.
        cache: 'no-store',
        headers: {
          // This tells the server what format we want back.
          Accept: 'application/json',
        },
      });
    } catch (error) {
      // Treat network problems the same as "no session" here.
      sessionCache = null;
      sessionLoaded = true;
      return null;
    }

    // Any non-200 response means the caller should treat the user as signed out.
    if (!response.ok) {
      // For this helper, both auth failure and server failure mean "no usable session".
      sessionCache = null;
      sessionLoaded = true;
      return null;
    }

    // Parse response as JSON
    const data = await readJsonSafe(response);
    
    // Store the session object so other checks do not need a second request.
    sessionCache = data && data.success ? data : null;
    
    // Mark session as loaded
    sessionLoaded = true;
    
    // Return cached session
    return sessionCache;
  }

  // ============================================================================
  // PUBLIC: requireAuth() - Verify user is logged in or redirect to login
  // ============================================================================
  // Protected pages call this before loading private data.
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
  // This clears the browser-side cache and also asks the server to destroy the session.
  async function clearAuth(redirectTo = null) {
    // Clear local session cache
    sessionCache = null;
    // Prevent old cached state from being reused.
    sessionLoaded = true;

    // Even if the network request fails, we still clear local state.
    try {
      await fetch('/functions/logout.php', {
        method: 'POST',
        // The server needs the session cookie to know which session to destroy.
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
        },
      });
    } catch (error) {
      // Local sign-out should still continue.
    }

    // If redirect URL provided, redirect after logout
    if (redirectTo) {
      window.location.replace(redirectTo);
    }
  }

  // ============================================================================
  // PUBLIC: getTokenExpiry() - Get session expiration time
  // ============================================================================
  // The panel uses this to show a countdown timer.
  function getTokenExpiry() {
    // Get expiration from cached session (in milliseconds)
    const expiresAtMs = sessionCache && Number(sessionCache.expires_at_ms);
    // Return if valid number, null otherwise
    return Number.isFinite(expiresAtMs) ? expiresAtMs : null;
  }

  // ============================================================================
  // PUBLIC: authFetch() - Make API request with authentication
  // ============================================================================
  // Use this instead of fetch() when the endpoint requires a signed-in session.
  async function authFetch(url, options = {}) {
    // Make request with merged options
    const response = await fetch(url, {
      ...options,
      // Include the session cookie automatically.
      credentials: 'same-origin',
      // Keep any headers passed by the caller.
      headers: new Headers(options.headers || {}),
    });

    // 401 means the backend no longer accepts the session.
    if (response.status === 401) {
      // Clear session and redirect to login
      await clearAuth(DEFAULT_REDIRECT);
      // Returning null makes the caller stop normal page work.
      return null;
    }

    // Return response for caller to handle
    return response;
  }

  // ============================================================================
  // EXPORT: Make functions globally available to frontend
  // ============================================================================
  // Expose the helpers globally so the HTML pages can call them directly.
  window.clearAuth = clearAuth;
  window.getSession = getSession;
  window.getTokenExpiry = getTokenExpiry;
  window.requireAuth = requireAuth;
  window.authFetch = authFetch;
  
  // ============================================================================
  // END: All functions are now globally accessible via window object
  // ============================================================================
})();
