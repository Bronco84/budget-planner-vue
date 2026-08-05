import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Gracefully recover from expired CSRF/session (419) on Inertia navigations.
// Inertia's router uses its own axios instance, so the window.axios CSRF-retry
// interceptor in bootstrap.js does not cover these requests. A 419 returns a
// non-Inertia page the router can't process, which otherwise leaves the user
// stuck until they manually refresh (e.g. after trusted-device auto-login
// regenerates the session under a stale page). Reloading pulls a fresh token.
router.on('invalid', (event) => {
    if (event.detail.response?.status === 419) {
        event.preventDefault();
        window.location.reload();
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue);

        // Suppress specific Vue warnings
        app.config.warnHandler = (msg, instance, trace) => {
            // Suppress "Extraneous non-emits event listeners" warnings
            if (msg.includes('Extraneous non-emits event listeners')) {
                return;
            }
            // Allow other warnings to show
            console.warn('[Vue warn]:', msg, trace);
        };

        return app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
