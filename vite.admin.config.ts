import path from 'node:path';
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

const projectRoot = path.dirname(fileURLToPath(import.meta.url));
const viteSourceModulePath = /\.(?:[cm]?[jt]sx?|map)$/;

export default defineConfig({
  root: path.resolve(projectRoot, 'resources/admin'),
  plugins: [tailwindcss(), react()],
  resolve: {
    alias: {
      '@admin': path.resolve(projectRoot, 'resources/admin'),
      '@shared': path.resolve(projectRoot, 'resources/js/shared'),
      '@entities': path.resolve(projectRoot, 'resources/js/entities'),
    },
  },
  build: {
    outDir: path.resolve(projectRoot, 'dist-admin'),
    emptyOutDir: true,
    manifest: true,
    sourcemap: true,
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        bypass(request) {
          const pathname = new URL(request.url ?? '/', 'http://vite.local').pathname;
          if (viteSourceModulePath.test(pathname)) {
            return pathname;
          }

          return undefined;
        },
      },
      '/sanctum': 'http://127.0.0.1:8000',
    },
  },
});
