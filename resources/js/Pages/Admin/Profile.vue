<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { ShieldCheck, Mail, User } from 'lucide-vue-next';

const props = defineProps({
    profile: { type: Object, required: true },
});

const qrSvg = ref('');
const recoveryCodes = ref([]);
const confirmCode = ref('');
const busy = ref(false);
const error = ref('');

function csrf() {
    return decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
}
async function api(method, url, body) {
    const res = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) throw new Error(res.status);
    const text = await res.text();
    return text ? JSON.parse(text) : null;
}

async function enable() {
    busy.value = true; error.value = '';
    try {
        await api('POST', '/user/two-factor-authentication');
        const data = await api('GET', '/user/two-factor-qr-code');
        qrSvg.value = data.svg;
    } catch (e) {
        error.value = "Impossible d'activer la 2FA.";
    } finally { busy.value = false; }
}

async function confirm() {
    busy.value = true; error.value = '';
    try {
        await api('POST', '/user/confirmed-two-factor-authentication', { code: confirmCode.value });
        recoveryCodes.value = await api('GET', '/user/two-factor-recovery-codes');
        qrSvg.value = '';
        router.reload({ only: ['profile'] });
    } catch (e) {
        error.value = 'Code invalide, réessayez.';
    } finally { busy.value = false; }
}

async function disable() {
    if (!window.confirm('Désactiver la double authentification sur votre compte ?')) return;
    busy.value = true;
    try {
        await api('DELETE', '/user/two-factor-authentication');
        recoveryCodes.value = []; qrSvg.value = '';
        router.reload({ only: ['profile'] });
    } finally { busy.value = false; }
}
</script>

<template>
    <AdminLayout current="">
        <template #title>Mon profil</template>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Identité -->
            <CutCard>
                <div class="p-5 space-y-4">
                    <h3 class="text-base font-medium text-[#002C52]">Identité</h3>
                    <div class="flex items-center gap-2 text-sm">
                        <span class="size-9 rounded-full bg-primary/10 text-primary grid place-items-center"><User class="size-4" /></span>
                        <div>
                            <div class="text-xs text-muted-foreground">Nom</div>
                            <div class="font-medium text-[#002C52]">{{ profile.name }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <span class="size-9 rounded-full bg-primary/10 text-primary grid place-items-center"><Mail class="size-4" /></span>
                        <div>
                            <div class="text-xs text-muted-foreground">E-mail</div>
                            <div class="font-medium text-[#002C52]">{{ profile.email }}</div>
                        </div>
                    </div>
                </div>
            </CutCard>

            <!-- 2FA -->
            <CutCard>
                <div class="p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-base font-medium text-[#002C52]">Double authentification</h3>
                        <Badge v-if="profile.twoFactorEnabled" variant="default" class="gap-1 bg-[#1D9E75] hover:bg-[#1D9E75]"><ShieldCheck class="size-3" /> Activée</Badge>
                        <Badge v-else variant="secondary">Désactivée</Badge>
                    </div>

                    <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

                    <!-- Activée -->
                    <template v-if="profile.twoFactorEnabled">
                        <p class="text-sm text-muted-foreground">Votre compte est protégé par un code à usage unique.</p>
                        <div v-if="recoveryCodes.length" class="rounded-xl bg-[#F6F2E4] p-4">
                            <div class="text-xs text-muted-foreground mb-2">Codes de récupération (à conserver) :</div>
                            <div class="grid grid-cols-2 gap-1 font-mono text-xs text-[#002C52]">
                                <span v-for="c in recoveryCodes" :key="c">{{ c }}</span>
                            </div>
                        </div>
                        <Button variant="outline" size="sm" :disabled="busy" @click="disable">Désactiver la 2FA</Button>
                    </template>

                    <!-- Flux d'activation -->
                    <template v-else>
                        <template v-if="!qrSvg">
                            <p class="text-sm text-muted-foreground">Ajoutez une couche de sécurité avec une application d'authentification (Google Authenticator, Authy…).</p>
                            <Button :disabled="busy" @click="enable">Activer la 2FA</Button>
                        </template>
                        <template v-else>
                            <p class="text-sm text-muted-foreground">Scannez ce QR code, puis saisissez le code généré pour confirmer.</p>
                            <div class="bg-white rounded-xl p-3 w-fit" v-html="qrSvg"></div>
                            <div class="space-y-2 max-w-48">
                                <Label>Code à 6 chiffres</Label>
                                <Input v-model="confirmCode" inputmode="numeric" placeholder="000000" class="text-center tracking-[0.3em]" />
                            </div>
                            <Button :disabled="busy" @click="confirm">Confirmer</Button>
                        </template>
                    </template>
                </div>
            </CutCard>
        </div>
    </AdminLayout>
</template>
