import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createI18nInstance, SUPPORTED_LOCALES } from './i18n';

createInertiaApp({
    title: (title) => (title ? `${title} — Gateway EBP` : 'Gateway EBP'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        // Locale résolue côté serveur (cookie > navigateur > fallback), passée en prop partagée.
        const locale = props.initialPage.props.locale;
        const i18n = createI18nInstance(locale);

        // Reflète la langue sur <html lang> pour l'accessibilité et le SEO.
        if (SUPPORTED_LOCALES.includes(locale)) {
            document.documentElement.setAttribute('lang', locale);
        }

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
    progress: { color: '#4338ca' },
});
