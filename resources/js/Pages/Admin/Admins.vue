<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
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
import { Plus, ShieldCheck, Mail } from 'lucide-vue-next';

const props = defineProps({
    admins: { type: Array, default: () => [] },
    preferences: { type: Array, default: () => [] },
});

const showForm = ref(false);
const form = useForm({ name: '', email: '', password: '', is_default_contact: false });

function submit() {
    form.post('/admin/admins', { onSuccess: () => { form.reset(); showForm.value = false; } });
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
        <template #actions><Button size="lg" @click="showForm = !showForm"><Plus class="size-5" /> Ajouter un admin</Button></template>

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
                        <div class="font-medium text-[#002C52] truncate">{{ u.name }}</div>
                        <div class="text-xs text-muted-foreground flex items-center gap-1 truncate">
                            <Mail class="size-3 shrink-0" /> {{ u.email }}
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
                    <Button variant="outline" size="sm" @click="toggle(u)">{{ u.active ? 'Désactiver' : 'Réactiver' }}</Button>
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
