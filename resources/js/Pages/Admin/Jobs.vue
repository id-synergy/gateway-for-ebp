<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Badge } from '@/components/ui/badge';
import CutCard from '@/components/CutCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import { ListChecks } from 'lucide-vue-next';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';

import { router } from '@inertiajs/vue3';

defineProps({ jobs: { type: Array, default: () => [] } });

function open(id) {
    router.get(`/admin/jobs/${id}`);
}

const statusBadge = {
    pending: { text: 'En attente', variant: 'secondary' },
    running: { text: 'En cours', variant: 'default' },
    done: { text: 'Terminé', variant: 'default' },
    partial: { text: 'Partiel', variant: 'secondary' },
    failed: { text: 'Échec', variant: 'destructive' },
};
</script>

<template>
    <AdminLayout current="jobs">
        <template #title>Jobs</template>

        <CutCard>
            <EmptyState v-if="!jobs.length" :icon="ListChecks" title="Aucun job"
                text="Les requêtes lourdes (lots, requêtes composites) basculées en file apparaîtront ici avec leur progression." />
            <div v-else class="p-5">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Job ID</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Application</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead>Progression</TableHead>
                            <TableHead class="text-right">Statut</TableHead>
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
