<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { ListChecks, Gauge, Bell, ExternalLink, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    queue: { type: Object, default: () => ({ pending: 0, done24h: 0, failed24h: 0 }) },
    quota: { type: Object, default: () => ({ usedToday: 0, perDay: 10000, perSecond: 1 }) },
    alerts: { type: Array, default: () => [] },
});

function clearAllCache() {
    if (window.confirm('Vider tout le cache de lecture (tous les comptes) ?')) {
        router.post('/admin/cache/clear', {}, { preserveScroll: true });
    }
}

const quotaPct = computed(() => Math.min(100, Math.round(props.quota.usedToday / Math.max(1, props.quota.perDay) * 100)));
</script>

<template>
    <AdminLayout current="supervision">
        <template #title>Supervision</template>
        <template #actions>
            <div class="flex items-center gap-2">
                <Button size="lg" variant="outline" @click="clearAllCache"><Trash2 class="size-4" /> Vider tout le cache</Button>
                <a href="/horizon" target="_blank">
                    <Button size="lg" variant="outline"><ExternalLink class="size-4" /> Ouvrir Horizon</Button>
                </a>
            </div>
        </template>

        <!-- Files -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <div class="rounded-[1.5rem] bg-[#002C52] p-5">
                <div class="size-10 rounded-full bg-white/12 text-white grid place-items-center mb-3"><ListChecks class="size-5" /></div>
                <div class="text-3xl font-medium text-white">{{ queue.pending }}</div>
                <div class="text-xs text-[#9FB6D1] mt-1">Jobs en file (en attente / en cours)</div>
            </div>
            <div class="rounded-[1.5rem] bg-white border border-[#E4DFCE] p-5">
                <div class="text-3xl font-medium text-[#1D9E75]">{{ queue.done24h }}</div>
                <div class="text-xs text-muted-foreground mt-1">Jobs terminés (24 h)</div>
            </div>
            <div class="rounded-[1.5rem] bg-white border border-[#E4DFCE] p-5">
                <div class="text-3xl font-medium" :class="queue.failed24h ? 'text-destructive' : 'text-[#002C52]'">{{ queue.failed24h }}</div>
                <div class="text-xs text-muted-foreground mt-1">Jobs en échec / partiels (24 h)</div>
            </div>
        </div>

        <!-- Quota EBP -->
        <CutCard class="mb-4">
            <div class="p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-medium text-[#002C52] flex items-center gap-2"><Gauge class="size-4" /> Quota EBP</h3>
                    <span class="text-xs text-muted-foreground">limite {{ quota.perSecond }} appel/s · {{ quota.perDay.toLocaleString('fr-FR') }}/jour</span>
                </div>
                <div class="flex items-end justify-between mb-2">
                    <div class="text-2xl font-medium text-[#002C52]">{{ quota.usedToday.toLocaleString('fr-FR') }} <span class="text-sm text-muted-foreground font-normal">/ {{ quota.perDay.toLocaleString('fr-FR') }} aujourd'hui</span></div>
                    <span class="text-sm font-medium" :class="quotaPct > 80 ? 'text-destructive' : 'text-[#1D9E75]'">{{ quotaPct }}%</span>
                </div>
                <div class="h-2 w-full rounded-full bg-muted overflow-hidden">
                    <div class="h-full rounded-full" :class="quotaPct > 80 ? 'bg-destructive' : 'bg-primary'" :style="{ width: quotaPct + '%' }"></div>
                </div>
            </div>
        </CutCard>

        <!-- Historique des alertes -->
        <CutCard>
            <EmptyState v-if="!alerts.length" :icon="Bell" title="Aucune alerte envoyée"
                text="Les alertes d'exploitation envoyées aux admins (échec de renouvellement, purge, compte expirant…) apparaîtront ici." />
            <div v-else class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-3 flex items-center gap-2"><Bell class="size-4" /> Alertes envoyées</h3>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Type</TableHead>
                            <TableHead>Objet</TableHead>
                            <TableHead>Destinataires</TableHead>
                            <TableHead class="text-right">Envoyée le</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="(a, i) in alerts" :key="i">
                            <TableCell class="font-medium text-[#002C52]">{{ a.type }}</TableCell>
                            <TableCell class="text-muted-foreground font-mono text-xs">{{ a.subject || '—' }}</TableCell>
                            <TableCell class="text-muted-foreground text-xs">{{ (a.recipients || []).join(', ') }}</TableCell>
                            <TableCell class="text-right text-muted-foreground whitespace-nowrap">{{ a.sentAt }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </CutCard>
    </AdminLayout>
</template>
