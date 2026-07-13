import path from 'node:path';
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

const projectRoot = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@admin': path.resolve(projectRoot, 'resources/admin'),
      '@shared': path.resolve(projectRoot, 'resources/js/shared'),
      '@entities': path.resolve(projectRoot, 'resources/js/entities'),
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./resources/admin/test/setup.ts'],
    include: ['resources/admin/**/*.{test,spec}.{ts,tsx}'],
    fileParallelism: false,
    passWithNoTests: true,
  },
});
