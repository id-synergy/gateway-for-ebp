<script setup>
import { router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { Plus, Trash2 } from 'lucide-vue-next';

defineProps({ ips: { type: Array, default: () => [] } });

const form = useForm({ ip: '', label: '' });

function submit() {
    form.post('/admin/ip', { onSuccess: () => form.reset() });
}
function remove(id) {
    if (confirm('Supprimer cette IP ? Elle sera de nouveau soumise au rate-limit.')) {
        router.delete(`/admin/ip/${id}`);
    }
}
</script>

<template>
    <AdminLayout current="ip">
        <template #title>IP autorisées</template>

        <CutCard class="mb-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-4">Autoriser une adresse IP</h3>
                <div class="flex items-end gap-3">
                    <div class="flex-1">
                        <label class="text-xs text-muted-foreground mb-1 block">Adresse IP</label>
                        <Input v-model="form.ip" placeholder="203.0.113.10" />
                        <p v-if="form.errors.ip" class="text-xs text-destructive mt-1">{{ form.errors.ip }}</p>
                    </div>
                    <div class="flex-1">
                        <label class="text-xs text-muted-foreground mb-1 block">Libellé</label>
                        <Input v-model="form.label" placeholder="Nouveau site" />
                        <p v-if="form.errors.label" class="text-xs text-destructive mt-1">{{ form.errors.label }}</p>
                    </div>
                    <Button :disabled="form.processing" @click="submit"><Plus class="size-4" /> Ajouter</Button>
                </div>
                <p class="text-xs text-muted-foreground mt-3">
                    Les IP listées bénéficient d'un débit large. Toute autre IP est fortement bridée (429 au dépassement).
                </p>
            </div>
        </CutCard>

        <CutCard>
            <div class="p-5">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Adresse IP</TableHead>
                            <TableHead>Libellé</TableHead>
                            <TableHead>Ajoutée par</TableHead>
                            <TableHead>Le</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="!ips.length"><TableCell colspan="5" class="text-muted-foreground text-center py-8">Aucune IP autorisée. Ajoutez l'IP du client ci-dessus pour lui donner un débit illimité.</TableCell></TableRow>
                        <TableRow v-for="i in ips" :key="i.id">
                            <TableCell class="font-mono text-sm">{{ i.ip }}</TableCell>
                            <TableCell>{{ i.label }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ i.by }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ i.at }}</TableCell>
                            <TableCell class="text-right">
                                <Button variant="ghost" size="sm" class="text-destructive" @click="remove(i.id)"><Trash2 class="size-3.5" /></Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </CutCard>
    </AdminLayout>
</template>
