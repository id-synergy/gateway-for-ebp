<script setup>
// Carte à arrondi généreux et uniforme (style retenu : comme l'écran Comptes). Pas de coin coupé.
// Les props corner/badge sont conservées pour compatibilité mais ignorées.
defineProps({
    corner: { type: String, default: 'tl' },
    badge: { type: Boolean, default: true },
});
</script>

<template>
    <div class="bg-white" style="border:1px solid #E4DFCE; border-radius:1.5rem">
        <slot />
    </div>
</template>
