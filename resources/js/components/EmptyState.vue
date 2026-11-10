<script setup>
// État vide : icône ronde, titre, texte, slot pour une action. Style EBP (rond, bleu clair).
defineProps({
    icon: { type: [Object, Function], default: null },
    title: { type: String, default: 'Rien pour l’instant' },
    text: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center text-center py-14 px-6">
        <div class="size-16 rounded-full bg-primary/10 text-primary grid place-items-center mb-4">
            <component :is="icon" v-if="icon" class="size-7" />
        </div>
        <h3 class="text-base font-medium text-[#002C52]">{{ title }}</h3>
        <p v-if="text" class="text-sm text-muted-foreground mt-1 max-w-sm">{{ text }}</p>
        <div v-if="$slots.default" class="mt-5"><slot /></div>
    </div>
</template>
