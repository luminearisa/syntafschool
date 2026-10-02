import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// Target backend untuk proxy pengembangan/pratinjau (dapat ditimpa lewat env).
const apiTarget = process.env.API_PROXY_TARGET ?? 'http://localhost:8001';

const proxy = {
  '/api': { target: apiTarget, changeOrigin: true },
  '/storage': { target: apiTarget, changeOrigin: true },
};

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  // Pratinjau hasil build (dipakai untuk uji UI & tautan tunnel).
  preview: {
    port: 3000,
    host: true,
    allowedHosts: ['.modal.host', 'localhost', '127.0.0.1'],
    proxy,
  },
  server: {
    port: 5173,
    // Saat aplikasi dibuka lewat URL tunnel (mis. pratinjau di lingkungan
    // pengembangan), Vite perlu mengenali host tersebut. Daftar ini hanya
    // berlaku untuk dev server; build produksi tidak terpengaruh.
    allowedHosts: ['.modal.host', 'localhost', '127.0.0.1'],
    proxy,
  },
});
