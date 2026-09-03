import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

const SURFACE = process.env.VITE_SURFACE || 'all';

const inputsBySurface = {
    'all': [
        'resources/css/app.css',
        'resources/css/site.css',
        'resources/js/app.js',
        'resources/js/site-editor/index.js',
        'resources/js/site-editor/parent-entry.js',
        'resources/js/site-editor/iframe-entry.js',
        'resources/css/site-editor.css',
    ],
    'agents': [
        'resources/css/app.css',
        'resources/css/site.css',
        'resources/js/app.js',
        'resources/js/save-bar.js',
        'resources/js/site-editor/parent-entry.js',
        'resources/css/site-editor.css',
    ],
    'customer': [
        'resources/css/app.css',
        'resources/css/site.css',
        'resources/js/app.js',
        // Editor-shell entry — clients drive the same WYSIWYG editor as
        // staff. Mirrors the agents-surface inputs so build-customer
        // ships parent-entry.js + the editor CSS.
        'resources/js/site-editor/parent-entry.js',
        'resources/css/site-editor.css',
    ],
    'site-public': [
        'resources/css/app.css',
        'resources/css/site.css',
        'resources/js/app.js',
    ],
    'editor-preview': [
        'resources/js/site-editor/iframe-entry.js',
        'resources/css/site-editor.css',
        // page.blade.php renders inside the editor iframe on this surface,
        // so the compiled site css must ship here too.
        'resources/css/site.css',
    ],
};

const buildDirBySurface = {
    'all': 'build',
    'agents': 'build-agents',
    'customer': 'build-customer',
    'site-public': 'build-site-public',
    'editor-preview': 'build-editor-preview',
};

export default defineConfig({
    plugins: [
        laravel({
            input: inputsBySurface[SURFACE],
            buildDirectory: buildDirBySurface[SURFACE],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
