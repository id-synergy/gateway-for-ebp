<script setup>
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ShieldCheck, Plug, KeyRound, Activity } from 'lucide-vue-next';
import GatewayLogo from '@/components/GatewayLogo.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const { t } = useI18n();

const props = defineProps({
    token: { type: String, required: true },
    name: { type: String, required: true },
    email: { type: String, required: true },
});

const form = useForm({ password: '', password_confirmation: '' });

function submit() {
    form.post(`/invitation/${props.token}`, { onFinish: () => form.reset('password', 'password_confirmation') });
}

const points = [
    { icon: Plug, text: t('auth.points.multiAccount') },
    { icon: KeyRound, text: t('auth.points.perAppTokens') },
    { icon: Activity, text: t('auth.points.quotaLogging') },
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
                    {{ t('auth.acceptInvitation.heroTitle') }}
                </h1>
                <p class="text-[#9FB6D1] text-sm max-w-sm mb-8">
                    {{ t('auth.acceptInvitation.heroSubtitle') }}
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

            <div class="relative text-[#6E8CAE] text-xs">{{ t('auth.acceptInvitation.linkNote') }}</div>
        </div>

        <!-- Volet droit : formulaire sur crème -->
        <div class="flex items-center justify-center p-6 bg-background">
            <div class="w-full max-w-sm">
                <div class="lg:hidden flex justify-center mb-6"><GatewayLogo :size="44" :rounded="12" /></div>
                <h2 class="text-2xl font-medium text-[#002C52] mb-1">{{ t('auth.acceptInvitation.title') }}</h2>
                <p class="text-sm text-muted-foreground mb-7">{{ t('auth.acceptInvitation.subtitle') }}</p>

                <div class="rounded-xl bg-[#F6F2E4] px-4 py-3 mb-5 text-sm">
                    <div class="text-[#002C52] font-medium">{{ name }}</div>
                    <div class="text-muted-foreground">{{ email }}</div>
                </div>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="password">{{ t('auth.acceptInvitation.password') }}</Label>
                        <Input id="password" v-model="form.password" type="password" autocomplete="new-password" />
                        <p class="text-xs text-muted-foreground">{{ t('auth.acceptInvitation.passwordHint') }}</p>
                        <p v-if="form.errors.password" class="text-xs text-destructive">{{ form.errors.password }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="password_confirmation">{{ t('auth.acceptInvitation.passwordConfirm') }}</Label>
                        <Input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                    </div>
                    <Button type="submit" size="lg" class="w-full" :disabled="form.processing">{{ t('auth.acceptInvitation.submit') }}</Button>
                    <p class="text-xs text-muted-foreground flex items-center justify-center gap-1.5 pt-1">
                        <ShieldCheck class="size-3.5" /> {{ t('auth.acceptInvitation.twoFactorHint') }}
                    </p>
                </form>
            </div>
        </div>
    </div>
</template>
