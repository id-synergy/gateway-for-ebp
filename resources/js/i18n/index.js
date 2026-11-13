import { createI18n } from 'vue-i18n';

import fr from './fr.json';
import en from './en.json';

export const SUPPORTED_LOCALES = ['fr', 'en'];

/**
 * Instancie vue-i18n. La locale initiale vient des props Inertia partagées
 * (prop `locale`, résolue côté serveur par le middleware SetLocale), de sorte
 * que le premier rendu est déjà dans la bonne langue, sans clignotement.
 */
export function createI18nInstance(initialLocale) {
    const locale = SUPPORTED_LOCALES.includes(initialLocale) ? initialLocale : 'fr';

    return createI18n({
        legacy: false, // API Composition (useI18n)
        globalInjection: true, // $t disponible dans tous les templates
        locale,
        fallbackLocale: 'fr',
        messages: { fr, en },
    });
}
