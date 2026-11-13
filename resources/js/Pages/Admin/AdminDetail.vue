<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
    ArrowLeft, ShieldCheck, Mail, Clock, LogIn, History, Bell,
    ChevronDown, ChevronRight,
} from 'lucide-vue-next';

const props = defineProps({
    admin: { type: Object, required: true },
    summary: { type: Object, default: () => ({}) },
    events: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    preferences: { type: Array, default: () => [] },
});

// --- Connexions : libellé et couleur du badge par type (repris de Connexions.vue) ---
const EVENT_META = {
    login: { label: 'Connexion', variant: 'default' },
    logout: { label: 'Déconnexion', variant: 'secondary' },
    failed: { label: 'Échec', variant: 'destructive' },
    two_factor_passed: { label: '2FA validée', variant: 'default' },
    two_factor_failed: { label: '2FA échouée', variant: 'destructive' },
    session_expired: { label: 'Session expirée', variant: 'outline' },
};
function eventLabel(e) { return EVENT_META[e]?.label ?? e; }
function eventVariant(e) { return EVENT_META[e]?.variant ?? 'secondary'; }
// session_expired : orange — pas de variante Badge dédiée, on force la classe.
function eventClass(e) {
    return e === 'session_expired' ? 'border-orange-500 text-orange-600 dark:text-orange-400' : '';
}

// --- Actions : couleur du badge selon la nature de l'opération (repris de Audit.vue) ---
function actionVariant(a) {
    if (/\.(deleted|revoked|removed|deactivated)$/.test(a) || a === 'ip.blocked') return 'destructive';
    if (/\.(created|allowed|reactivated)$/.test(a)) return 'default';
    return 'secondary';
}

function truncate(s, n = 42) {
    if (!s) return '—';
    return s.length > n ? s.slice(0, n) + '…' : s;
}

// Détails (properties) des actions, repliables par ligne.
const expanded = ref(new Set());
function toggle(id) {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
}
function hasDetails(row) {
    return row.properties && Object.keys(row.properties).length > 0;
}

// Préférences d'alertes : même route que la page Admins (admin.preferences.toggle).
function togglePref(type, current) {
    router.post('/admin/preferences', { user_id: props.admin.id, alert_type: type, enabled: !current }, { preserveScroll: true });
}

function initials(name) {
    return name.split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase();
}
</script>

<template>
    <AdminLayout current="admins">
        <template #title>
            <Link href="/admin/admins" class="inline-flex items-center gap-2 text-muted-foreground hover:text-[#002C52]">
                <ArrowLeft class="size-4" /> Admins
            </Link>
        </template>

        <!-- En-tête de l'admin -->
        <CutCard class="mb-4">
            <div class="p-5 flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <div class="size-12 rounded-full bg-primary text-primary-foreground grid place-items-center font-medium">
                        {{ initials(admin.name) }}
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-lg font-medium text-[#002C52]">{{ admin.name }}</span>
                            <Badge v-if="admin.twoFa" variant="default" class="gap-1"><ShieldCheck class="size-3" /> 2FA</Badge>
                            <Badge v-else variant="secondary">2FA désactivée</Badge>
                            <Badge v-if="admin.defaultContact" variant="outline">Contact par défaut</Badge>
                            <Badge :variant="admin.active ? 'default' : 'secondary'" :class="admin.active ? 'bg-[#1D9E75] hover:bg-[#1D9E75]' : ''">
                                {{ admin.active ? 'Actif' : 'Désactivé' }}
                            </Badge>
                        </div>
                        <div class="text-xs text-muted-foreground flex items-center gap-1 mt-1">
                            <Mail class="size-3 shrink-0" /> {{ admin.email }}
                            <span class="mx-1">·</span>
                            Créé le {{ admin.createdAt }}
                        </div>
                    </div>
                </div>
            </div>
        </CutCard>

        <!-- Résumé -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <div class="rounded-[1.5rem] bg-white border border-[#E4DFCE] p-5">
                <div class="text-xs text-muted-foreground mb-1">Dernière connexion</div>
                <div class="text-xl font-medium text-[#002C52]">{{ summary.lastLoginAt ?? 'Jamais' }}</div>
                <div v-if="summary.lastLoginAgo" class="text-xs text-muted-foreground mt-1">{{ summary.lastLoginAgo }}</div>
            </div>
            <div class="rounded-[1.5rem] bg-white border border-[#E4DFCE] p-5">
                <div class="text-xs text-muted-foreground mb-1">Connexions enregistrées</div>
                <div class="text-xl font-medium text-[#002C52]">{{ summary.loginCount ?? 0 }}</div>
            </div>
            <div class="rounded-[1.5rem] bg-[#002C52] p-5">
                <div class="text-xs text-[#9FB6D1] mb-1">Dernière action</div>
                <div class="text-xl font-medium text-white truncate">{{ summary.lastActionLabel ?? '—' }}</div>
                <div v-if="summary.lastActionAt" class="text-xs text-[#9FB6D1] mt-1">{{ summary.lastActionAt }}</div>
            </div>
        </div>

        <!-- Connexions récentes -->
        <CutCard class="mb-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-3 flex items-center gap-2"><LogIn class="size-4" /> Connexions récentes</h3>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Horodatage</TableHead>
                            <TableHead>Événement</TableHead>
                            <TableHead>IP</TableHead>
                            <TableHead>Navigateur</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="!events.length"><TableCell colspan="4" class="text-muted-foreground text-center py-6">Aucune connexion enregistrée pour cet admin.</TableCell></TableRow>
                        <TableRow v-for="e in events" :key="e.id">
                            <TableCell class="text-muted-foreground whitespace-nowrap">{{ e.at }}</TableCell>
                            <TableCell>
                                <Badge :variant="eventVariant(e.event)" :class="eventClass(e.event)">{{ eventLabel(e.event) }}</Badge>
                            </TableCell>
                            <TableCell class="font-mono text-xs text-muted-foreground">{{ e.ip ?? '—' }}</TableCell>
                            <TableCell class="text-xs text-muted-foreground max-w-xs truncate" :title="e.userAgent ?? ''">{{ truncate(e.userAgent) }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <p class="text-xs text-muted-foreground mt-4">
                    Vue filtrée sur cet admin. Le journal global reste disponible dans
                    <Link href="/admin/connexions" class="underline hover:text-[#002C52]">Connexions</Link>.
                </p>
            </div>
        </CutCard>

        <!-- Actions récentes -->
        <CutCard class="mb-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-3 flex items-center gap-2"><History class="size-4" /> Actions récentes</h3>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Horodatage</TableHead>
                            <TableHead>Action</TableHead>
                            <TableHead>Ressource</TableHead>
                            <TableHead>IP</TableHead>
                            <TableHead class="text-right">Détails</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="!actions.length"><TableCell colspan="5" class="text-muted-foreground text-center py-6">Aucune action enregistrée pour cet admin.</TableCell></TableRow>
                        <template v-for="row in actions" :key="row.id">
                            <TableRow>
                                <TableCell class="text-muted-foreground whitespace-nowrap">{{ row.at }}</TableCell>
                                <TableCell>
                                    <Badge :variant="actionVariant(row.action)">{{ row.actionLabel }}</Badge>
                                </TableCell>
                                <TableCell>
                                    <div class="font-medium">{{ row.label }}</div>
                                    <div v-if="row.subjectType" class="text-xs text-muted-foreground">{{ row.subjectType }}</div>
                                </TableCell>
                                <TableCell class="font-mono text-xs text-muted-foreground">{{ row.ip ?? '—' }}</TableCell>
                                <TableCell class="text-right">
                                    <button v-if="hasDetails(row)" type="button" class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-[#002C52]" @click="toggle(row.id)">
                                        <component :is="expanded.has(row.id) ? ChevronDown : ChevronRight" class="size-4" />
                                        {{ expanded.has(row.id) ? 'Masquer' : 'Voir' }}
                                    </button>
                                    <span v-else class="text-muted-foreground text-xs">—</span>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="hasDetails(row) && expanded.has(row.id)">
                                <TableCell colspan="5" class="bg-muted/40">
                                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-xs py-1">
                                        <template v-for="(value, key) in row.properties" :key="key">
                                            <dt class="font-medium text-muted-foreground">{{ key }}</dt>
                                            <dd class="font-mono break-all">{{ Array.isArray(value) ? value.join(', ') : value }}</dd>
                                        </template>
                                    </dl>
                                </TableCell>
                            </TableRow>
                        </template>
                    </TableBody>
                </Table>
                <p class="text-xs text-muted-foreground mt-4">
                    Vue filtrée sur cet admin. Le journal global reste disponible dans
                    <Link href="/admin/audit" class="underline hover:text-[#002C52]">Audit</Link>.
                </p>
            </div>
        </CutCard>

        <!-- Préférences d'alertes -->
        <CutCard>
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-1 flex items-center gap-2"><Bell class="size-4" /> Préférences d'alertes</h3>
                <p class="text-xs text-muted-foreground mb-4">Quelles alertes cet admin reçoit par mail. Cliquez pour modifier.</p>
                <div class="space-y-1.5">
                    <div v-for="p in preferences" :key="p.type" class="flex items-center gap-3 text-sm rounded-xl bg-[#F6F2E4] px-3 py-2.5">
                        <span class="font-medium text-[#002C52] flex-1 min-w-0">{{ p.label }}</span>
                        <Switch :model-value="p.enabled" @update:model-value="togglePref(p.type, p.enabled)" />
                    </div>
                </div>
            </div>
        </CutCard>
    </AdminLayout>
</template>
