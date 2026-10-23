import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    build: {
        // The desk ships the Kolguyev map in one file. That file is larger than Vite's default notice.
        chunkSizeWarningLimit: 1600,
    },
    test: {
        environment: 'node',
    },
});