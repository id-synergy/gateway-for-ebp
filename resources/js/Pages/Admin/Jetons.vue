<script setup>
import { ref, computed, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import { router, useForm, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import { KeyRound } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { Plus, Copy, Check, RefreshCw, QrCode, X, ShieldCheck, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    endpoint: { type: String, default: '' },
    routeCatalog: { type: Array, default: () => [] },
});

const { t } = useI18n();

const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

// Tous les patterns connus, à plat, pour la datalist d'autocomplétion.
const allPatterns = computed(() =>
    props.routeCatalog.flatMap((m) => m.routes.map((r) => r.pattern)),
);

const page = usePage();
const showForm = ref(false);
const copied = ref(false);

const form = useForm({ name: '', ebp_account_id: props.accounts[0]?.id ?? null, default_folder_id: null });

const currentFolders = computed(() => props.accounts.find((a) => a.id === form.ebp_account_id)?.folders ?? []);

const activeTokens = computed(() => props.tokens.filter((t) => t.active));
const revokedTokens = computed(() => props.tokens.filter((t) => !t.active));

function submit() {
    form.post('/admin/jetons', { onSuccess: () => { form.reset(); showForm.value = false; } });
}
function revoke(id) {
    if (confirm(t('jetons.confirmRevoke'))) {
        router.delete(`/admin/jetons/${id}`);
    }
}

// --- Appairage d'une application (QR à code à usage unique) ---
const pairing = ref({ open: false, step: 'password', token: null, password: '', error: '', busy: false, needsRegen: false });
const pairingCanvas = ref(null);
const countdown = ref(0);
let countdownTimer = null;

function openPairing(token) {
    pairing.value = { open: true, step: 'password', token, password: '', error: '', busy: false, needsRegen: false };
}
function regenerateFromPairing() {
    // Amorce un ancien jeton : régénère (déconnecte les appareils actuels), puis on pourra appairer.
    const t = pairing.value.token;
    closePairing();
    regenerate(t);
}
function closePairing() {
    pairing.value.open = false;
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
}

async function startPairing() {
    pairing.value.busy = true; pairing.value.error = '';
    try {
        const res = await fetch(`/admin/jetons/${pairing.value.token.id}/pairing`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ password: pairing.value.password }),
        });
        if (res.status === 422) {
            const d = await res.json();
            pairing.value.error = d.message || t('jetons.pairing.wrongPassword');
            return;
        }
        // Jeton créé avant le stockage chiffré : il faut le régénérer une fois.
        if (res.status === 409) {
            const d = await res.json();
            pairing.value.error = d.message;
            pairing.value.needsRegen = true;
            return;
        }
        if (!res.ok) throw new Error(res.status);
        const data = await res.json();

        // Le QR ne contient PAS le jeton, seulement le code d'appairage à usage unique.
        pairing.value.step = 'qr';
        pairing.value.password = '';
        nextTick(() => {
            if (pairingCanvas.value) {
                const payload = JSON.stringify({ endpoint: data.endpoint, pairingCode: data.code });
                QRCode.toCanvas(pairingCanvas.value, payload, { width: 240, margin: 1 }, () => {});
            }
        });

        // Compte à rebours (le code expire).
        countdown.value = data.expiresInSeconds;
        if (countdownTimer) clearInterval(countdownTimer);
        countdownTimer = setInterval(() => {
            countdown.value -= 1;
            if (countdown.value <= 0) { clearInterval(countdownTimer); countdownTimer = null; }
        }, 1000);
    } catch {
        pairing.value.error = t('jetons.pairing.generationFailed');
    } finally {
        pairing.value.busy = false;
    }
}

const countdownLabel = computed(() => {
    const m = Math.floor(Math.max(0, countdown.value) / 60);
    const s = Math.max(0, countdown.value) % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
});

// Copie du jeton : API Clipboard moderne, avec repli (execCommand) si indisponible (http, focus perdu…).
async function copyToken(value) {
    let ok = false;
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
            ok = true;
        }
    } catch (e) {
        ok = false;
    }
    if (!ok) {
        try {
            const ta = document.createElement('textarea');
            ta.value = value;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            ok = document.execCommand('copy');
            document.body.removeChild(ta);
        } catch (e) {
            ok = false;
        }
    }
    if (ok) {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1600);
    } else {
        window.prompt(t('jetons.copyManually'), value);
    }
}

// Régénération : double confirmation, car l'ancienne clé cesse immédiatement de fonctionner.
function regenerate(token) {
    const first = window.confirm(t('jetons.confirmRegenerate1', { name: token.name }));
    if (!first) return;

    const second = window.confirm(t('jetons.confirmRegenerate2'));
    if (!second) return;

    router.post(`/admin/jetons/${token.id}/regenerate`, {}, { preserveScroll: true });
}

// --- Règles d'accès (contexte par application) ---
const rulesModal = ref({ open: false, token: null });
const rulesForm = useForm({ rules: [] });

function openRules(token) {
    // Copie profonde des règles existantes (verbes + pattern), ou liste vide (= ouvert).
    rulesForm.clearErrors();
    rulesForm.rules = (token.rules ?? []).map((r) => ({
        methods: [...r.methods],
        pattern: r.pattern,
    }));
    rulesModal.value = { open: true, token };
}
function closeRules() {
    rulesModal.value.open = false;
}
function addRule() {
    rulesForm.rules.push({ methods: ['GET'], pattern: '' });
}
function removeRule(index) {
    rulesForm.rules.splice(index, 1);
}
function toggleMethod(rule, method) {
    const i = rule.methods.indexOf(method);
    if (i === -1) rule.methods.push(method);
    else rule.methods.splice(i, 1);
}
function saveRules() {
    rulesForm.put(`/admin/jetons/${rulesModal.value.token.id}/regles`, {
        preserveScroll: true,
        onSuccess: () => { rulesModal.value.open = false; },
    });
}
// Erreur de validation ciblant une règle précise (ex. "rules.0.pattern").
function ruleError(index, field) {
    return rulesForm.errors[`rules.${index}.${field}`];
}
</script>

<template>
    <AdminLayout current="jetons">
        <template #title>{{ t('nav.jetons') }}</template>
        <template #actions>
            <Button size="lg" @click="showForm = !showForm"><Plus class="size-5" /> {{ t('jetons.create') }}</Button>
        </template>

        <!-- Jeton fraîchement créé (montré une seule fois) -->
        <CutCard v-if="page.props.flash?.newToken" class="mb-4 border-2 border-primary">
            <div class="p-5">
                <p class="text-sm font-medium text-[#002C52] mb-2">{{ t('jetons.newToken.label') }}</p>
                <div class="flex items-center gap-2">
                    <code class="flex-1 text-xs bg-muted rounded-lg px-3 py-2.5 break-all">{{ page.props.flash.newToken }}</code>
                    <Button variant="outline" size="icon" :aria-label="copied ? t('common.copied') : t('common.copy')" @click="copyToken(page.props.flash.newToken)">
                        <Check v-if="copied" class="size-4 text-[#1D9E75]" />
                        <Copy v-else class="size-4" />
                    </Button>
                </div>
                <p v-if="copied" class="text-xs text-[#1D9E75] mt-1.5">{{ t('jetons.newToken.copied') }}</p>

                <p class="text-xs text-muted-foreground mt-3">
                    {{ t('jetons.newToken.hintBefore') }}
                    <strong class="text-[#002C52]">{{ t('jetons.connect') }}</strong> {{ t('jetons.newToken.hintAfter') }}
                </p>
            </div>
        </CutCard>

        <!-- Formulaire de création -->
        <CutCard v-if="showForm" class="mb-4">
            <div class="p-5 space-y-4">
                <h3 class="text-base font-medium text-[#002C52]">{{ t('jetons.form.title') }}</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <Label>{{ t('jetons.form.appName') }}</Label>
                        <Input v-model="form.name" :placeholder="t('jetons.form.appNamePlaceholder')" />
                        <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>{{ t('jetons.form.ebpAccount') }}</Label>
                        <select v-model="form.ebp_account_id" class="gw-select w-full h-11 rounded-xl border border-input bg-background px-4 text-sm">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label>{{ t('jetons.form.defaultFolder') }}</Label>
                        <select v-model="form.default_folder_id" class="gw-select w-full h-11 rounded-xl border border-input bg-background px-4 text-sm">
                            <option :value="null">{{ t('jetons.form.folderPerRequest') }}</option>
                            <option v-for="f in currentFolders" :key="f.id" :value="f.id">{{ f.label }}</option>
                        </select>
                        <p class="text-xs text-muted-foreground">{{ t('jetons.form.folderHint') }}</p>
                    </div>
                </div>
                <Button :disabled="form.processing" @click="submit">{{ t('jetons.form.submit') }}</Button>
            </div>
        </CutCard>

        <!-- Aucun jeton -->
        <CutCard v-if="!tokens.length">
            <EmptyState :icon="KeyRound" :title="t('jetons.empty.title')"
                :text="t('jetons.empty.text')">
                <Button size="lg" @click="showForm = true"><Plus class="size-5" /> {{ t('jetons.create') }}</Button>
            </EmptyState>
        </CutCard>

        <template v-else>
            <!-- Jetons actifs. Titre seulement s'il y a aussi des jetons révoqués. -->
            <CutCard :class="revokedTokens.length ? 'mb-4' : ''">
                <div class="p-5">
                    <h3 v-if="revokedTokens.length" class="text-base font-medium text-[#002C52] mb-3">{{ t('jetons.activeTokens') }}</h3>
                    <p v-if="!activeTokens.length" class="text-sm text-muted-foreground">{{ t('jetons.noActive') }}</p>
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('jetons.col.application') }}</TableHead>
                                <TableHead>{{ t('jetons.form.ebpAccount') }}</TableHead>
                                <TableHead>{{ t('jetons.col.defaultFolder') }}</TableHead>
                                <TableHead>{{ t('jetons.col.ebpAccess') }}</TableHead>
                                <TableHead>{{ t('jetons.col.lastUse') }}</TableHead>
                                <TableHead>{{ t('common.status') }}</TableHead>
                                <TableHead class="text-right">{{ t('common.actions') }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="tok in activeTokens" :key="tok.id">
                                <TableCell class="font-medium">{{ tok.name }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ tok.account }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ tok.folder }}</TableCell>
                                <TableCell>
                                    <Badge v-if="(tok.rules?.length ?? 0) === 0" variant="secondary" :title="t('jetons.accessOpenTitle')">{{ t('jetons.accessOpen') }}</Badge>
                                    <Badge v-else variant="outline" class="border-amber-400 text-amber-700" :title="t('jetons.accessRulesTitle', { count: tok.rules.length })">
                                        {{ t('jetons.accessRestricted', { count: tok.rules.length }) }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="text-muted-foreground">{{ tok.lastUsed }}</TableCell>
                                <TableCell><Badge variant="default">{{ t('jetons.stateActive') }}</Badge></TableCell>
                                <TableCell class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <Button variant="ghost" size="sm" @click="openRules(tok)">
                                            <ShieldCheck class="size-3.5" /> {{ t('jetons.access') }}
                                        </Button>
                                        <Button variant="ghost" size="sm" @click="openPairing(tok)">
                                            <QrCode class="size-3.5" /> {{ t('jetons.connect') }}
                                        </Button>
                                        <Button variant="ghost" size="sm" @click="regenerate(tok)">
                                            <RefreshCw class="size-3.5" /> {{ t('jetons.regenerateKey') }}
                                        </Button>
                                        <Button variant="ghost" size="sm" class="text-destructive" @click="revoke(tok.id)">{{ t('jetons.revoke') }}</Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </CutCard>

            <!-- Jetons révoqués : affichés seulement s'il y en a, sans action (révocation définitive). -->
            <CutCard v-if="revokedTokens.length">
                <div class="p-5">
                    <h3 class="text-base font-medium text-[#002C52] mb-3">{{ t('jetons.revokedTokens') }}</h3>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('jetons.col.application') }}</TableHead>
                                <TableHead>{{ t('jetons.form.ebpAccount') }}</TableHead>
                                <TableHead>{{ t('jetons.col.defaultFolder') }}</TableHead>
                                <TableHead>{{ t('jetons.col.lastUse') }}</TableHead>
                                <TableHead>{{ t('common.status') }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="tok in revokedTokens" :key="tok.id">
                                <TableCell class="font-medium text-muted-foreground">{{ tok.name }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ tok.account }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ tok.folder }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ tok.lastUsed }}</TableCell>
                                <TableCell><Badge variant="secondary">{{ t('jetons.stateRevoked') }}</Badge></TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </CutCard>
        </template>

        <!-- Modale d'appairage -->
        <div v-if="pairing.open" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="closePairing">
            <div class="w-full max-w-md rounded-[1.5rem] bg-background border border-[#E4DFCE] shadow-xl">
                <div class="p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-medium text-[#002C52] flex items-center gap-2">
                            <QrCode class="size-4" /> {{ t('jetons.pairing.title', { name: pairing.token?.name }) }}
                        </h3>
                        <Button variant="ghost" size="icon" @click="closePairing" :aria-label="t('common.close')"><X class="size-4" /></Button>
                    </div>

                    <!-- Étape 1 : mot de passe -->
                    <template v-if="pairing.step === 'password'">
                        <p class="text-sm text-muted-foreground mb-4">
                            {{ t('jetons.pairing.passwordIntro') }}
                        </p>
                        <form @submit.prevent="startPairing" class="space-y-3">
                            <div class="space-y-2">
                                <Label>{{ t('jetons.pairing.adminPassword') }}</Label>
                                <Input v-model="pairing.password" type="password" autocomplete="current-password" />
                                <p v-if="pairing.error" class="text-xs text-destructive">{{ pairing.error }}</p>
                            </div>
                            <Button v-if="!pairing.needsRegen" type="submit" :disabled="pairing.busy || !pairing.password" class="w-full">{{ t('jetons.pairing.showQr') }}</Button>
                            <Button v-else type="button" variant="outline" class="w-full" @click="regenerateFromPairing">{{ t('jetons.pairing.regenerateNow') }}</Button>
                        </form>
                    </template>

                    <!-- Étape 2 : QR d'appairage -->
                    <template v-else>
                        <p class="text-sm text-muted-foreground mb-3">
                            {{ t('jetons.pairing.qrIntro') }}
                        </p>
                        <div class="flex flex-col items-center gap-3">
                            <div class="rounded-xl bg-white border border-[#E4DFCE] p-3">
                                <canvas ref="pairingCanvas" class="block"></canvas>
                            </div>
                            <div class="text-sm" :class="countdown > 0 ? 'text-muted-foreground' : 'text-destructive'">
                                <template v-if="countdown > 0">{{ t('jetons.pairing.expiresIn') }} <strong class="text-[#002C52]">{{ countdownLabel }}</strong></template>
                                <template v-else>{{ t('jetons.pairing.expired') }}</template>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground mt-4">
                            {{ t('jetons.pairing.singleUse') }}
                        </p>
                    </template>
                </div>
            </div>
        </div>

        <!-- Modale d'édition des règles d'accès (contexte par application) -->
        <div v-if="rulesModal.open" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="closeRules">
            <div class="w-full max-w-2xl rounded-[1.5rem] bg-background border border-[#E4DFCE] shadow-xl max-h-[90vh] flex flex-col">
                <div class="p-5 border-b border-[#E4DFCE] flex items-center justify-between">
                    <h3 class="text-base font-medium text-[#002C52] flex items-center gap-2">
                        <ShieldCheck class="size-4" /> {{ t('jetons.rules.title', { name: rulesModal.token?.name }) }}
                    </h3>
                    <Button variant="ghost" size="icon" @click="closeRules" :aria-label="t('common.close')"><X class="size-4" /></Button>
                </div>

                <div class="p-5 space-y-4 overflow-y-auto">
                    <!-- Explication de la sémantique ouvert / restreint -->
                    <div class="rounded-xl bg-muted/60 px-4 py-3 text-sm text-muted-foreground">
                        <template v-if="rulesForm.rules.length === 0">
                            <strong class="text-[#002C52]">{{ t('jetons.rules.openTitle') }}</strong>
                            {{ t('jetons.rules.openText') }}
                        </template>
                        <template v-else>
                            <strong class="text-[#002C52]">{{ t('jetons.rules.restrictedTitle', { count: rulesForm.rules.length }) }}</strong>
                            {{ t('jetons.rules.restrictedText') }}
                        </template>
                    </div>

                    <!-- Liste des règles -->
                    <div v-for="(rule, i) in rulesForm.rules" :key="i" class="rounded-xl border border-input p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-muted-foreground">{{ t('jetons.rules.ruleN', { n: i + 1 }) }}</span>
                            <Button variant="ghost" size="icon" class="text-destructive size-7" @click="removeRule(i)" :aria-label="t('jetons.rules.removeRule')">
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <!-- Verbes HTTP -->
                        <div class="space-y-1.5">
                            <Label class="text-xs">{{ t('jetons.rules.allowedVerbs') }}</Label>
                            <div class="flex flex-wrap gap-1.5">
                                <button v-for="m in HTTP_METHODS" :key="m" type="button"
                                    class="px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors"
                                    :class="rule.methods.includes(m)
                                        ? 'bg-[#002C52] text-white border-[#002C52]'
                                        : 'bg-background text-muted-foreground border-input hover:border-[#002C52]'"
                                    @click="toggleMethod(rule, m)">
                                    {{ m }}
                                </button>
                            </div>
                            <p v-if="ruleError(i, 'methods')" class="text-xs text-destructive">{{ ruleError(i, 'methods') }}</p>
                        </div>

                        <!-- Pattern de route -->
                        <div class="space-y-1.5">
                            <Label class="text-xs">{{ t('jetons.rules.routePattern') }}</Label>
                            <Input v-model="rule.pattern" list="route-patterns"
                                placeholder="gescom/api/v1/Folders/*/Items/*" class="font-mono text-xs" />
                            <p v-if="ruleError(i, 'pattern')" class="text-xs text-destructive">{{ ruleError(i, 'pattern') }}</p>
                        </div>
                    </div>

                    <!-- Datalist partagée : suggestions issues de la doc API -->
                    <datalist id="route-patterns">
                        <option v-for="p in allPatterns" :key="p" :value="p" />
                    </datalist>

                    <Button variant="outline" size="sm" @click="addRule">
                        <Plus class="size-3.5" /> {{ t('jetons.rules.addRule') }}
                    </Button>

                    <!-- Aide sur les jokers -->
                    <p class="text-xs text-muted-foreground">
                        {{ t('jetons.rules.wildcardsBefore') }} <code class="bg-muted rounded px-1">*</code> {{ t('jetons.rules.wildcardsOne') }}
                        <code class="bg-muted rounded px-1">**</code> {{ t('jetons.rules.wildcardsRest') }}
                        {{ t('jetons.rules.wildcardsFolder') }}
                    </p>
                </div>

                <div class="p-5 border-t border-[#E4DFCE] flex items-center justify-end gap-2">
                    <Button variant="ghost" @click="closeRules">{{ t('common.cancel') }}</Button>
                    <Button :disabled="rulesForm.processing" @click="saveRules">{{ t('jetons.rules.save') }}</Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
