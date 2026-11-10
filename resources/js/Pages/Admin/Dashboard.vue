<script setup>
import { computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Plug, KeyRound, ListChecks, Activity } from 'lucide-vue-next';

const props = defineProps({
    stats: { type: Object, default: () => ({ accounts: 0, tokens: 0, jobs: 0, calls24h: 0 }) },
    accounts: { type: Array, default: () => [] },
    activity: { type: Array, default: () => [] },
    recentCalls: { type: Array, default: () => [] },
    errorRate: { type: Number, default: 0 },
});

// Alternance marine / blanc (1 sur 2), coin coupé alterné.
const cards = computed(() => [
    { label: 'Comptes EBP connectés', value: props.stats.accounts, icon: Plug, dark: false, corner: 'tl' },
    { label: "Jetons d'apps actifs", value: props.stats.tokens, icon: KeyRound, dark: true, corner: 'tr' },
    { label: 'Jobs en file', value: props.stats.jobs, icon: ListChecks, dark: false, corner: 'tl' },
    { label: 'Appels (24 h)', value: props.stats.calls24h, icon: Activity, dark: true, corner: 'tr' },
]);

const maxActivity = computed(() => Math.max(1, ...props.activity.map((a) => a.count)));

const stateBadge = {
    healthy: { text: 'Sain', variant: 'default' },
    expiring: { text: 'Expire bientôt', variant: 'secondary' },
    broken: { text: 'Rompu', variant: 'destructive' },
};

function statusColor(s) {
    if (s < 300) return '#1D9E75';
    if (s < 400) return '#BA7517';
    return '#E24B4A';
}
</script>

<template>
    <AdminLayout current="dashboard">
        <template #title>Tableau de bord</template>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div
                v-for="s in cards" :key="s.label"
                class="p-6 pt-7"
                style="border-radius:1.5rem"
                :class="s.dark ? 'bg-[#002C52]' : 'bg-white border border-[#E4DFCE]'"
            >
                <div class="size-11 rounded-full grid place-items-center mb-4"
                     :class="s.dark ? 'bg-white/12 text-white' : 'bg-primary/10 text-primary'">
                    <component :is="s.icon" class="size-[22px]" />
                </div>
                <div class="text-5xl leading-none font-medium tracking-tight"
                     :class="s.dark ? 'text-white' : 'text-[#002C52]'">{{ s.value }}</div>
                <div class="text-sm mt-3" :class="s.dark ? 'text-[#9FB6D1]' : 'text-muted-foreground'">{{ s.label }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
            <!-- Graphe d'activité -->
            <div class="lg:col-span-2 bg-white p-5" style="border:1px solid #E4DFCE; border-radius:1.5rem">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-medium text-[#002C52]">Activité des 7 derniers jours</h3>
                    <span class="text-xs text-muted-foreground">{{ errorRate }}% d'erreurs (24 h)</span>
                </div>
                <div class="flex items-end gap-2.5 h-36 border-b-2 border-[#E4DFCE]">
                    <div v-for="(a, i) in activity" :key="i" class="flex-1 flex flex-col items-center justify-end h-full group">
                        <div class="text-xs font-medium mb-1" :class="a.count ? 'text-[#002C52]' : 'text-muted-foreground'">{{ a.count }}</div>
                        <div class="w-full rounded-t-md transition-all"
                             :class="a.count ? 'bg-primary' : 'bg-[#D8E2EF]'"
                             :style="{ height: a.count ? Math.max(6, Math.round(a.count / maxActivity * 100)) + '%' : '3px' }"></div>
                    </div>
                </div>
                <div class="flex gap-2.5 mt-2">
                    <div v-for="(a, i) in activity" :key="i" class="flex-1 text-center text-xs text-muted-foreground">{{ a.label }}</div>
                </div>
            </div>

            <!-- Derniers appels -->
            <div class="bg-white p-5" style="border:1px solid #E4DFCE; border-radius:1.5rem">
                <h3 class="text-base font-medium text-[#002C52] mb-4">Derniers appels</h3>
                <div class="space-y-3">
                    <p v-if="!recentCalls.length" class="text-sm text-muted-foreground">Aucun appel récent.</p>
                    <div v-for="(c, i) in recentCalls" :key="i" class="flex items-center gap-2.5 text-sm">
                        <span class="size-2 rounded-full shrink-0" :style="{ background: statusColor(c.status) }"></span>
                        <span class="font-mono text-xs text-muted-foreground w-9 shrink-0">{{ c.at }}</span>
                        <span class="truncate flex-1">{{ c.app }}</span>
                        <span class="font-mono text-xs px-1.5 py-0.5 rounded"
                              :style="{ color: statusColor(c.status), background: statusColor(c.status) + '18' }">{{ c.status }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- État des comptes -->
        <div class="mt-4 bg-white p-5" style="border:1px solid #E4DFCE; border-radius:1.5rem">
            <h3 class="text-base font-medium text-[#002C52] mb-4">État des comptes EBP</h3>
            <div class="space-y-3">
                <p v-if="!accounts.length" class="text-sm text-muted-foreground">Aucun compte EBP connecté.</p>
                <div v-for="a in accounts" :key="a.label" class="flex items-center justify-between border-b border-border last:border-0 pb-3 last:pb-0">
                    <div>
                        <div class="font-medium text-sm">{{ a.label }}</div>
                        <div class="text-xs text-muted-foreground">Refresh token {{ a.expires }}</div>
                    </div>
                    <Badge :variant="(stateBadge[a.state] || stateBadge.broken).variant">{{ (stateBadge[a.state] || stateBadge.broken).text }}</Badge>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
