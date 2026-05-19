import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const backend = env.VITE_PHP_BACKEND_URL || 'http://localhost';
  const verifyBackendTls = backend.startsWith('https://') && env.VITE_PHP_BACKEND_INSECURE_TLS !== '1';

  return {
    plugins: [react()],
    css: {
      devSourcemap: false,
    },
    build: {
      sourcemap: false,
      minify: 'esbuild',
      assetsInlineLimit: 0,
      rollupOptions: {
        output: {
          sourcemapExcludeSources: true,
        },
      },
    },
    server: {
      host: '127.0.0.1',
      cors: false,
      proxy: {
        '/functions': {
          target: backend,
          changeOrigin: false,
          secure: verifyBackendTls,
        },
      },
    },
    preview: {
      host: '127.0.0.1',
    },
  };
});
