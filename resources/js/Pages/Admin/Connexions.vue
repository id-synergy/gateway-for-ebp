<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { Search } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    events: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({ user: null, event: '', ip: '' }) },
    userOptions: { type: Array, default: () => [] },
    eventOptions: { type: Array, default: () => [] },
});

const ip = ref(props.filters.ip);
const user = ref(props.filters.user);
const event = ref(props.filters.event);

let timer = null;
function reload() {
    router.get('/admin/connexions', { ip: ip.value, user: user.value, event: event.value }, { preserveState: true, replace: true, preserveScroll: true });
}
watch(ip, () => { clearTimeout(timer); timer = setTimeout(reload, 350); });
watch(user, reload);
watch(event, reload);

// Libellé et couleur du badge par type d'événement. Les libellés passent par i18n ;
// la variante de couleur reste côté front.
const EVENT_VARIANT = {
    login: 'default',
    logout: 'secondary',
    failed: 'destructive',
    two_factor_passed: 'default',
    two_factor_failed: 'destructive',
    session_expired: 'outline',
};
function eventLabel(e) {
    return EVENT_VARIANT[e] ? t(`connexions.events.${e}`) : e;
}
function eventVariant(e) {
    return EVENT_VARIANT[e] ?? 'secondary';
}
// session_expired : orange — pas de variante Badge dédiée, on force la classe.
function eventClass(e) {
    return e === 'session_expired' ? 'border-orange-500 text-orange-600 dark:text-orange-400' : '';
}
function truncate(s, n = 42) {
    if (!s) return '—';
    return s.length > n ? s.slice(0, n) + '…' : s;
}
function go(url) {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AdminLayout current="connexions">
        <template #title>{{ t('connexions.title') }}</template>

        <CutCard class="mb-4">
            <div class="p-5 flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-52">
                    <Search class="size-4 absolute left-3 top-3.5 text-muted-foreground" />
                    <Input v-model="ip" class="pl-9" :placeholder="t('connexions.searchPlaceholder')" />
                </div>
                <select v-if="userOptions.length" v-model="user" class="gw-select h-9 rounded-full border border-input bg-background px-4 text-sm">
                    <option :value="null">{{ t('connexions.allAdmins') }}</option>
                    <option v-for="u in userOptions" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="event" class="gw-select h-9 rounded-full border border-input bg-background px-4 text-sm">
                    <option value="">{{ t('connexions.allEvents') }}</option>
                    <option v-for="o in eventOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>
        </CutCard>

        <CutCard>
            <div class="p-5">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('connexions.columns.timestamp') }}</TableHead>
                            <TableHead>{{ t('connexions.columns.admin') }}</TableHead>
                            <TableHead>{{ t('connexions.columns.event') }}</TableHead>
                            <TableHead>{{ t('connexions.columns.ip') }}</TableHead>
                            <TableHead>{{ t('connexions.columns.browser') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="!events.data.length"><TableCell colspan="5" class="text-muted-foreground text-center py-6">{{ t('connexions.empty') }}</TableCell></TableRow>
                        <TableRow v-for="(e, idx) in events.data" :key="idx">
                            <TableCell class="text-muted-foreground whitespace-nowrap">{{ e.at }}</TableCell>
                            <TableCell>
                                <div v-if="e.adminName" class="font-medium">{{ e.adminName }}</div>
                                <div class="text-xs text-muted-foreground">{{ e.adminEmail ?? '—' }}</div>
                            </TableCell>
                            <TableCell>
                                <Badge :variant="eventVariant(e.event)" :class="eventClass(e.event)">{{ eventLabel(e.event) }}</Badge>
                            </TableCell>
                            <TableCell class="font-mono text-xs text-muted-foreground">{{ e.ip ?? '—' }}</TableCell>
                            <TableCell class="text-xs text-muted-foreground max-w-xs truncate" :title="e.userAgent ?? ''">{{ truncate(e.userAgent) }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div v-if="events.links && events.links.length > 3" class="flex items-center justify-between mt-4 pt-4 border-t border-border">
                    <span class="text-xs text-muted-foreground">{{ t('connexions.footer', { n: events.total }) }}</span>
                    <div class="flex gap-1">
                        <Button v-for="(link, i) in events.links" :key="i" size="sm"
                                :variant="link.active ? 'default' : 'outline'"
                                :disabled="!link.url"
                                @click="go(link.url)" v-html="link.label" />
                    </div>
                </div>
                <p v-else class="text-xs text-muted-foreground mt-4">{{ t('connexions.footer', { n: events.total }) }}</p>
            </div>
        </CutCard>
    </AdminLayout>
</template>
