<script setup>
import { ref, onMounted, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Badge } from '@/components/ui/badge';
import { BookOpen, KeyRound, Server, Copy, Check, Download } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    openapiUrl: { type: String, required: true },
    baseUrl: { type: String, required: true },
    groups: { type: Array, default: () => [] },
    proxyPrefixes: { type: Array, default: () => [] },
});

const SWAGGER_VERSION = '5.17.14';
const tab = ref('guide');
const swaggerLoaded = ref(false);
const copied = ref(false);

// Couleur du badge selon le verbe HTTP.
const methodClass = (m) => ({
    GET: 'bg-[#1D9E75]/15 text-[#0F6E56]',
    POST: 'bg-primary/15 text-primary',
    PATCH: 'bg-[#FAC775] text-[#633806]',
    DELETE: 'bg-destructive/10 text-destructive',
}[m] || 'bg-muted text-muted-foreground');

function copyBase() {
    navigator.clipboard?.writeText(props.baseUrl);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1500);
}

// Charge Swagger UI (CSS + JS depuis le CDN) une seule fois, à la première ouverture de l'onglet.
function loadSwagger() {
    if (swaggerLoaded.value) return;
    swaggerLoaded.value = true;

    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = `https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/${SWAGGER_VERSION}/swagger-ui.min.css`;
    document.head.appendChild(css);

    const script = document.createElement('script');
    script.src = `https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/${SWAGGER_VERSION}/swagger-ui-bundle.min.js`;
    script.onload = () => {
        // eslint-disable-next-line no-undef
        window.SwaggerUIBundle({
            url: props.openapiUrl,
            domNode: document.getElementById('swagger-ui'),
            deepLinking: true,
            defaultModelsExpandDepth: -1,
        });
    };
    document.body.appendChild(script);
}

function openSwagger() {
    tab.value = 'swagger';
    nextTick(loadSwagger);
}

onMounted(() => {
    // Rien : Swagger ne se charge qu'à la demande (onglet Explorateur).
});
</script>

<template>
    <AdminLayout current="api">
        <template #title>{{ t('apiDoc.title') }}</template>

        <!-- Onglets + téléchargements -->
        <div class="flex items-center gap-2 mb-4 flex-wrap">
            <button
                type="button" @click="tab = 'guide'"
                class="px-4 py-2 rounded-full text-sm font-medium transition-colors"
                :class="tab === 'guide' ? 'bg-primary text-primary-foreground' : 'bg-white border border-[#E4DFCE] text-muted-foreground hover:text-[#002C52]'"
            >
                <BookOpen class="size-4 inline -mt-0.5 mr-1" /> {{ t('apiDoc.tabs.guide') }}
            </button>
            <button
                type="button" @click="openSwagger"
                class="px-4 py-2 rounded-full text-sm font-medium transition-colors"
                :class="tab === 'swagger' ? 'bg-primary text-primary-foreground' : 'bg-white border border-[#E4DFCE] text-muted-foreground hover:text-[#002C52]'"
            >
                <Server class="size-4 inline -mt-0.5 mr-1" /> {{ t('apiDoc.tabs.explorer') }}
            </button>

            <!-- Téléchargement des spécifications OpenAPI -->
            <div class="ml-auto flex items-center gap-2">
                <a
                    href="/admin/api/download/gateway"
                    class="px-3 py-2 rounded-full text-sm font-medium bg-white border border-[#E4DFCE] text-[#002C52] hover:border-primary transition-colors inline-flex items-center gap-1.5"
                    :title="t('apiDoc.download.gatewayTitle')"
                >
                    <Download class="size-4" /> {{ t('apiDoc.download.gateway') }}
                </a>
                <a
                    href="/admin/api/download/ebp"
                    class="px-3 py-2 rounded-full text-sm font-medium bg-white border border-[#E4DFCE] text-[#002C52] hover:border-primary transition-colors inline-flex items-center gap-1.5"
                    :title="t('apiDoc.download.ebpTitle')"
                >
                    <Download class="size-4" /> {{ t('apiDoc.download.ebp') }}
                </a>
            </div>
        </div>

        <!-- GUIDE -->
        <div v-show="tab === 'guide'" class="space-y-4">
            <!-- Principe -->
            <CutCard>
                <div class="p-5">
                    <h3 class="text-base font-medium text-[#002C52] mb-2">{{ t('apiDoc.principle.title') }}</h3>
                    <p class="text-sm text-muted-foreground leading-relaxed">
                        {{ t('apiDoc.principle.p1') }}
                    </p>
                    <p class="text-sm text-muted-foreground leading-relaxed mt-2" v-html="t('apiDoc.principle.p2')" />
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-muted-foreground">{{ t('apiDoc.principle.relayedPrefixes') }}</span>
                        <code v-for="p in proxyPrefixes" :key="p" class="px-2 py-0.5 rounded-md bg-[#F6F2E4] text-[#002C52] font-mono text-xs">{{ p }}</code>
                        <span v-if="!proxyPrefixes.length" class="text-muted-foreground text-xs">{{ t('apiDoc.principle.noPrefix') }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground mt-2" v-html="t('apiDoc.principle.otherModules')" />
                    <div class="mt-4 flex items-center gap-2 text-sm">
                        <span class="text-muted-foreground">{{ t('apiDoc.principle.baseUrl') }}</span>
                        <code class="px-2 py-1 rounded-lg bg-[#F6F2E4] text-[#002C52] font-mono text-xs">{{ baseUrl }}</code>
                        <button type="button" @click="copyBase" class="text-muted-foreground hover:text-[#002C52]" :aria-label="t('apiDoc.principle.copyBaseUrl')">
                            <Check v-if="copied" class="size-4 text-[#1D9E75]" />
                            <Copy v-else class="size-4" />
                        </button>
                    </div>
                </div>
            </CutCard>

            <!-- Authentification -->
            <CutCard>
                <div class="p-5">
                    <h3 class="text-base font-medium text-[#002C52] mb-2 flex items-center gap-2">
                        <KeyRound class="size-4" /> {{ t('apiDoc.auth.title') }}
                    </h3>
                    <p class="text-sm text-muted-foreground leading-relaxed mb-3" v-html="t('apiDoc.auth.description')" />
                    <pre class="rounded-xl bg-[#002C52] text-[#E8EEF6] p-4 text-xs overflow-x-auto"><code>curl {{ baseUrl }}/gescom/api/v1/Clients \
  -H "Authorization: Bearer gw_your_token"</code></pre>
                </div>
            </CutCard>

            <!-- Endpoints -->
            <CutCard v-for="group in groups" :key="group.title">
                <div class="p-5">
                    <h3 class="text-base font-medium text-[#002C52]">{{ group.title }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5 mb-3">{{ group.description }}</p>
                    <div class="space-y-1.5">
                        <div
                            v-for="ep in group.endpoints" :key="ep.method + ep.path"
                            class="flex items-center gap-3 text-sm rounded-xl bg-[#F6F2E4] px-3 py-2.5"
                        >
                            <span class="shrink-0 w-14 text-center px-2 py-0.5 rounded-md text-[11px] font-semibold font-mono" :class="methodClass(ep.method)">
                                {{ ep.method }}
                            </span>
                            <code class="font-mono text-[#002C52] text-[13px]">{{ ep.path }}</code>
                            <span class="text-muted-foreground text-xs ml-2 truncate">{{ ep.summary }}</span>
                            <Badge v-if="!ep.auth" variant="outline" class="ml-auto shrink-0">{{ t('apiDoc.public') }}</Badge>
                        </div>
                    </div>
                </div>
            </CutCard>

            <p class="text-xs text-muted-foreground px-1" v-html="t('apiDoc.fullSpec', { url: openapiUrl })" />
        </div>

        <!-- SWAGGER -->
        <div v-show="tab === 'swagger'">
            <CutCard>
                <div class="p-2">
                    <div id="swagger-ui"></div>
                    <p v-if="!swaggerLoaded" class="p-5 text-sm text-muted-foreground">{{ t('apiDoc.loadingExplorer') }}</p>
                </div>
            </CutCard>
        </div>
    </AdminLayout>
</template>

<style>
/* Cadre Swagger UI dans la carte (il fournit ses propres styles). */
#swagger-ui .topbar { display: none; }
#swagger-ui .info { margin: 16px 0; }
</style>
