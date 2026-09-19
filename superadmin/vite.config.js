import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * The console is served from /superadmin/ by tools/dev-server.mjs (and by
 * Apache in production), so assets are built with that base path.
 *
 * `npm run dev` inside superadmin/ gives hot reload: the API is proxied to the
 * PHP dev server on :8080.
 */
export default defineConfig({
  base: '/superadmin/',
  plugins: [react()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
    chunkSizeWarningLimit: 900,
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': { target: 'http://localhost:8080', changeOrigin: true },
    },
  },
});
