<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, AlertTriangle } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({ job: { type: Object, required: true } });

const statusBadge = computed(() => ({
    pending: { text: t('jobs.status.pending'), variant: 'secondary' },
    running: { text: t('jobs.status.running'), variant: 'default' },
    done: { text: t('jobs.status.done'), variant: 'default' },
    partial: { text: t('jobs.status.partial'), variant: 'secondary' },
    failed: { text: t('jobs.status.failed'), variant: 'destructive' },
}));

const infos = computed(() => [
    { label: t('jobDetail.infos.type'), value: props.job.type },
    { label: t('jobDetail.infos.application'), value: props.job.app },
    { label: t('jobDetail.infos.account'), value: props.job.account },
    { label: t('jobDetail.infos.folder'), value: props.job.folder },
    { label: t('jobDetail.infos.createdAt'), value: props.job.createdAt },
    { label: t('jobDetail.infos.finishedAt'), value: props.job.finishedAt || '—' },
]);
</script>

<template>
    <AdminLayout current="jobs">
        <template #title>
            <Link href="/admin/jobs" class="inline-flex items-center gap-2 text-muted-foreground hover:text-[#002C52]">
                <ArrowLeft class="size-4" /> {{ t('jobs.title') }}
            </Link>
        </template>

        <CutCard class="mb-4">
            <div class="p-5">
                <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
                    <div>
                        <div class="font-mono text-sm text-[#002C52]">{{ job.id }}</div>
                        <div class="text-xs text-muted-foreground">{{ t('jobDetail.progress', { done: job.done, total: job.total }) }}</div>
                    </div>
                    <Badge :variant="(statusBadge[job.status] || statusBadge.pending).variant">
                        {{ (statusBadge[job.status] || statusBadge.pending).text }}
                    </Badge>
                </div>
                <div class="h-2 w-full rounded-full bg-muted overflow-hidden">
                    <div class="h-full bg-primary" :style="{ width: Math.round(job.done / job.total * 100) + '%' }"></div>
                </div>
            </div>
        </CutCard>

        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
            <div v-for="i in infos" :key="i.label" class="rounded-[1.5rem] bg-white border border-[#E4DFCE] p-5">
                <div class="text-xs text-muted-foreground mb-0.5">{{ i.label }}</div>
                <div class="font-medium text-[#002C52] text-sm">{{ i.value }}</div>
            </div>
        </div>

        <!-- Sous-erreurs -->
        <CutCard v-if="job.errors && job.errors.length" class="mb-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-destructive mb-3 flex items-center gap-2"><AlertTriangle class="size-4" /> {{ t('jobDetail.failedSubjobs') }}</h3>
                <div class="space-y-1.5">
                    <div v-for="(e, i) in job.errors" :key="i" class="rounded-xl bg-destructive/5 border border-destructive/15 px-3 py-2 text-sm">
                        <span v-if="e.key" class="font-mono text-xs text-destructive mr-2">{{ e.key }}</span>
                        <span class="text-muted-foreground">{{ e.message }}</span>
                    </div>
                </div>
            </div>
        </CutCard>

        <!-- Résultat -->
        <CutCard>
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-3">{{ t('jobDetail.result') }}</h3>
                <p v-if="!job.result" class="text-sm text-muted-foreground">{{ t('jobDetail.noResult') }}</p>
                <pre v-else class="rounded-xl bg-[#002C52] text-[#DCE7F5] text-xs p-4 overflow-auto max-h-96">{{ JSON.stringify(job.result, null, 2) }}</pre>
            </div>
        </CutCard>
    </AdminLayout>
</template>
