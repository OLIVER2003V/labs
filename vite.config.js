import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/main.jsx'], // el CSS se importa dentro de main.jsx
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    build: {
        // Un solo archivo JS (sin carga diferida): así funciona en cualquier subcarpeta del servidor
        rollupOptions: { output: { inlineDynamicImports: true } },
        chunkSizeWarningLimit: 2000,
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
