import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => ({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
            ],
            refresh: true,
        }),
        tailwindcss(),
        react(),
    ],
    resolve: {
        alias: {
            // FSD layer aliases
            '@app': '/resources/js/app',
            '@pages': '/resources/js/pages',
            '@widgets': '/resources/js/widgets',
            '@features': '/resources/js/features',
            '@entities': '/resources/js/entities',
            '@shared': '/resources/js/shared',
        },
    },
    build: {
        // تفعيل source maps للتشخيص في production
        sourcemap: true,
        // تحسين رسائل الخطأ
        minify: 'esbuild',
        rollupOptions: {
            output: {
                // تقسيم الملفات لأداء أفضل عند التحميل
                manualChunks: {
                    vendor: ['react', 'react-dom', 'react-router-dom'],
                    ui: ['@tabler/icons-react'],
                    charts: ['recharts'],
                    dnd: ['@dnd-kit/core', '@dnd-kit/sortable', '@dnd-kit/utilities'],
                    i18n: ['i18next', 'react-i18next'],
                },
            },
        },
    },
    server: {
        host: '0.0.0.0',
        hmr: { host: 'localhost' },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
}));
