<script setup>
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck, Plug, KeyRound, Activity } from 'lucide-vue-next';
import GatewayLogo from '@/components/GatewayLogo.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const form = useForm({ email: '', password: '', remember: false });

function submit() {
    form.transform((data) => ({ ...data, remember: data.remember ? 'on' : '' }))
        .post('/login', { onFinish: () => form.reset('password') });
}

const points = [
    { icon: Plug, text: 'Connexion OAuth EBP multi-comptes' },
    { icon: KeyRound, text: "Jetons d'accès par application" },
    { icon: Activity, text: 'Régulation du quota et journalisation' },
];
</script>

<template>
    <div class="min-h-screen grid lg:grid-cols-2">
        <!-- Volet gauche : aplat marine -->
        <div class="relative hidden lg:flex flex-col justify-between p-10 bg-[#002C52] overflow-hidden">
            <div class="absolute -top-24 -right-24 size-72 rounded-full bg-[#0046FE]/25"></div>
            <div class="absolute bottom-10 -left-20 size-64 rounded-full bg-[#0046FE]/15"></div>

            <div class="relative flex items-center gap-3">
                <GatewayLogo :size="40" :rounded="11" />
                <div class="leading-tight">
                    <div class="text-white font-medium">Gateway</div>
                    <div class="text-[#9FB6D1] text-xs">EBP · passerelle</div>
                </div>
            </div>

            <div class="relative">
                <h1 class="text-white text-3xl font-medium leading-tight mb-3">
                    Vos applications,<br>une seule porte vers EBP.
                </h1>
                <p class="text-[#9FB6D1] text-sm max-w-sm mb-8">
                    La passerelle centralise l'accès à l'API EBP SaaS Gestion : authentification, quota, journalisation.
                </p>
                <ul class="space-y-3">
                    <li v-for="p in points" :key="p.text" class="flex items-center gap-3 text-white/90 text-sm">
                        <span class="size-8 rounded-full bg-white/10 grid place-items-center shrink-0">
                            <component :is="p.icon" class="size-4" />
                        </span>
                        {{ p.text }}
                    </li>
                </ul>
            </div>

            <div class="relative text-[#6E8CAE] text-xs">Accès réservé aux administrateurs.</div>
        </div>

        <!-- Volet droit : formulaire sur crème -->
        <div class="flex items-center justify-center p-6 bg-background">
            <div class="w-full max-w-sm">
                <div class="lg:hidden flex justify-center mb-6"><GatewayLogo :size="44" :rounded="12" /></div>
                <h2 class="text-2xl font-medium text-[#002C52] mb-1">Connexion</h2>
                <p class="text-sm text-muted-foreground mb-7">Accédez à l'espace d'administration.</p>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="email">Adresse e-mail</Label>
                        <Input id="email" v-model="form.email" type="email" placeholder="admin@example.com" autocomplete="username" />
                        <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="password">Mot de passe</Label>
                        <Input id="password" v-model="form.password" type="password" autocomplete="current-password" />
                        <p v-if="form.errors.password" class="text-xs text-destructive">{{ form.errors.password }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-muted-foreground">
                        <input v-model="form.remember" type="checkbox" class="gw-check"> Se souvenir de moi
                    </label>
                    <Button type="submit" size="lg" class="w-full" :disabled="form.processing">Se connecter</Button>
                    <p class="text-xs text-muted-foreground flex items-center justify-center gap-1.5 pt-1">
                        <ShieldCheck class="size-3.5" /> Double authentification si activée
                    </p>
                </form>
            </div>
        </div>
    </div>
</template>
