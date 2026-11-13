<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Languages } from 'lucide-vue-next';

// Bascule FR/EN. Deux choses doivent suivre le changement :
//  - le FRONT (libellés vue-i18n) : on change `locale` tout de suite, sans attendre
//    le serveur, pour une bascule instantanée de toute l'interface Vue ;
//  - le BACK (flash, libellés d'audit rendus côté PHP) : on pose le cookie via
//    /locale/{code} puis on recharge les props Inertia (reload), que SetLocale
//    reconstruit dans la nouvelle langue.
// <html lang> est mis à jour en parallèle pour l'accessibilité.
const { locale } = useI18n();
const page = usePage();

const current = computed(() => locale.value);

function switchTo(code) {
    if (code === locale.value) {
        return;
    }
    locale.value = code;
    document.documentElement.setAttribute('lang', code);

    router.get(`/locale/${code}`, {}, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <div class="flex items-center gap-0.5 rounded-lg border border-border p-0.5" role="group" aria-label="Langue / Language">
        <Languages class="size-4 text-muted-foreground mx-1.5" aria-hidden="true" />
        <button
            v-for="code in ['fr', 'en']" :key="code" type="button"
            @click="switchTo(code)"
            class="rounded-md px-2 py-1 text-xs font-medium uppercase transition-colors"
            :class="current === code
                ? 'bg-primary text-primary-foreground'
                : 'text-muted-foreground hover:bg-muted'"
            :aria-pressed="current === code"
        >
            {{ code }}
        </button>
    </div>
</template>
