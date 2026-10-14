import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ command }) => ({
    // Built scripts load the rest of the app (each page is its own file) by path. The default path starts at the
    // top of the website, "/build/...", which is only right when the app is the whole site. Relative paths load each
    // file from beside the one that asked for it, so the app works the same at /, at /adoma/public, or anywhere else
    // (a subfolder under Apache, a subdomain, or a hosted address). Without this, every page after the first one
    // fails to load and the screen stays white. In development the laravel plugin's own setting is used.
    base: command === 'build' ? './' : undefined,

    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
}));
