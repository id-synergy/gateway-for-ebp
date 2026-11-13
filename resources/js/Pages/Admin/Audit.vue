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
import { Search, ChevronDown, ChevronRight } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    actions: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({ user: null, action: '', search: '' }) },
    userOptions: { type: Array, default: () => [] },
    actionOptions: { type: Array, default: () => [] },
});

const search = ref(props.filters.search);
const user = ref(props.filters.user);
const action = ref(props.filters.action);

let timer = null;
function reload() {
    router.get('/admin/audit', { search: search.value, user: user.value, action: action.value }, { preserveState: true, replace: true, preserveScroll: true });
}
watch(search, () => { clearTimeout(timer); timer = setTimeout(reload, 350); });
watch(user, reload);
watch(action, reload);

// Couleur du badge d'action selon la nature de l'opération.
// Suppressions/révocations/blocages en rouge, créations en vert (default), le reste en gris.
function actionVariant(a) {
    if (/\.(deleted|revoked|removed|deactivated)$/.test(a) || a === 'ip.blocked') return 'destructive';
    if (/\.(created|allowed|reactivated)$/.test(a)) return 'default';
    return 'secondary';
}

// Détails (properties) repliables par ligne.
const expanded = ref(new Set());
function toggle(id) {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
}
function hasDetails(row) {
    return row.properties && Object.keys(row.properties).length > 0;
}

function go(url) {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AdminLayout current="audit">
        <template #title>{{ t('audit.title') }}</template>

        <CutCard class="mb-4">
            <div class="p-5 flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-52">
                    <Search class="size-4 absolute left-3 top-3.5 text-muted-foreground" />
                    <Input v-model="search" class="pl-9" :placeholder="t('audit.searchPlaceholder')" />
                </div>
                <select v-if="userOptions.length" v-model="user" class="gw-select h-9 rounded-full border border-input bg-background px-4 text-sm">
                    <option :value="null">{{ t('audit.allAdmins') }}</option>
                    <option v-for="u in userOptions" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="action" class="gw-select h-9 rounded-full border border-input bg-background px-4 text-sm">
                    <option value="">{{ t('audit.allActions') }}</option>
                    <option v-for="o in actionOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>
        </CutCard>

        <CutCard>
            <div class="p-5">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('audit.columns.timestamp') }}</TableHead>
                            <TableHead>{{ t('audit.columns.admin') }}</TableHead>
                            <TableHead>{{ t('audit.columns.action') }}</TableHead>
                            <TableHead>{{ t('audit.columns.resource') }}</TableHead>
                            <TableHead>{{ t('audit.columns.ip') }}</TableHead>
                            <TableHead class="text-right">{{ t('audit.columns.details') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="!actions.data.length"><TableCell colspan="6" class="text-muted-foreground text-center py-6">{{ t('audit.empty') }}</TableCell></TableRow>
                        <template v-for="row in actions.data" :key="row.id">
                            <TableRow>
                                <TableCell class="text-muted-foreground whitespace-nowrap">{{ row.at }}</TableCell>
                                <TableCell>
                                    <div v-if="row.adminName" class="font-medium">{{ row.adminName }}</div>
                                    <div class="text-xs text-muted-foreground">{{ row.adminEmail ?? '—' }}</div>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="actionVariant(row.action)">{{ row.actionLabel }}</Badge>
                                </TableCell>
                                <TableCell>
                                    <div class="font-medium">{{ row.label }}</div>
                                    <div v-if="row.subjectType" class="text-xs text-muted-foreground">{{ row.subjectType }}</div>
                                </TableCell>
                                <TableCell class="font-mono text-xs text-muted-foreground">{{ row.ip ?? '—' }}</TableCell>
                                <TableCell class="text-right">
                                    <Button v-if="hasDetails(row)" size="sm" variant="ghost" class="gap-1" @click="toggle(row.id)">
                                        <component :is="expanded.has(row.id) ? ChevronDown : ChevronRight" class="size-4" />
                                        {{ expanded.has(row.id) ? t('audit.hide') : t('audit.show') }}
                                    </Button>
                                    <span v-else class="text-muted-foreground text-xs">—</span>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="hasDetails(row) && expanded.has(row.id)">
                                <TableCell colspan="6" class="bg-muted/40">
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

                <div v-if="actions.links && actions.links.length > 3" class="flex items-center justify-between mt-4 pt-4 border-t border-border">
                    <span class="text-xs text-muted-foreground">{{ t('audit.footer', { n: actions.total }) }}</span>
                    <div class="flex gap-1">
                        <Button v-for="(link, i) in actions.links" :key="i" size="sm"
                                :variant="link.active ? 'default' : 'outline'"
                                :disabled="!link.url"
                                @click="go(link.url)" v-html="link.label" />
                    </div>
                </div>
                <p v-else class="text-xs text-muted-foreground mt-4">{{ t('audit.footer', { n: actions.total }) }}</p>
            </div>
        </CutCard>
    </AdminLayout>
</template>
