<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { Plus, ShieldCheck, Mail, Send, Clock, Trash2, ArrowRight } from 'lucide-vue-next';

const props = defineProps({
    admins: { type: Array, default: () => [] },
    preferences: { type: Array, default: () => [] },
    invitations: { type: Array, default: () => [] },
});

const showForm = ref(false);
const form = useForm({ name: '', email: '', password: '', is_default_contact: false });

const showInvite = ref(false);
const inviteForm = useForm({ name: '', email: '', is_default_contact: false });

function submit() {
    form.post('/admin/admins', { onSuccess: () => { form.reset(); showForm.value = false; } });
}
function sendInvite() {
    inviteForm.post('/admin/invitations', { preserveScroll: true, onSuccess: () => { inviteForm.reset(); showInvite.value = false; } });
}
function revokeInvite(id) {
    if (confirm('Révoquer cette invitation ? Le lien ne fonctionnera plus.')) {
        router.delete(`/admin/invitations/${id}`, { preserveScroll: true });
    }
}
function toggle(u) {
    router.patch(`/admin/admins/${u.id}/toggle`, {}, { preserveScroll: true });
}
function togglePref(userId, alertType, current) {
    router.post('/admin/preferences', { user_id: userId, alert_type: alertType, enabled: !current }, { preserveScroll: true });
}
function initials(name) {
    return name.split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase();
}
</script>

<template>
    <AdminLayout current="admins">
        <template #title>Admins</template>
        <template #actions>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="lg" @click="showInvite = !showInvite"><Send class="size-4" /> Inviter</Button>
                <Button size="lg" @click="showForm = !showForm"><Plus class="size-5" /> Ajouter un admin</Button>
            </div>
        </template>

        <!-- Formulaire d'invitation -->
        <CutCard v-if="showInvite" class="mb-4">
            <div class="p-5 space-y-4">
                <h3 class="text-base font-medium text-[#002C52] flex items-center gap-2"><Send class="size-4" /> Inviter un administrateur</h3>
                <p class="text-xs text-muted-foreground -mt-2">La personne recevra un lien (valable 24 h) pour créer son compte en choisissant son mot de passe.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <Label>Nom</Label>
                        <Input v-model="inviteForm.name" placeholder="Jean Dupont" />
                        <p v-if="inviteForm.errors.name" class="text-xs text-destructive">{{ inviteForm.errors.name }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>E-mail</Label>
                        <Input v-model="inviteForm.email" type="email" placeholder="jean@example.com" />
                        <p v-if="inviteForm.errors.email" class="text-xs text-destructive">{{ inviteForm.errors.email }}</p>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-muted-foreground">
                    <input v-model="inviteForm.is_default_contact" type="checkbox" class="gw-check"> Contact par défaut pour les alertes
                </label>
                <Button :disabled="inviteForm.processing" @click="sendInvite"><Send class="size-4" /> Envoyer l'invitation</Button>
            </div>
        </CutCard>

        <!-- Invitations en attente -->
        <CutCard v-if="invitations.length" class="mb-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-3 flex items-center gap-2"><Clock class="size-4" /> Invitations en attente</h3>
                <div class="space-y-1.5">
                    <div v-for="inv in invitations" :key="inv.id" class="flex items-center gap-2.5 text-sm rounded-xl bg-[#F6F2E4] px-3 py-2.5">
                        <span class="size-7 rounded-full bg-primary/10 text-primary grid place-items-center shrink-0"><Mail class="size-3.5" /></span>
                        <div class="min-w-0">
                            <div class="font-medium text-[#002C52]">{{ inv.name }}</div>
                            <div class="text-xs text-muted-foreground">{{ inv.email }}</div>
                        </div>
                        <Badge variant="outline" class="ml-auto">Expire {{ inv.expiresIn }}</Badge>
                        <Button variant="ghost" size="icon" class="text-destructive" @click="revokeInvite(inv.id)" aria-label="Révoquer">
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </CutCard>

        <!-- Formulaire de création -->
        <CutCard v-if="showForm" class="mb-4">
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="space-y-2">
                        <Label>Nom</Label>
                        <Input v-model="form.name" placeholder="Jean Dupont" />
                        <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>E-mail</Label>
                        <Input v-model="form.email" type="email" placeholder="jean@example.com" />
                        <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>Mot de passe</Label>
                        <Input v-model="form.password" type="password" placeholder="10 caractères min." />
                        <p v-if="form.errors.password" class="text-xs text-destructive">{{ form.errors.password }}</p>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-muted-foreground">
                    <input v-model="form.is_default_contact" type="checkbox" class="gw-check"> Contact par défaut pour les alertes
                </label>
                <Button :disabled="form.processing" @click="submit">Créer l'admin</Button>
            </div>
        </CutCard>

        <!-- Liste des admins -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <CutCard v-for="u in admins" :key="u.email">
                <div class="p-5 flex items-start gap-3">
                    <div class="size-11 rounded-full bg-primary text-primary-foreground grid place-items-center font-medium shrink-0">
                        {{ initials(u.name) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <Link :href="`/admin/admins/${u.id}`" class="font-medium text-[#002C52] truncate block hover:underline">{{ u.name }}</Link>
                        <div class="text-xs text-muted-foreground flex items-center gap-1 truncate">
                            <Mail class="size-3 shrink-0" /> {{ u.email }}
                        </div>
                        <div class="text-xs text-muted-foreground flex items-center gap-1 truncate mt-0.5">
                            <Clock class="size-3 shrink-0" /> {{ u.lastLoginAt ? `Dernière connexion : ${u.lastLoginAt}` : 'Jamais connecté' }}
                        </div>
                        <div class="flex flex-wrap gap-1.5 mt-2.5">
                            <Badge v-if="u.twoFa" variant="default" class="gap-1"><ShieldCheck class="size-3" /> 2FA</Badge>
                            <Badge v-else variant="secondary">2FA désactivée</Badge>
                            <Badge v-if="u.defaultContact" variant="outline">Contact par défaut</Badge>
                            <Badge :variant="u.active ? 'default' : 'secondary'" :class="u.active ? 'bg-[#1D9E75] hover:bg-[#1D9E75]' : ''">
                                {{ u.active ? 'Actif' : 'Désactivé' }}
                            </Badge>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1.5 shrink-0">
                        <Button variant="outline" size="sm" @click="toggle(u)">{{ u.active ? 'Désactiver' : 'Réactiver' }}</Button>
                        <Link :href="`/admin/admins/${u.id}`" class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-[#002C52]">
                            Détail <ArrowRight class="size-3" />
                        </Link>
                    </div>
                </div>
            </CutCard>
        </div>

        <!-- Matrice de préférences (éditable) -->
        <CutCard class="mt-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-1">Préférences de notification</h3>
                <p class="text-xs text-muted-foreground mb-4">Qui reçoit quelle alerte par mail. Cliquez pour modifier.</p>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Type d'alerte</TableHead>
                            <TableHead v-for="u in admins" :key="u.id" class="text-center">{{ u.name }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="p in preferences" :key="p.type">
                            <TableCell class="font-medium text-[#002C52]">{{ p.label }}</TableCell>
                            <TableCell v-for="u in admins" :key="u.id" class="text-center">
                                <div class="flex justify-center">
                                    <Switch :model-value="!!p.byUser[u.id]" @update:model-value="togglePref(u.id, p.type, !!p.byUser[u.id])" />
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </CutCard>
    </AdminLayout>
</template>
