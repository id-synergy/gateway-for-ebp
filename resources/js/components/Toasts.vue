<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, AlertTriangle, X } from 'lucide-vue-next';

const page = usePage();
const toasts = ref([]);
let id = 0;

function push(type, message) {
    const t = { id: ++id, type, message };
    toasts.value.push(t);
    setTimeout(() => dismiss(t.id), 4000);
}
function dismiss(tid) {
    toasts.value = toasts.value.filter((t) => t.id !== tid);
}

watch(() => page.props.flash, (flash) => {
    if (flash?.success) push('success', flash.success);
    if (flash?.error) push('error', flash.error);
}, { deep: true, immediate: true });
</script>

<template>
    <div class="fixed bottom-6 right-6 z-50 flex flex-col gap-2 w-80">
        <transition-group name="toast">
            <div v-for="t in toasts" :key="t.id"
                 class="flex items-start gap-3 rounded-2xl bg-white border border-[#E4DFCE] shadow-lg p-4"
                 :class="t.type === 'error' ? 'border-l-4 border-l-destructive' : 'border-l-4 border-l-[#1D9E75]'">
                <component :is="t.type === 'error' ? AlertTriangle : CheckCircle2" class="size-5 shrink-0 mt-0.5"
                           :class="t.type === 'error' ? 'text-destructive' : 'text-[#1D9E75]'" />
                <p class="text-sm text-[#002C52] flex-1">{{ t.message }}</p>
                <button type="button" aria-label="Fermer" @click="dismiss(t.id)"><X class="size-4 text-muted-foreground hover:text-foreground" /></button>
            </div>
        </transition-group>
    </div>
</template>

<style scoped>
.toast-enter-active, .toast-leave-active { transition: all .25s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(20px); }
</style>
