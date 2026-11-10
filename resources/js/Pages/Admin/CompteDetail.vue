<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Plug, FolderOpen, KeyRound, Activity, ArrowLeft, AlertTriangle, Settings, Trash2, RefreshCw,
    Plug2, PlugZap, Ban, FolderSync, ShieldAlert, CheckCircle2, Clock,
} from 'lucide-vue-next';

const props = defineProps({
    account: { type: Object, required: true },
    folders: { type: Array, default: () => [] },
    tokens: { type: Array, default: () => [] },
    callCount: { type: Number, default: 0 },
    events: { type: Object, default: () => ({ items: [], hasMore: false, nextCursor: null }) },
});

// --- Journal d'activité : timeline avec scroll infini + actualisation ---
const log = ref([...props.events.items]);
const cursor = ref(props.events.nextCursor);
const hasMore = ref(props.events.hasMore);
const loadingMore = ref(false);
const refreshing = ref(false);
const sentinel = ref(null);
let observer = null;

// Icône et couleurs par type/niveau d'événement.
const eventIcon = {
    connected: Plug2, reconnected: PlugZap, token_refreshed: RefreshCw, refresh_failed: ShieldAlert,
    denied: Ban, folders_synced: FolderSync,
    app_token_created: KeyRound, app_token_revoked: KeyRound, app_token_regenerated: KeyRound,
};
const levelStyle = {
    success: { dot: 'bg-[#1D9E75]', ring: 'bg-[#1D9E75]/12 text-[#0F6E56]' },
    info: { dot: 'bg-[#0046FE]', ring: 'bg-[#0046FE]/12 text-[#0046FE]' },
    warning: { dot: 'bg-[#FAC775]', ring: 'bg-[#FAC775]/30 text-[#633806]' },
    error: { dot: 'bg-[#E24B4A]', ring: 'bg-[#E24B4A]/12 text-[#E24B4A]' },
};
function iconFor(type) { return eventIcon[type] || Activity; }
function styleFor(level) { return levelStyle[level] || levelStyle.info; }

async function fetchEvents(before = null) {
    const url = `/admin/comptes/${props.account.id}/events${before ? `?before=${before}` : ''}`;
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!res.ok) throw new Error('fetch events');
    return res.json();
}

async function loadMore() {
    if (loadingMore.value || !hasMore.value) return;
    loadingMore.value = true;
    try {
        const page = await fetchEvents(cursor.value);
        log.value.push(...page.items);
        cursor.value = page.nextCursor;
        hasMore.value = page.hasMore;
    } catch { /* silencieux : le journal est secondaire */ }
    finally { loadingMore.value = false; }
}

async function refreshLog() {
    if (refreshing.value) return;
    refreshing.value = true;
    try {
        const page = await fetchEvents(null);
        log.value = page.items;
        cursor.value = page.nextCursor;
        hasMore.value = page.hasMore;
    } catch { /* silencieux */ }
    finally { refreshing.value = false; }
}

onMounted(() => {
    // Scroll infini : charge la page suivante dès que la sentinelle entre dans le viewport.
    observer = new IntersectionObserver((entries) => {
        if (entries[0]?.isIntersecting) loadMore();
    }, { rootMargin: '200px' });
    if (sentinel.value) observer.observe(sentinel.value);
});
onBeforeUnmount(() => observer?.disconnect());

const stateBadge = {
    healthy: { text: 'Sain', variant: 'default' },
    expiring: { text: 'Expire bientôt', variant: 'secondary' },
    broken: { text: 'Rompu', variant: 'destructive' },
};

const showEdit = ref(false);
const form = useForm({
    label: props.account.label,
    active_subscription_key: props.account.key === 'secondaire' ? 'secondary' : 'primary',
    environment: props.account.environment || 'production',
    cache_enabled: props.account.cacheEnabled ?? true,
    cache_ttl: props.account.cacheTtl ?? null,
    client_id: '', client_secret: '',
    subscription_key_primary: '', subscription_key_secondary: '',
});

function save() {
    form.patch(`/admin/comptes/${props.account.id}`, { onSuccess: () => { showEdit.value = false; } });
}
function remove() {
    if (window.confirm(`Supprimer le compte « ${props.account.label} » ? Les jetons rattachés perdront leur accès.`)) {
        router.delete(`/admin/comptes/${props.account.id}`);
    }
}
function connect() {
    window.location.href = `/oauth/ebp/connect?account=${props.account.id}`;
}
function syncFolders() {
    router.post(`/admin/comptes/${props.account.id}/sync-folders`, {}, { preserveScroll: true });
}
function clearCache() {
    router.post(`/admin/comptes/${props.account.id}/clear-cache`, {}, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout current="comptes">
        <template #title>
            <Link href="/admin/comptes" class="inline-flex items-center gap-2 text-muted-foreground hover:text-[#002C52]">
                <ArrowLeft class="size-4" /> Comptes EBP
            </Link>
        </template>

        <!-- En-tête du compte -->
        <CutCard class="mb-4">
            <div class="p-5 flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <div class="size-12 rounded-full bg-primary text-primary-foreground grid place-items-center"><Plug class="size-6" /></div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-lg font-medium text-[#002C52]">{{ account.label }}</span>
                            <Badge :variant="(stateBadge[account.tokenState] || stateBadge.broken).variant">
                                {{ (stateBadge[account.tokenState] || stateBadge.broken).text }}
                            </Badge>
                        </div>
                        <div class="text-xs text-muted-foreground flex items-center gap-1.5">
                            Clé d'abonnement : {{ account.key }}
                            <span class="inline-block px-1.5 py-0.5 rounded-md text-[11px] font-medium"
                                  :class="{
                                      'bg-[#1D9E75]/15 text-[#0F6E56]': account.environment === 'production',
                                      'bg-[#FAC775] text-[#633806]': account.environment === 'preproduction',
                                      'bg-primary/15 text-primary': account.environment === 'demo',
                                  }">
                                {{ account.environment === 'preproduction' ? 'Préproduction' : account.environment === 'demo' ? 'Démo' : 'Production' }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <Button size="sm" @click="connect">Reconnecter</Button>
                    <Button variant="outline" size="sm" @click="showEdit = !showEdit"><Settings class="size-3.5" /> Modifier</Button>
                    <Button variant="outline" size="sm" class="text-destructive" @click="remove"><Trash2 class="size-3.5" /> Supprimer</Button>
                </div>
            </div>
        </CutCard>

        <!-- Formulaire d'édition -->
        <CutCard v-if="showEdit" class="mb-4">
            <div class="p-5 space-y-4">
                <h3 class="text-base font-medium text-[#002C52]">Modifier le compte</h3>
                <p class="text-xs text-muted-foreground -mt-2">Laissez un secret vide pour le conserver inchangé.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <Label>Libellé</Label>
                        <Input v-model="form.label" />
                        <p v-if="form.errors.label" class="text-xs text-destructive">{{ form.errors.label }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>Clé d'abonnement active</Label>
                        <select v-model="form.active_subscription_key" class="gw-select w-full h-11 rounded-xl border border-input bg-white px-4 text-sm">
                            <option value="primary">Primaire</option>
                            <option value="secondary">Secondaire</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label>Environnement EBP</Label>
                        <select v-model="form.environment" class="gw-select w-full h-11 rounded-xl border border-input bg-white px-4 text-sm">
                            <option value="production">Production</option>
                            <option value="preproduction">Préproduction</option>
                            <option value="demo">Démo (serveur simulé)</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label>Cache des lectures</Label>
                        <label class="flex items-center gap-2.5 h-11 px-4 rounded-xl border border-input bg-white text-sm cursor-pointer">
                            <input type="checkbox" v-model="form.cache_enabled" class="gw-check" />
                            <span>{{ form.cache_enabled ? 'Activé' : 'Désactivé' }}</span>
                        </label>
                    </div>
                    <div class="space-y-2">
                        <Label>Durée du cache (secondes)</Label>
                        <Input v-model.number="form.cache_ttl" type="number" min="0" max="86400" :disabled="!form.cache_enabled" placeholder="Défaut (config)" />
                        <p class="text-xs text-muted-foreground">Vide = valeur par défaut. Le cache ne s'applique qu'à une requête identique (URL + paramètres).</p>
                    </div>
                    <div class="space-y-2">
                        <Label>ClientId (vide = inchangé)</Label>
                        <Input v-model="form.client_id" placeholder="•••••" />
                    </div>
                    <div class="space-y-2">
                        <Label>ClientSecret (vide = inchangé)</Label>
                        <Input v-model="form.client_secret" type="password" placeholder="•••••" />
                    </div>
                    <div class="space-y-2">
                        <Label>Clé primaire (vide = inchangée)</Label>
                        <Input v-model="form.subscription_key_primary" type="password" placeholder="•••••" />
                    </div>
                    <div class="space-y-2">
                        <Label>Clé secondaire (vide = inchangée)</Label>
                        <Input v-model="form.subscription_key_secondary" type="password" placeholder="•••••" />
                    </div>
                </div>
                <Button :disabled="form.processing" @click="save">Enregistrer</Button>
            </div>
        </CutCard>

        <!-- Erreur éventuelle -->
        <CutCard v-if="account.lastError" class="mb-4">
            <div class="p-5 flex items-start gap-3">
                <span class="size-9 rounded-full bg-destructive/10 text-destructive grid place-items-center shrink-0"><AlertTriangle class="size-4" /></span>
                <div>
                    <div class="font-medium text-destructive text-sm">Dernière erreur de renouvellement</div>
                    <div class="text-xs text-muted-foreground mt-1 font-mono break-all">{{ account.lastError }}</div>
                </div>
            </div>
        </CutCard>

        <!-- Infos token -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <div class="rounded-[1.5rem] bg-white border border-[#E4DFCE] p-5">
                <div class="text-xs text-muted-foreground mb-1">Refresh token expire</div>
                <div class="text-xl font-medium text-[#002C52]">{{ account.expires }}</div>
            </div>
            <div class="rounded-[1.5rem] bg-white border border-[#E4DFCE] p-5">
                <div class="text-xs text-muted-foreground mb-1">Dernier renouvellement</div>
                <div class="text-xl font-medium text-[#002C52]">{{ account.lastRefresh }}</div>
                <div class="text-xs text-muted-foreground mt-1">Access token jusqu'à {{ account.accessExpires }}</div>
            </div>
            <div class="rounded-[1.5rem] bg-[#002C52] p-5">
                <div class="text-xs text-[#9FB6D1] mb-1">Appels enregistrés</div>
                <div class="text-xl font-medium text-white">{{ callCount }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Dossiers -->
            <CutCard>
                <div class="p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-base font-medium text-[#002C52] flex items-center gap-2"><FolderOpen class="size-4" /> Dossiers</h3>
                        <div class="flex items-center gap-2">
                            <Button variant="outline" size="sm" @click="clearCache">
                                <Trash2 class="size-3.5" /> Vider le cache
                            </Button>
                            <Button variant="outline" size="sm" :disabled="account.status !== 'connected'" @click="syncFolders">
                                <RefreshCw class="size-3.5" /> Synchroniser
                            </Button>
                        </div>
                    </div>
                    <p v-if="!folders.length" class="text-sm text-muted-foreground">
                        Aucun dossier. Les dossiers sont découverts automatiquement depuis EBP à la connexion du compte ;
                        cliquez sur « Synchroniser » une fois le compte connecté pour les récupérer.
                    </p>
                    <div v-else class="space-y-1.5">
                        <div v-for="f in folders" :key="f.id" class="flex items-center gap-2.5 text-sm rounded-xl bg-primary/5 border border-primary/10 px-3 py-2.5">
                            <span class="size-7 rounded-full bg-primary/10 text-primary grid place-items-center shrink-0"><FolderOpen class="size-3.5" /></span>
                            <span class="font-medium text-[#002C52]">{{ f.label || '—' }}</span>
                            <span class="text-muted-foreground">#{{ f.id }}</span>
                            <Badge variant="outline" class="ml-auto">{{ f.short }}</Badge>
                        </div>
                    </div>
                </div>
            </CutCard>

            <!-- Jetons rattachés -->
            <CutCard>
                <div class="p-5">
                    <h3 class="text-base font-medium text-[#002C52] mb-3 flex items-center gap-2"><KeyRound class="size-4" /> Jetons d'apps rattachés</h3>
                    <p v-if="!tokens.length" class="text-sm text-muted-foreground">Aucun jeton ne cible ce compte.</p>
                    <div v-else class="space-y-1.5">
                        <div v-for="t in tokens" :key="t.name" class="flex items-center gap-2.5 text-sm rounded-xl bg-[#F6F2E4] px-3 py-2.5">
                            <span class="size-7 rounded-full bg-primary/10 text-primary grid place-items-center shrink-0"><KeyRound class="size-3.5" /></span>
                            <span class="font-medium text-[#002C52]">{{ t.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ t.lastUsed }}</span>
                            <Badge :variant="t.active ? 'default' : 'secondary'" class="ml-auto">{{ t.active ? 'Actif' : 'Révoqué' }}</Badge>
                        </div>
                    </div>
                </div>
            </CutCard>
        </div>

        <!-- Journal d'activité (timeline) -->
        <CutCard class="mt-4">
            <div class="p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-medium text-[#002C52] flex items-center gap-2">
                        <Clock class="size-4" /> Journal d'activité
                    </h3>
                    <Button variant="outline" size="sm" :disabled="refreshing" @click="refreshLog">
                        <RefreshCw class="size-3.5" :class="{ 'animate-spin': refreshing }" /> Actualiser
                    </Button>
                </div>

                <p v-if="!log.length" class="text-sm text-muted-foreground">
                    Aucun événement pour l'instant. Les connexions, renouvellements de token, refus,
                    synchronisations de dossiers et actions sur les jetons apparaîtront ici.
                </p>

                <ol v-else class="relative border-s border-[#E4DFCE] ms-3 space-y-5">
                    <li v-for="e in log" :key="e.id" class="ms-6">
                        <!-- Pastille sur la ligne de temps -->
                        <span class="absolute -start-[7px] mt-1.5 size-3.5 rounded-full border-2 border-white" :class="styleFor(e.level).dot"></span>
                        <div class="flex items-start gap-3">
                            <span class="size-8 rounded-full grid place-items-center shrink-0" :class="styleFor(e.level).ring">
                                <component :is="iconFor(e.type)" class="size-4" />
                            </span>
                            <div class="min-w-0">
                                <div class="text-sm text-[#002C52]">{{ e.message }}</div>
                                <div class="text-xs text-muted-foreground mt-0.5">
                                    {{ e.at }} · {{ e.ago }}
                                </div>
                                <div v-if="e.context && e.context.error"
                                     class="text-xs text-destructive font-mono break-all mt-1 bg-destructive/5 rounded-lg px-2 py-1">
                                    {{ e.context.error }}
                                </div>
                            </div>
                        </div>
                    </li>
                </ol>

                <!-- Sentinelle du scroll infini + indicateur -->
                <div v-if="log.length" ref="sentinel" class="pt-4 text-center text-xs text-muted-foreground">
                    <span v-if="loadingMore" class="inline-flex items-center gap-1.5">
                        <RefreshCw class="size-3.5 animate-spin" /> Chargement…
                    </span>
                    <span v-else-if="!hasMore">Début du journal.</span>
                </div>
            </div>
        </CutCard>
    </AdminLayout>
</template>
