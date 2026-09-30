import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    // Saat aplikasi dibuka lewat URL tunnel (mis. pratinjau di lingkungan
    // pengembangan), Vite perlu mengenali host tersebut. Daftar ini hanya
    // berlaku untuk dev server; build produksi tidak terpengaruh.
    allowedHosts: ['.modal.host', 'localhost', '127.0.0.1'],
  },
});
