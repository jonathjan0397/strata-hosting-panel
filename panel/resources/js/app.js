import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue, route } from '../../vendor/tightenco/ziggy';

// Keep window.Ziggy in sync so route() works inside <script setup>
router.on('navigate', (event) => {
    window.Ziggy = event.detail.page.props.ziggy;
});
window.route = route;

createInertiaApp({
    title: (title) => title ? `${title} — Strata Panel` : 'Strata Panel',

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        // Seed Ziggy config from the initial page load
        if (props.initialPage?.props?.ziggy) {
            window.Ziggy = props.initialPage.props.ziggy;
        }

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },

    progress: {
        color: '#6366f1',
    },
});
