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
    logs: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({ search: '', status: '', account: null }) },
    accountOptions: { type: Array, default: () => [] },
});

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const account = ref(props.filters.account);

let timer = null;
function reload() {
    router.get('/admin/logs', { search: search.value, status: status.value, account: account.value }, { preserveState: true, replace: true, preserveScroll: true });
}
watch(search, () => { clearTimeout(timer); timer = setTimeout(reload, 350); });
watch(status, reload);
watch(account, reload);

function statusVariant(s) {
    if (s < 300) return 'default';
    if (s < 400) return 'secondary';
    return 'destructive';
}
function go(url) {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
}
function open(id) {
    router.get(`/admin/logs/${id}`);
}
</script>

<template>
    <AdminLayout current="logs">
        <template #title>{{ t('logs.title') }}</template>

        <CutCard class="mb-4">
            <div class="p-5 flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-52">
                    <Search class="size-4 absolute left-3 top-3.5 text-muted-foreground" />
                    <Input v-model="search" class="pl-9" :placeholder="t('logs.searchPlaceholder')" />
                </div>
                <div class="flex gap-1.5">
                    <Button :variant="status === '' ? 'default' : 'outline'" size="sm" @click="status = ''">{{ t('logs.filters.all') }}</Button>
                    <Button :variant="status === 'success' ? 'default' : 'outline'" size="sm" @click="status = 'success'">{{ t('logs.filters.success') }}</Button>
                    <Button :variant="status === 'error' ? 'default' : 'outline'" size="sm" @click="status = 'error'">{{ t('logs.filters.errors') }}</Button>
                </div>
                <select v-if="accountOptions.length" v-model="account" class="gw-select h-9 rounded-full border border-input bg-background px-4 text-sm">
                    <option :value="null">{{ t('logs.allAccounts') }}</option>
                    <option v-for="a in accountOptions" :key="a.id" :value="a.id">{{ a.label }}</option>
                </select>
            </div>
        </CutCard>

        <CutCard>
            <div class="p-5">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('logs.columns.timestamp') }}</TableHead>
                            <TableHead>{{ t('logs.columns.application') }}</TableHead>
                            <TableHead>{{ t('logs.columns.account') }}</TableHead>
                            <TableHead>{{ t('logs.columns.route') }}</TableHead>
                            <TableHead>{{ t('logs.columns.ip') }}</TableHead>
                            <TableHead class="text-right">{{ t('logs.columns.duration') }}</TableHead>
                            <TableHead class="text-right">{{ t('common.status') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="!logs.data.length"><TableCell colspan="7" class="text-muted-foreground text-center py-6">{{ t('logs.empty') }}</TableCell></TableRow>
                        <TableRow v-for="(l, idx) in logs.data" :key="idx" class="cursor-pointer hover:bg-muted/50" @click="open(l.id)">
                            <TableCell class="text-muted-foreground whitespace-nowrap">{{ l.at }}</TableCell>
                            <TableCell>{{ l.app }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ l.account }}</TableCell>
                            <TableCell class="font-mono text-xs">{{ l.route }}</TableCell>
                            <TableCell class="font-mono text-xs text-muted-foreground">{{ l.ip }}</TableCell>
                            <TableCell class="text-right text-muted-foreground">{{ l.ms != null ? l.ms + ' ms' : '—' }}</TableCell>
                            <TableCell class="text-right"><Badge :variant="statusVariant(l.status)">{{ l.status }}</Badge></TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div v-if="logs.links && logs.links.length > 3" class="flex items-center justify-between mt-4 pt-4 border-t border-border">
                    <span class="text-xs text-muted-foreground">{{ t('logs.footer', { n: logs.total }) }}</span>
                    <div class="flex gap-1">
                        <Button v-for="(link, i) in logs.links" :key="i" size="sm"
                                :variant="link.active ? 'default' : 'outline'"
                                :disabled="!link.url"
                                @click="go(link.url)" v-html="link.label" />
                    </div>
                </div>
                <p v-else class="text-xs text-muted-foreground mt-4">{{ t('logs.footer', { n: logs.total }) }}</p>
            </div>
        </CutCard>
    </AdminLayout>
</template>
