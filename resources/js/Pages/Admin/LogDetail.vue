<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Repeat } from 'lucide-vue-next';

const { t, locale } = useI18n();

const props = defineProps({
    log: { type: Object, required: true },
    body: { type: String, default: null },
    canReplay: { type: Boolean, default: false },
});

// Lien vers le testeur pré-rempli avec les éléments du log (méthode, endpoint, params, compte, dossier).
const replayHref = computed(() => {
    const params = new URLSearchParams();
    if (props.log.accountId) params.set('account', props.log.accountId);
    if (props.log.folderId) params.set('folder', props.log.folderId);
    if (props.log.method) params.set('method', props.log.method);
    if (props.log.endpoint) params.set('endpoint', props.log.endpoint);
    for (const [key, value] of Object.entries(props.log.params ?? {})) {
        params.set(`params[${key}]`, typeof value === 'object' ? JSON.stringify(value) : value);
    }
    return `/admin/testeur?${params.toString()}`;
});

function statusVariant(s) {
    if (s < 300) return 'default';
    if (s < 400) return 'secondary';
    return 'destructive';
}

const infos = computed(() => [
    { label: t('logDetail.infos.timestamp'), value: props.log.at },
    { label: t('logDetail.infos.application'), value: props.log.app },
    { label: t('logDetail.infos.account'), value: props.log.account },
    { label: t('logDetail.infos.folder'), value: props.log.folder },
    { label: t('logDetail.infos.sourceIp'), value: props.log.ip || '—' },
    { label: t('logDetail.infos.duration'), value: `${props.log.durationMs} ms` },
]);
</script>

<template>
    <AdminLayout current="logs">
        <template #title>
            <Link href="/admin/logs" class="inline-flex items-center gap-2 text-muted-foreground hover:text-[#002C52]">
                <ArrowLeft class="size-4" /> {{ t('logs.title') }}
            </Link>
        </template>

        <template v-if="canReplay" #actions>
            <Button as-child variant="outline">
                <Link :href="replayHref"><Repeat class="size-4" /> {{ t('logDetail.replay') }}</Link>
            </Button>
        </template>

        <CutCard class="mb-4">
            <div class="p-5 flex items-center justify-between flex-wrap gap-3">
                <div class="font-mono text-sm text-[#002C52]">{{ log.method }} {{ log.endpoint }}</div>
                <Badge :variant="statusVariant(log.status)">{{ log.status }}</Badge>
            </div>
        </CutCard>

        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
            <div v-for="i in infos" :key="i.label" class="rounded-[1.5rem] bg-white border border-[#E4DFCE] p-5">
                <div class="text-xs text-muted-foreground mb-0.5">{{ i.label }}</div>
                <div class="font-medium text-[#002C52] text-sm">{{ i.value }}</div>
            </div>
        </div>

        <!-- Paramètres -->
        <CutCard v-if="log.params && Object.keys(log.params).length" class="mb-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-3">{{ t('logDetail.requestParams') }}</h3>
                <pre class="rounded-xl bg-[#F6F2E4] text-[#002C52] text-xs p-4 overflow-auto">{{ JSON.stringify(log.params, null, 2) }}</pre>
            </div>
        </CutCard>

        <!-- Corps de réponse -->
        <CutCard>
            <div class="p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-medium text-[#002C52]">{{ t('logDetail.responseBody') }}</h3>
                    <span v-if="log.size" class="text-xs text-muted-foreground">{{ t('logDetail.bytes', { n: log.size.toLocaleString(locale === 'fr' ? 'fr-FR' : 'en-US') }) }}</span>
                </div>
                <p v-if="!body" class="text-sm text-muted-foreground">
                    {{ t('logDetail.bodyUnavailable') }}
                </p>
                <pre v-else class="rounded-xl bg-[#002C52] text-[#DCE7F5] text-xs p-4 overflow-auto max-h-[28rem]">{{ body }}</pre>
            </div>
        </CutCard>
    </AdminLayout>
</template>
