<script setup>
import { ref, computed, reactive } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { FlaskConical, Plus, Trash2, Send, AlertTriangle, Clock, Link2 } from 'lucide-vue-next';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    prefill: { type: Object, default: () => ({}) },
});

const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

// --- État du formulaire, éventuellement pré-rempli par le rejeu d'un log ---
const accountId = ref(props.prefill.accountId ?? props.accounts[0]?.id ?? null);
const folderId = ref(props.prefill.folderId ?? null);
const method = ref(HTTP_METHODS.includes(props.prefill.method) ? props.prefill.method : 'GET');
const endpoint = ref(props.prefill.endpoint ?? '');
const bodyText = ref('');

// Paramètres de query en lignes clé/valeur, amorcés depuis le pré-remplissage.
const queryRows = reactive(
    Object.entries(props.prefill.query ?? {}).map(([key, value]) => ({
        key,
        value: typeof value === 'object' ? JSON.stringify(value) : String(value),
    })),
);
if (queryRows.length === 0) {
    queryRows.push({ key: '', value: '' });
}

const currentFolders = computed(() => props.accounts.find((a) => a.id === accountId.value)?.folders ?? []);
const isWrite = computed(() => WRITE_METHODS.includes(method.value));

// Le dossier pré-rempli n'existe peut-être plus pour ce compte : on le réinitialise au besoin.
function onAccountChange() {
    if (folderId.value && ! currentFolders.value.some((f) => f.id === folderId.value)) {
        folderId.value = null;
    }
}

function addRow() {
    queryRows.push({ key: '', value: '' });
}
function removeRow(index) {
    queryRows.splice(index, 1);
    if (queryRows.length === 0) {
        queryRows.push({ key: '', value: '' });
    }
}

// --- Exécution ---
const running = ref(false);
const result = ref(null);
const formError = ref('');
const confirmOpen = ref(false);

function buildQuery() {
    const query = {};
    for (const row of queryRows) {
        const key = (row.key ?? '').trim();
        if (key !== '') {
            query[key] = row.value;
        }
    }
    return query;
}

// GET : on part directement. Écriture : on passe par le dialog de confirmation.
function attemptRun() {
    formError.value = '';
    if (! accountId.value) {
        formError.value = 'Sélectionnez un compte EBP.';
        return;
    }
    if (endpoint.value.trim() === '') {
        formError.value = "Renseignez l'endpoint EBP.";
        return;
    }
    if (isWrite.value) {
        confirmOpen.value = true;
        return;
    }
    run(false);
}

function confirmWrite() {
    confirmOpen.value = false;
    run(true);
}

async function run(confirmed) {
    running.value = true;
    formError.value = '';
    result.value = null;

    try {
        const res = await fetch('/admin/testeur/run', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                ebp_account_id: accountId.value,
                folder_id: folderId.value,
                method: method.value,
                endpoint: endpoint.value.trim(),
                query: buildQuery(),
                body: isWrite.value ? bodyText.value : null,
                confirmed,
            }),
        });

        if (res.status === 422) {
            const data = await res.json();
            formError.value = data.message || Object.values(data.errors ?? {})[0]?.[0] || 'Requête invalide.';
            return;
        }
        if (! res.ok && res.status !== 200) {
            formError.value = `Erreur serveur (HTTP ${res.status}).`;
            return;
        }

        result.value = await res.json();
    } catch (e) {
        formError.value = 'Échec de la requête : '+e.message;
    } finally {
        running.value = false;
    }
}

function statusVariant(s) {
    if (! s) return 'destructive';
    if (s < 300) return 'default';
    if (s < 400) return 'secondary';
    return 'destructive';
}
</script>

<template>
    <AdminLayout current="testeur">
        <template #title>Testeur de requêtes EBP</template>

        <CutCard class="mb-4">
            <div class="p-5 space-y-4">
                <div class="flex items-start gap-3">
                    <div class="size-10 shrink-0 rounded-xl bg-[#F6F2E4] grid place-items-center text-[#002C52]">
                        <FlaskConical class="size-5" />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Émet une vraie requête authentifiée vers l'API EBP via la gateway et affiche la réponse brute.
                        N'importe quel endpoint EBP est accepté (pas de liste blanche), mais l'appel reste borné au host
                        du compte choisi. Chaque appel apparaît dans les <strong class="text-[#002C52]">Logs</strong>.
                    </p>
                </div>

                <!-- Compte / dossier / méthode -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="space-y-2">
                        <Label>Compte EBP</Label>
                        <select v-model="accountId" @change="onAccountChange"
                                class="gw-select w-full h-11 rounded-xl border border-input bg-background px-4 text-sm">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label>Dossier (optionnel)</Label>
                        <select v-model="folderId"
                                class="gw-select w-full h-11 rounded-xl border border-input bg-background px-4 text-sm">
                            <option :value="null">— aucun</option>
                            <option v-for="f in currentFolders" :key="f.id" :value="f.id">{{ f.label }}</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label>Méthode HTTP</Label>
                        <select v-model="method"
                                class="gw-select w-full h-11 rounded-xl border border-input bg-background px-4 text-sm">
                            <option v-for="m in HTTP_METHODS" :key="m" :value="m">{{ m }}</option>
                        </select>
                    </div>
                </div>

                <!-- Endpoint -->
                <div class="space-y-2">
                    <Label>Endpoint EBP</Label>
                    <Input v-model="endpoint" class="font-mono"
                           placeholder="gescom/api/v1/Folders/405032/Items/ItemsExtentedQuery" />
                    <p class="text-xs text-muted-foreground">Chemin relatif, sans le host. Le slash de début est optionnel.</p>
                </div>

                <!-- Paramètres de query -->
                <div class="space-y-2">
                    <Label>Paramètres de query</Label>
                    <div v-for="(row, i) in queryRows" :key="i" class="flex items-center gap-2">
                        <Input v-model="row.key" class="flex-1 font-mono" placeholder="clé (ex. $top)" />
                        <Input v-model="row.value" class="flex-1 font-mono" placeholder="valeur" />
                        <button type="button" aria-label="Retirer" @click="removeRow(i)"
                                class="shrink-0 size-9 rounded-xl grid place-items-center text-muted-foreground hover:bg-black/5 hover:text-destructive transition-colors">
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                    <Button variant="outline" size="sm" @click="addRow"><Plus class="size-4" /> Ajouter un paramètre</Button>
                </div>

                <!-- Corps JSON (écritures seulement) -->
                <div v-if="isWrite" class="space-y-2">
                    <Label>Corps JSON</Label>
                    <textarea v-model="bodyText" rows="8"
                              class="w-full rounded-xl border border-input bg-background px-4 py-3 text-sm font-mono"
                              placeholder='{ "Caption": "Mon article" }'></textarea>
                    <p class="text-xs text-muted-foreground">JSON valide attendu pour {{ method }}.</p>
                </div>

                <p v-if="formError" class="text-sm text-destructive flex items-center gap-2">
                    <AlertTriangle class="size-4 shrink-0" /> {{ formError }}
                </p>

                <div class="flex items-center gap-3">
                    <Button :disabled="running" @click="attemptRun">
                        <Send class="size-4" /> {{ running ? 'Envoi…' : 'Envoyer la requête' }}
                    </Button>
                    <Badge v-if="isWrite" variant="destructive">Écriture réelle</Badge>
                </div>
            </div>
        </CutCard>

        <!-- Résultat -->
        <CutCard v-if="result">
            <div class="p-5 space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <h3 class="text-base font-medium text-[#002C52]">Réponse</h3>
                    <div class="flex items-center gap-3">
                        <Badge :variant="statusVariant(result.status)">{{ result.status || 'réseau' }}</Badge>
                        <span class="text-xs text-muted-foreground inline-flex items-center gap-1">
                            <Clock class="size-3.5" /> {{ result.durationMs }} ms
                        </span>
                    </div>
                </div>

                <div class="rounded-xl bg-[#F6F2E4] text-[#002C52] text-xs p-4 flex items-start gap-2 break-all">
                    <Link2 class="size-4 shrink-0 mt-0.5" />
                    <span class="font-mono">{{ result.method }} {{ result.url }}</span>
                </div>

                <p v-if="result.error" class="text-sm text-destructive flex items-center gap-2">
                    <AlertTriangle class="size-4 shrink-0" /> {{ result.error }}
                </p>

                <div v-if="result.body !== null">
                    <div class="text-xs text-muted-foreground mb-2">Corps de réponse EBP</div>
                    <pre class="rounded-xl bg-[#002C52] text-[#DCE7F5] text-xs p-4 overflow-auto max-h-[32rem]">{{ result.body }}</pre>
                </div>
                <p v-else-if="! result.error" class="text-sm text-muted-foreground">Corps vide.</p>
            </div>
        </CutCard>

        <!-- Confirmation d'écriture -->
        <div v-if="confirmOpen" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="confirmOpen = false">
            <div class="w-full max-w-md bg-white rounded-[1.5rem] border border-[#E4DFCE] p-6 space-y-4">
                <div class="flex items-start gap-3">
                    <div class="size-10 shrink-0 rounded-xl bg-destructive/10 grid place-items-center text-destructive">
                        <AlertTriangle class="size-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-medium text-[#002C52]">Modifier des données réelles dans EBP ?</h3>
                        <p class="text-sm text-muted-foreground mt-1">
                            Cette requête <strong class="text-[#002C52] font-mono">{{ method }}</strong> écrit dans la
                            gestion commerciale réelle du compte sélectionné. L'opération peut être irréversible.
                        </p>
                    </div>
                </div>
                <div class="rounded-xl bg-[#F6F2E4] text-[#002C52] text-xs p-3 font-mono break-all">
                    {{ method }} /{{ endpoint.replace(/^\//, '') }}
                </div>
                <div class="flex justify-end gap-3">
                    <Button variant="outline" @click="confirmOpen = false">Annuler</Button>
                    <Button variant="destructive" :disabled="running" @click="confirmWrite">Confirmer l'écriture</Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
