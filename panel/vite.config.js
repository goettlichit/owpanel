import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// All assets are bundled locally. The panel never loads fonts, styles or
// scripts from a CDN (docs/DESIGN.md, rule 1).
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
