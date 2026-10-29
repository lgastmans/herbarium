import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    server: {
        cors: {
            origin: 'https://herbarium.test',
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/css/admin.css',
                'resources/js/admin.js',
                'resources/css/public.css',
            ],
            refresh: true,
        }),
    ],
});
