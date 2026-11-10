<script setup>
import { ref, computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import { KeyRound } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { Plus, Copy } from 'lucide-vue-next';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
});

const page = usePage();
const showForm = ref(false);

const form = useForm({ name: '', ebp_account_id: props.accounts[0]?.id ?? null, default_folder_id: null });

const currentFolders = computed(() => props.accounts.find((a) => a.id === form.ebp_account_id)?.folders ?? []);

function submit() {
    form.post('/admin/jetons', { onSuccess: () => { form.reset(); showForm.value = false; } });
}
function revoke(id) {
    if (confirm('Révoquer ce jeton ? Les apps qui l\'utilisent perdront l\'accès.')) {
        router.delete(`/admin/jetons/${id}`);
    }
}
</script>

<template>
    <AdminLayout current="jetons">
        <template #title>Jetons d'apps</template>
        <template #actions>
            <Button size="lg" @click="showForm = !showForm"><Plus class="size-5" /> Créer un jeton</Button>
        </template>

        <!-- Jeton fraîchement créé (montré une seule fois) -->
        <CutCard v-if="page.props.flash?.newToken" class="mb-4 border-2 border-primary">
            <div class="p-5">
                <p class="text-sm font-medium text-[#002C52] mb-2">Nouveau jeton (copiez-le maintenant, il ne sera plus affiché) :</p>
                <div class="flex items-center gap-2">
                    <code class="flex-1 text-xs bg-muted rounded-lg px-3 py-2.5 break-all">{{ page.props.flash.newToken }}</code>
                    <Button variant="outline" size="icon" aria-label="Copier" @click="navigator.clipboard.writeText(page.props.flash.newToken)"><Copy class="size-4" /></Button>
                </div>
            </div>
        </CutCard>

        <!-- Formulaire de création -->
        <CutCard v-if="showForm" class="mb-4">
            <div class="p-5 space-y-4">
                <h3 class="text-base font-medium text-[#002C52]">Nouveau jeton d'application</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <Label>Nom de l'application</Label>
                        <Input v-model="form.name" placeholder="Logiciel tickets" />
                        <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>Compte EBP</Label>
                        <select v-model="form.ebp_account_id" class="gw-select w-full h-11 rounded-xl border border-input bg-background px-4 text-sm">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label>Dossier par défaut (optionnel)</Label>
                        <select v-model="form.default_folder_id" class="gw-select w-full h-11 rounded-xl border border-input bg-background px-4 text-sm">
                            <option :value="null">— à préciser dans chaque requête</option>
                            <option v-for="f in currentFolders" :key="f.id" :value="f.id">{{ f.label }}</option>
                        </select>
                        <p class="text-xs text-muted-foreground">Sinon l'app devra passer folderId à chaque appel.</p>
                    </div>
                </div>
                <Button :disabled="form.processing" @click="submit">Créer le jeton</Button>
            </div>
        </CutCard>

        <CutCard>
            <EmptyState v-if="!tokens.length" :icon="KeyRound" title="Aucun jeton d'application"
                text="Créez un jeton pour qu'une application (impression de tickets, pilotage…) puisse accéder à EBP via la gateway.">
                <Button size="lg" @click="showForm = true"><Plus class="size-5" /> Créer un jeton</Button>
            </EmptyState>
            <div v-else class="p-5">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Application</TableHead>
                            <TableHead>Compte EBP</TableHead>
                            <TableHead>Dossier par défaut</TableHead>
                            <TableHead>Dernière utilisation</TableHead>
                            <TableHead>État</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="t in tokens" :key="t.id">
                            <TableCell class="font-medium">{{ t.name }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ t.account }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ t.folder }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ t.lastUsed }}</TableCell>
                            <TableCell><Badge :variant="t.active ? 'default' : 'secondary'">{{ t.active ? 'Actif' : 'Révoqué' }}</Badge></TableCell>
                            <TableCell class="text-right">
                                <Button v-if="t.active" variant="ghost" size="sm" class="text-destructive" @click="revoke(t.id)">Révoquer</Button>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </CutCard>
    </AdminLayout>
</template>
