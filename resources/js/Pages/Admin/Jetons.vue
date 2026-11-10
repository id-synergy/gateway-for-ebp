<script setup>
import { ref, computed, nextTick } from 'vue';
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
import { Plus, Copy, Check, RefreshCw, QrCode, X } from 'lucide-vue-next';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    endpoint: { type: String, default: '' },
});

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
    if (confirm('Révoquer ce jeton ? Les apps qui l\'utilisent perdront l\'accès.')) {
        router.delete(`/admin/jetons/${id}`);
    }
}

// --- Appairage d'une application (QR à code à usage unique) ---
const pairing = ref({ open: false, step: 'password', token: null, password: '', error: '', busy: false });
const pairingCanvas = ref(null);
const countdown = ref(0);
let countdownTimer = null;

function openPairing(token) {
    pairing.value = { open: true, step: 'password', token, password: '', error: '', busy: false };
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
            pairing.value.error = d.message || 'Mot de passe incorrect.';
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
        pairing.value.error = "Échec de la génération du code d'appairage.";
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
        window.prompt('Copiez le jeton manuellement :', value);
    }
}

// Régénération : double confirmation, car l'ancienne clé cesse immédiatement de fonctionner.
function regenerate(token) {
    const first = window.confirm(
        `Régénérer la clé du jeton « ${token.name} » ?\n\n`
        + `ATTENTION : l'application qui utilise la clé actuelle ne pourra plus accéder à EBP `
        + `tant qu'elle n'aura pas reçu la nouvelle clé.`,
    );
    if (!first) return;

    const second = window.confirm(
        `Êtes-vous sûr ? Cette action est IRRÉVERSIBLE.\n\n`
        + `L'ancienne clé sera définitivement invalidée. La nouvelle clé ne sera affichée qu'une seule fois.`,
    );
    if (!second) return;

    router.post(`/admin/jetons/${token.id}/regenerate`, {}, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout current="jetons">
        <template #title>Jetons d'apps</template>
        <template #actions>
            <Button size="lg" @click="showForm = !showForm"><Plus class="size-5" /> Créer un jeton</Button>
        </template>

        <!-- Jeton fraîchement créé (montré une seule fois) -->
        <CutCard v-if="page.props.flash?.newToken" class="mb-4 border-2 border-primary">
            <div class="p-5">
                <p class="text-sm font-medium text-[#002C52] mb-2">Nouveau jeton (copiez-le maintenant, il ne sera plus affiché) :</p>
                <div class="flex items-center gap-2">
                    <code class="flex-1 text-xs bg-muted rounded-lg px-3 py-2.5 break-all">{{ page.props.flash.newToken }}</code>
                    <Button variant="outline" size="icon" :aria-label="copied ? 'Copié' : 'Copier'" @click="copyToken(page.props.flash.newToken)">
                        <Check v-if="copied" class="size-4 text-[#1D9E75]" />
                        <Copy v-else class="size-4" />
                    </Button>
                </div>
                <p v-if="copied" class="text-xs text-[#1D9E75] mt-1.5">Jeton copié dans le presse-papiers.</p>

                <p class="text-xs text-muted-foreground mt-3">
                    Pour connecter une application par QR code, utilisez le bouton
                    <strong class="text-[#002C52]">« Connecter »</strong> sur la ligne du jeton : il génère
                    un code d'appairage temporaire, sans jamais exposer le jeton dans le QR.
                </p>
            </div>
        </CutCard>

        <!-- Formulaire de création -->
        <CutCard v-if="showForm" class="mb-4">
            <div class="p-5 space-y-4">
                <h3 class="text-base font-medium text-[#002C52]">Nouveau jeton d'application</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <Label>Nom de l'application</Label>
                        <Input v-model="form.name" placeholder="Logiciel tickets" />
                        <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>Compte EBP</Label>
                        <select v-model="form.ebp_account_id" class="gw-select w-full h-11 rounded-xl border border-input bg-background px-4 text-sm">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label>Dossier par défaut (optionnel)</Label>
                        <select v-model="form.default_folder_id" class="gw-select w-full h-11 rounded-xl border border-input bg-background px-4 text-sm">
                            <option :value="null">— à préciser dans chaque requête</option>
                            <option v-for="f in currentFolders" :key="f.id" :value="f.id">{{ f.label }}</option>
                        </select>
                        <p class="text-xs text-muted-foreground">Sinon l'app devra passer folderId à chaque appel.</p>
                    </div>
                </div>
                <Button :disabled="form.processing" @click="submit">Créer le jeton</Button>
            </div>
        </CutCard>

        <!-- Aucun jeton -->
        <CutCard v-if="!tokens.length">
            <EmptyState :icon="KeyRound" title="Aucun jeton d'application"
                text="Créez un jeton pour qu'une application (impression de tickets, pilotage…) puisse accéder à EBP via la gateway.">
                <Button size="lg" @click="showForm = true"><Plus class="size-5" /> Créer un jeton</Button>
            </EmptyState>
        </CutCard>

        <template v-else>
            <!-- Jetons actifs. Titre seulement s'il y a aussi des jetons révoqués. -->
            <CutCard :class="revokedTokens.length ? 'mb-4' : ''">
                <div class="p-5">
                    <h3 v-if="revokedTokens.length" class="text-base font-medium text-[#002C52] mb-3">Jetons actifs</h3>
                    <p v-if="!activeTokens.length" class="text-sm text-muted-foreground">Aucun jeton actif.</p>
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Application</TableHead>
                                <TableHead>Compte EBP</TableHead>
                                <TableHead>Dossier par défaut</TableHead>
                                <TableHead>Dernière utilisation</TableHead>
                                <TableHead>État</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="t in activeTokens" :key="t.id">
                                <TableCell class="font-medium">{{ t.name }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ t.account }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ t.folder }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ t.lastUsed }}</TableCell>
                                <TableCell><Badge variant="default">Actif</Badge></TableCell>
                                <TableCell class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <Button variant="ghost" size="sm" @click="openPairing(t)">
                                            <QrCode class="size-3.5" /> Connecter
                                        </Button>
                                        <Button variant="ghost" size="sm" @click="regenerate(t)">
                                            <RefreshCw class="size-3.5" /> Régénérer la clé
                                        </Button>
                                        <Button variant="ghost" size="sm" class="text-destructive" @click="revoke(t.id)">Révoquer</Button>
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
                    <h3 class="text-base font-medium text-[#002C52] mb-3">Jetons révoqués</h3>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Application</TableHead>
                                <TableHead>Compte EBP</TableHead>
                                <TableHead>Dossier par défaut</TableHead>
                                <TableHead>Dernière utilisation</TableHead>
                                <TableHead>État</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="t in revokedTokens" :key="t.id">
                                <TableCell class="font-medium text-muted-foreground">{{ t.name }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ t.account }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ t.folder }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ t.lastUsed }}</TableCell>
                                <TableCell><Badge variant="secondary">Révoqué</Badge></TableCell>
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
                            <QrCode class="size-4" /> Connecter « {{ pairing.token?.name }} »
                        </h3>
                        <Button variant="ghost" size="icon" @click="closePairing" aria-label="Fermer"><X class="size-4" /></Button>
                    </div>

                    <!-- Étape 1 : mot de passe -->
                    <template v-if="pairing.step === 'password'">
                        <p class="text-sm text-muted-foreground mb-4">
                            Pour afficher le QR code d'appairage, confirmez votre mot de passe administrateur.
                            Un nouveau jeton sera généré et l'ancien cessera de fonctionner.
                        </p>
                        <form @submit.prevent="startPairing" class="space-y-3">
                            <div class="space-y-2">
                                <Label>Mot de passe administrateur</Label>
                                <Input v-model="pairing.password" type="password" autocomplete="current-password" />
                                <p v-if="pairing.error" class="text-xs text-destructive">{{ pairing.error }}</p>
                            </div>
                            <Button type="submit" :disabled="pairing.busy || !pairing.password" class="w-full">Afficher le QR code</Button>
                        </form>
                    </template>

                    <!-- Étape 2 : QR d'appairage -->
                    <template v-else>
                        <p class="text-sm text-muted-foreground mb-3">
                            Scannez ce code depuis l'application pour la connecter. Il contient un
                            <strong class="text-[#002C52]">code d'appairage temporaire</strong>, pas le jeton :
                            l'application récupère le jeton par la gateway, puis le code est invalidé.
                        </p>
                        <div class="flex flex-col items-center gap-3">
                            <div class="rounded-xl bg-white border border-[#E4DFCE] p-3">
                                <canvas ref="pairingCanvas" class="block"></canvas>
                            </div>
                            <div class="text-sm" :class="countdown > 0 ? 'text-muted-foreground' : 'text-destructive'">
                                <template v-if="countdown > 0">Expire dans <strong class="text-[#002C52]">{{ countdownLabel }}</strong></template>
                                <template v-else>Code expiré — fermez et recommencez.</template>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground mt-4">
                            Usage unique : une fois scanné, ce code ne fonctionne plus. Un QR photographié
                            après coup est inutile.
                        </p>
                    </template>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
