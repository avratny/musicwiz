import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Entwicklung: `vite build --watch` (Container „assets“). Laufzeitdaten (Cache, Sessions, Vorschaubilder, Logs),
// Musikdateien und Tests nie beobachten – sonst baut jeder Cache-Eintrag neu. Beim Neubau public/build nicht leeren,
// damit das Manifest nie kurz fehlt (sonst Fehler 500 auf jeder Seite, die genau dann lädt).
const watching = process.argv.includes('--watch');
const ignored = ['**/storage/app/**', '**/storage/framework/cache/**', '**/storage/framework/sessions/**', '**/storage/logs/**',
    '**/public/**', '**/demofiles/**', '**/vendor/**', '**/node_modules/**', '**/tests/**', '**/.git/**'];

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        emptyOutDir: !watching,
        watch: watching ? { exclude: ignored, chokidar: { ignored } } : null,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
