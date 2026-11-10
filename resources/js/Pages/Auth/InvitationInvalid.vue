<script setup>
import { Link } from '@inertiajs/vue3';
import { LinkIcon } from 'lucide-vue-next';
import GatewayLogo from '@/components/GatewayLogo.vue';
import { Button } from '@/components/ui/button';
</script>

<template>
    <div class="min-h-screen grid place-items-center bg-background p-6">
        <div class="w-full max-w-md text-center">
            <div class="flex justify-center mb-6"><GatewayLogo :size="48" :rounded="13" /></div>
            <div class="mx-auto size-14 rounded-full bg-[#FAC775]/30 text-[#633806] grid place-items-center mb-4">
                <LinkIcon class="size-6" />
            </div>
            <h1 class="text-2xl font-medium text-[#002C52] mb-2">Ce lien n'est plus valide</h1>
            <p class="text-sm text-muted-foreground mb-6">
                Cette invitation a peut-être expiré (elle est valable 24 h) ou a déjà été utilisée.
                Demandez une nouvelle invitation à votre administrateur.
            </p>
            <Button as-child variant="outline">
                <Link href="/login">Aller à la connexion</Link>
            </Button>
        </div>
    </div>
</template>
