import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./resources/js/__tests__/setup.ts'],
    include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      include: ['resources/js/**/*.{ts,tsx}'],
      exclude: [
        'resources/js/**/*.test.{ts,tsx}',
        'resources/js/**/*.spec.{ts,tsx}',
        'resources/js/__tests__/**',
        'resources/js/types/**',
      ],
      thresholds: { lines: 85, statements: 85, functions: 85, branches: 70 },
    },
  },
  resolve: {
    alias: {
      // FSD layer aliases
      '@app': path.resolve(__dirname, './resources/js/app'),
      '@pages': path.resolve(__dirname, './resources/js/pages'),
      '@widgets': path.resolve(__dirname, './resources/js/widgets'),
      '@features': path.resolve(__dirname, './resources/js/features'),
      '@entities': path.resolve(__dirname, './resources/js/entities'),
      '@shared': path.resolve(__dirname, './resources/js/shared'),
    },
  },
});
