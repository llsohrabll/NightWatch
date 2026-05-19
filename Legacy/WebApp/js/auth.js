(function () {
  'use strict';

  const DEFAULT_REDIRECT = '/login';
  let sessionCache = null;
  let sessionLoaded = false;
  let csrfToken = null;

  function sessionIsFresh(session) {
    const expiresAtMs = session && Number(session.expires_at_ms);
    return Boolean(session && session.success && Number.isFinite(expiresAtMs) && expiresAtMs > Date.now());
  }

  async function readJsonSafe(response) {
    try {
      return await response.json();
    } catch (error) {
      return null;
    }
  }

  async function getCsrfToken(forceRefresh = false) {
    if (csrfToken && !forceRefresh) return csrfToken;

    try {
      const response = await fetch('/functions/csrf.php', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      const data = await readJsonSafe(response);
      csrfToken = response.ok && data && data.success ? String(data.csrf_token || '') : null;
      return csrfToken;
    } catch (error) {
      csrfToken = null;
      return null;
    }
  }

  function requestNeedsCsrf(method) {
    return !['GET', 'HEAD', 'OPTIONS'].includes(String(method || 'GET').toUpperCase());
  }

  async function withCsrfHeaders(options = {}) {
    const method = options.method || 'GET';
    const headers = new Headers(options.headers || {});
    if (requestNeedsCsrf(method)) {
      const token = await getCsrfToken();
      if (token) headers.set('X-CSRF-Token', token);
    }
    return { ...options, headers };
  }

  async function getSession(forceRefresh = false) {
    if (sessionLoaded && !forceRefresh) {
      if (!sessionIsFresh(sessionCache)) sessionCache = null;
      return sessionCache;
    }

    let response;
    try {
      response = await fetch('/functions/session.php', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
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
    sessionCache = sessionIsFresh(data) ? data : null;
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
      const csrfOptions = await withCsrfHeaders({
        method: 'POST',
        headers: { Accept: 'application/json' },
      });
      await fetch('/functions/logout.php', {
        ...csrfOptions,
        credentials: 'same-origin',
      });
    } catch (error) {
      // Redirect still happens even if the network request fails.
    }

    csrfToken = null;
    if (redirectTo) window.location.replace(redirectTo);
  }

  function getTokenExpiry() {
    const expiresAtMs = sessionCache && Number(sessionCache.expires_at_ms);
    return Number.isFinite(expiresAtMs) ? expiresAtMs : null;
  }

  async function authFetch(url, options = {}, csrfRetry = false) {
    const { authRequired, ...fetchOptions } = options;
    void authRequired;
    try {
      const csrfOptions = await withCsrfHeaders(fetchOptions);
      const response = await fetch(url, {
        ...csrfOptions,
        credentials: 'same-origin',
      });

      // 401 is not always session expiry: login, verification, reset-code, and
      // current-password checks also use it for normal validation failures.
      // Callers that require an active session handle 401 explicitly.
      if (response.status === 403 && requestNeedsCsrf(fetchOptions.method) && !csrfRetry) {
        const data = await readJsonSafe(response);
        const errorText = String((data && data.error) || '').toLowerCase();
        if (errorText.includes('csrf')) {
          csrfToken = null;
          return authFetch(url, options, true);
        }
        return new Response(JSON.stringify(data || { success: false, error: 'Forbidden.' }), {
          status: response.status,
          statusText: response.statusText,
          headers: { 'Content-Type': 'application/json' },
        });
      }

      return response;
    } catch (error) {
      return null;
    }
  }

  window.authFetch = authFetch;
  window.clearAuth = clearAuth;
  window.getCsrfToken = getCsrfToken;
  window.getSession = getSession;
  window.getTokenExpiry = getTokenExpiry;
  window.requireAuth = requireAuth;
})();
