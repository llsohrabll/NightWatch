(function () {
  const DEFAULT_REDIRECT = '/login';
  let sessionCache = null;
  let sessionLoaded = false;

  async function readJsonSafe(response) {
    try {
      return await response.json();
    } catch (error) {
      return null;
    }
  }

  async function getSession(forceRefresh = false) {
    if (sessionLoaded && !forceRefresh) {
      return sessionCache;
    }

    let response;
    try {
      response = await fetch('/functions/session.php', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
        },
      });
    } catch (error) {
      sessionCache = null;
      sessionLoaded = true;
      return null;
    }

    if (!response.ok) {
      sessionCache = null;
      sessionLoaded = true;
      return null;
    }

    const data = await readJsonSafe(response);
    sessionCache = data && data.success ? data : null;
    sessionLoaded = true;
    return sessionCache;
  }

  async function requireAuth(redirectTo = DEFAULT_REDIRECT) {
    const session = await getSession(true);
    if (!session) {
      window.location.replace(redirectTo);
      return null;
    }

    return session;
  }

  async function clearAuth(redirectTo = null) {
    sessionCache = null;
    sessionLoaded = true;

    try {
      await fetch('/functions/logout.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
        },
      });
    } catch (error) {
      // Ignore logout transport errors and continue clearing local state.
    }

    if (redirectTo) {
      window.location.replace(redirectTo);
    }
  }

  function getTokenExpiry() {
    const expiresAtMs = sessionCache && Number(sessionCache.expires_at_ms);
    return Number.isFinite(expiresAtMs) ? expiresAtMs : null;
  }

  async function authFetch(url, options = {}) {
    const response = await fetch(url, {
      ...options,
      credentials: 'same-origin',
      headers: new Headers(options.headers || {}),
    });

    if (response.status === 401) {
      await clearAuth(DEFAULT_REDIRECT);
      return null;
    }

    return response;
  }

  window.clearAuth = clearAuth;
  window.getSession = getSession;
  window.getTokenExpiry = getTokenExpiry;
  window.requireAuth = requireAuth;
  window.authFetch = authFetch;
})();
