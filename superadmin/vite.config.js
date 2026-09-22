import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * The console is served from /superadmin/ by tools/dev-server.mjs (and by
 * Apache in production), so assets are built with that base path.
 *
 * Auto-detects the base path by checking if superadmin/ exists one level up
 * (indicating a root install like /superadmin/) or if we're in a sub-directory
 * install (like /restaurants/superadmin/).
 *
 * You can override it without touching this file:
 *
 *   VITE_BASE=/restaurants/superadmin/ npm run build
 *
 * `npm run dev` inside superadmin/ gives hot reload: the API is proxied to the
 * PHP dev server on :8080.
 */
let base = process.env.VITE_BASE;

if (!base) {
  // Auto-detect by checking the directory structure.
  // If this is at /restaurants/superadmin/, infer the install path.
  // Default to /superadmin/ for root installs.
  const currentDir = import.meta.url.split('/').slice(-3, -1).join('/');
  base = currentDir.includes('restaurants') ? '/restaurants/superadmin/' : '/superadmin/';
}

export default defineConfig({
  base,
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
