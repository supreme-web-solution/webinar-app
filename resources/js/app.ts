import { createInertiaApp } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import '../css/app.css';
import { initializeTheme } from '@/composables/useAppearance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Wayfinder bakes APP_URL into route actions at build time. If production assets
 * were built with the wrong APP_URL (e.g. https://localhost), Inertia would POST
 * to the wrong host and trigger CORS errors. Rewrite cross-origin visit URLs to
 * the current browser origin while preserving path, query, and hash.
 */
router.on('before', (event) => {
    if (typeof window === 'undefined') {
        return;
    }

    const visit = event.detail.visit;
    let url: URL;

    try {
        url =
            visit.url instanceof URL
                ? visit.url
                : new URL(String(visit.url), window.location.origin);
    } catch {
        return;
    }

    if (url.origin !== window.location.origin) {
        visit.url = new URL(
            url.pathname + url.search + url.hash,
            window.location.origin,
        );
    }
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
