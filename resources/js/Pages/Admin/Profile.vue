<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { ShieldCheck, Mail, User } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    profile: { type: Object, required: true },
});

const qrSvg = ref('');
const recoveryCodes = ref([]);
const confirmCode = ref('');
const busy = ref(false);
const error = ref('');

// Formulaires identité et mot de passe.
const identity = ref({ name: props.profile.name, email: props.profile.email });
const identityMsg = ref('');
const identityErr = ref('');
const pwd = ref({ current_password: '', password: '', password_confirmation: '' });
const pwdMsg = ref('');
const pwdErr = ref('');

async function apiOrErrors(method, url, body) {
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
    if (res.status === 422) {
        const data = await res.json();
        const msgs = Object.values(data.errors || {}).flat();
        throw new Error(msgs[0] || t('profile.errors.invalidData'));
    }
    if (!res.ok) throw new Error(t('profile.errors.generic', { status: res.status }));
    const text = await res.text();
    return text ? JSON.parse(text) : null;
}

async function saveIdentity() {
    busy.value = true; identityMsg.value = ''; identityErr.value = '';
    try {
        await apiOrErrors('PUT', '/user/profile-information', {
            name: identity.value.name,
            email: identity.value.email,
        });
        identityMsg.value = t('profile.identity.saved');
        router.reload({ only: ['profile'] });
    } catch (e) {
        identityErr.value = e.message;
    } finally { busy.value = false; }
}

async function savePassword() {
    busy.value = true; pwdMsg.value = ''; pwdErr.value = '';
    try {
        await apiOrErrors('PUT', '/user/password', { ...pwd.value });
        pwd.value = { current_password: '', password: '', password_confirmation: '' };
        pwdMsg.value = t('profile.password.saved');
    } catch (e) {
        pwdErr.value = e.message;
    } finally { busy.value = false; }
}

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
        error.value = t('profile.twoFactor.enableError');
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
        error.value = t('profile.twoFactor.invalidCode');
    } finally { busy.value = false; }
}

async function disable() {
    if (!window.confirm(t('profile.twoFactor.disableConfirm'))) return;
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
        <template #title>{{ t('profile.title') }}</template>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Identité (éditable) -->
            <CutCard>
                <div class="p-5 space-y-4">
                    <h3 class="text-base font-medium text-[#002C52] flex items-center gap-2"><User class="size-4" /> {{ t('profile.identity.title') }}</h3>
                    <div class="space-y-2">
                        <Label>{{ t('profile.identity.name') }}</Label>
                        <Input v-model="identity.name" />
                    </div>
                    <div class="space-y-2">
                        <Label>{{ t('profile.identity.email') }}</Label>
                        <Input v-model="identity.email" type="email" />
                    </div>
                    <p v-if="identityErr" class="text-sm text-destructive">{{ identityErr }}</p>
                    <p v-if="identityMsg" class="text-sm text-[#1D9E75]">{{ identityMsg }}</p>
                    <Button :disabled="busy" @click="saveIdentity">{{ t('common.save') }}</Button>
                </div>
            </CutCard>

            <!-- 2FA -->
            <CutCard>
                <div class="p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-base font-medium text-[#002C52]">{{ t('profile.twoFactor.title') }}</h3>
                        <Badge v-if="profile.twoFactorEnabled" variant="default" class="gap-1 bg-[#1D9E75] hover:bg-[#1D9E75]"><ShieldCheck class="size-3" /> {{ t('profile.twoFactor.enabled') }}</Badge>
                        <Badge v-else variant="secondary">{{ t('profile.twoFactor.disabled') }}</Badge>
                    </div>

                    <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

                    <!-- Activée -->
                    <template v-if="profile.twoFactorEnabled">
                        <p class="text-sm text-muted-foreground">{{ t('profile.twoFactor.protected') }}</p>
                        <div v-if="recoveryCodes.length" class="rounded-xl bg-[#F6F2E4] p-4">
                            <div class="text-xs text-muted-foreground mb-2">{{ t('profile.twoFactor.recoveryCodes') }}</div>
                            <div class="grid grid-cols-2 gap-1 font-mono text-xs text-[#002C52]">
                                <span v-for="c in recoveryCodes" :key="c">{{ c }}</span>
                            </div>
                        </div>
                        <Button variant="outline" size="sm" :disabled="busy" @click="disable">{{ t('profile.twoFactor.disableButton') }}</Button>
                    </template>

                    <!-- Flux d'activation -->
                    <template v-else>
                        <template v-if="!qrSvg">
                            <p class="text-sm text-muted-foreground">{{ t('profile.twoFactor.addLayer') }}</p>
                            <Button :disabled="busy" @click="enable">{{ t('profile.twoFactor.enableButton') }}</Button>
                        </template>
                        <template v-else>
                            <p class="text-sm text-muted-foreground">{{ t('profile.twoFactor.scanQr') }}</p>
                            <div class="bg-white rounded-xl p-3 w-fit" v-html="qrSvg"></div>
                            <div class="space-y-2 max-w-48">
                                <Label>{{ t('profile.twoFactor.codeLabel') }}</Label>
                                <Input v-model="confirmCode" inputmode="numeric" placeholder="000000" class="text-center tracking-[0.3em]" />
                            </div>
                            <Button :disabled="busy" @click="confirm">{{ t('common.confirm') }}</Button>
                        </template>
                    </template>
                </div>
            </CutCard>
        </div>

        <!-- Mot de passe -->
        <CutCard class="mt-4">
            <div class="p-5 space-y-4 max-w-xl">
                <h3 class="text-base font-medium text-[#002C52]">{{ t('profile.password.title') }}</h3>
                <div class="space-y-2">
                    <Label>{{ t('profile.password.current') }}</Label>
                    <Input v-model="pwd.current_password" type="password" autocomplete="current-password" />
                </div>
                <div class="space-y-2">
                    <Label>{{ t('profile.password.new') }}</Label>
                    <Input v-model="pwd.password" type="password" autocomplete="new-password" />
                    <p class="text-xs text-muted-foreground">{{ t('profile.password.hint') }}</p>
                </div>
                <div class="space-y-2">
                    <Label>{{ t('profile.password.confirm') }}</Label>
                    <Input v-model="pwd.password_confirmation" type="password" autocomplete="new-password" />
                </div>
                <p v-if="pwdErr" class="text-sm text-destructive">{{ pwdErr }}</p>
                <p v-if="pwdMsg" class="text-sm text-[#1D9E75]">{{ pwdMsg }}</p>
                <Button :disabled="busy" @click="savePassword">{{ t('profile.password.submit') }}</Button>
            </div>
        </CutCard>
    </AdminLayout>
</template>
