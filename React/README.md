# NightWatch React Frontend

This folder contains the React/Vite version of the NightWatch browser UI. The PHP API in `NightWatch/WebApp/functions` is still the backend contract; the React app calls the same `/functions/*.php` endpoints and keeps the same routes:

- `/`
- `/login`
- `/register`
- `/register/verify`
- `/forgetpassword`
- `/panel`
- `/admin`
- `/users?username=<name>`

## Local Development

Install dependencies:

```bash
npm.cmd install
```

Run the React dev server:

```bash
npm.cmd run dev
```

The dev and preview servers bind to `127.0.0.1` by default. If you intentionally need LAN access, pass a host explicitly for that session instead of changing the default.

If your PHP/Apache backend is not at `http://localhost`, set the proxy target before starting Vite:

```bash
set VITE_PHP_BACKEND_URL=http://localhost
npm.cmd run dev
```

For a local HTTPS backend with a self-signed certificate, set `VITE_PHP_BACKEND_INSECURE_TLS=1` only in that local shell session.

Do not put secrets in `VITE_*` variables. Vite exposes those values to browser code, so this frontend should only receive public configuration such as the local PHP backend URL.

Vite proxies `/functions/*` to the PHP backend during development and preserves the browser-facing host so the existing same-origin checks keep working. In production, serve the built files from the same origin as the PHP endpoints or copy the build into the configured Apache document root.

## Production Build

```bash
npm.cmd run build
```

Source maps are explicitly disabled in `vite.config.js`. The `public/.htaccess` file also denies `*.map` files if they are ever created by mistake, includes the SPA fallback rewrite, and leaves `/functions/` alone for PHP endpoints.
