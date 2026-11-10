<script setup>
// Logo de la gateway : deux nœuds reliés (app -> EBP). Générique, sans référence EBP.
defineProps({
    size: { type: Number, default: 32 },
    rounded: { type: Number, default: 8 },
});
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 48 48" aria-hidden="true">
        <rect x="2" y="2" width="44" height="44" :rx="rounded" fill="#0046FE" />
        <line x1="16" y1="24" x2="32" y2="24" stroke="#fff" stroke-width="2.4" stroke-linecap="round" />
        <circle cx="16" cy="24" r="4.5" fill="#002C52" stroke="#fff" stroke-width="2.4" />
        <circle cx="32" cy="24" r="4.5" fill="#F6F2E4" />
    </svg>
</template>
