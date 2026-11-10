<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard, Plug, KeyRound, Users, Network, ScrollText, ListChecks, LogOut, Activity, BookOpen,
} from 'lucide-vue-next';
import {
    Avatar, AvatarFallback,
} from '@/components/ui/avatar';
import GatewayLogo from '@/components/GatewayLogo.vue';
import Toasts from '@/components/Toasts.vue';

// Maquette statique : navigation en dur, pas de logique d'auth.
const nav = [
    { label: 'Tableau de bord', icon: LayoutDashboard, href: '/admin', key: 'dashboard' },
    { label: 'Comptes EBP', icon: Plug, href: '/admin/comptes', key: 'comptes' },
    { label: "Jetons d'apps", icon: KeyRound, href: '/admin/jetons', key: 'jetons' },
    { label: 'Admins', icon: Users, href: '/admin/admins', key: 'admins' },
    { label: 'IP autorisées', icon: Network, href: '/admin/ip', key: 'ip' },
    { label: 'Logs', icon: ScrollText, href: '/admin/logs', key: 'logs' },
    { label: 'Jobs', icon: ListChecks, href: '/admin/jobs', key: 'jobs' },
    { label: 'Supervision', icon: Activity, href: '/admin/supervision', key: 'supervision' },
    { label: 'API & doc', icon: BookOpen, href: '/admin/api', key: 'api' },
];

defineProps({ current: { type: String, default: '' } });

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name ?? 'Admin');
const initials = computed(() => userName.value.split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase());

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground flex">
        <aside class="w-72 shrink-0 border-r border-border bg-sidebar flex flex-col">
            <div class="h-[72px] flex items-center gap-3 px-5 border-b border-sidebar-border">
                <GatewayLogo :size="40" :rounded="11" />
                <div class="leading-tight">
                    <div class="font-medium text-[15px] text-sidebar-foreground">Gateway</div>
                    <div class="text-xs text-sidebar-foreground/60">EBP · passerelle</div>
                </div>
            </div>
            <nav class="flex-1 p-4 space-y-1.5">
                <Link
                    v-for="item in nav" :key="item.key" :href="item.href"
                    class="flex items-center gap-3 rounded-xl px-4 py-3 text-[15px] transition-colors"
                    :class="current === item.key
                        ? 'bg-primary text-primary-foreground font-medium'
                        : 'text-sidebar-foreground/85 hover:bg-white/10 hover:text-white'"
                >
                    <component :is="item.icon" class="size-5" />
                    {{ item.label }}
                </Link>
            </nav>
            <div class="p-3 border-t border-sidebar-border flex items-center gap-1">
                <Link href="/admin/profil" class="flex items-center gap-3 flex-1 min-w-0 rounded-xl hover:bg-white/10 px-3 py-2 transition-colors">
                    <Avatar class="size-8 shrink-0"><AvatarFallback class="bg-sidebar-accent text-sidebar-accent-foreground">{{ initials }}</AvatarFallback></Avatar>
                    <div class="flex-1 leading-tight min-w-0">
                        <div class="text-sm font-medium text-sidebar-foreground truncate">{{ userName }}</div>
                        <div class="text-xs text-sidebar-foreground/60">Voir mon profil</div>
                    </div>
                </Link>
                <button type="button" aria-label="Se déconnecter" @click="logout"
                        class="shrink-0 size-9 rounded-xl grid place-items-center text-sidebar-foreground/60 hover:bg-white/10 hover:text-sidebar-foreground transition-colors">
                    <LogOut class="size-4" />
                </button>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0">
            <header class="h-[72px] border-b border-border flex items-center px-8">
                <h1 class="text-xl font-medium text-[#002C52] dark:text-foreground"><slot name="title">Gateway EBP</slot></h1>
                <div class="ml-auto"><slot name="actions" /></div>
            </header>
            <main class="flex-1 p-8 max-w-6xl w-full">
                <slot />
            </main>
        </div>
        <Toasts />
    </div>
</template>
