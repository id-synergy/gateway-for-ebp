<script setup>
import { computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Badge } from '@/components/ui/badge';
import CutCard from '@/components/CutCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import { ListChecks } from 'lucide-vue-next';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';

import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({ jobs: { type: Array, default: () => [] } });

function open(id) {
    router.get(`/admin/jobs/${id}`);
}

const statusBadge = computed(() => ({
    pending: { text: t('jobs.status.pending'), variant: 'secondary' },
    running: { text: t('jobs.status.running'), variant: 'default' },
    done: { text: t('jobs.status.done'), variant: 'default' },
    partial: { text: t('jobs.status.partial'), variant: 'secondary' },
    failed: { text: t('jobs.status.failed'), variant: 'destructive' },
}));
</script>

<template>
    <AdminLayout current="jobs">
        <template #title>{{ t('jobs.title') }}</template>

        <CutCard>
            <EmptyState v-if="!jobs.length" :icon="ListChecks" :title="t('jobs.empty.title')"
                :text="t('jobs.empty.text')" />
            <div v-else class="p-5">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('jobs.columns.jobId') }}</TableHead>
                            <TableHead>{{ t('jobs.columns.type') }}</TableHead>
                            <TableHead>{{ t('jobs.columns.application') }}</TableHead>
                            <TableHead>{{ t('jobs.columns.description') }}</TableHead>
                            <TableHead>{{ t('jobs.columns.progress') }}</TableHead>
                            <TableHead class="text-right">{{ t('common.status') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="j in jobs" :key="j.id" class="cursor-pointer hover:bg-muted/50" @click="open(j.publicId)">
                            <TableCell class="font-mono text-xs">{{ j.id }}</TableCell>
                            <TableCell><Badge variant="outline">{{ j.type }}</Badge></TableCell>
                            <TableCell>{{ j.app }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ j.desc }}</TableCell>
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-24 rounded-full bg-muted overflow-hidden">
                                        <div class="h-full bg-primary" :style="{ width: Math.round(j.done / j.total * 100) + '%' }"></div>
                                    </div>
                                    <span class="text-xs text-muted-foreground">{{ j.done }}/{{ j.total }}</span>
                                </div>
                            </TableCell>
                            <TableCell class="text-right"><Badge :variant="statusBadge[j.status].variant">{{ statusBadge[j.status].text }}</Badge></TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </CutCard>
    </AdminLayout>
</template>
