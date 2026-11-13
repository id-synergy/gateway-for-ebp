<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CutCard from '@/components/CutCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
    Plus, Trash2, ShieldCheck, ShieldBan, Pencil, Check, X,
} from 'lucide-vue-next';

defineProps({
    allowed: { type: Array, default: () => [] },
    blocked: { type: Array, default: () => [] },
    seen: { type: Array, default: () => [] },
});

// Ajout manuel : par défaut on autorise, bouton pour basculer en blocage.
const form = useForm({ ip: '', label: '', status: 'allowed' });

function submit() {
    form.post('/admin/ip', { preserveScroll: true, onSuccess: () => form.reset() });
}

// Actions rapides sur une entrée déjà existante (id connu).
function setStatus(id, status) {
    router.patch(`/admin/ip/${id}`, { status }, { preserveScroll: true });
}
function remove(id) {
    if (confirm('Retirer cette IP ? Son libellé est conservé si elle en a un.')) {
        router.delete(`/admin/ip/${id}`, { preserveScroll: true });
    }
}

// Actions rapides sur une IP vue dans les logs mais pas encore classée (pas d'id) :
// on upsert via POST /admin/ip. Si elle est déjà classée, on a son id.
function classify(row, status) {
    if (row.id) {
        setStatus(row.id, status);
    } else {
        router.post('/admin/ip', { ip: row.ip, status, label: row.label }, { preserveScroll: true });
    }
}

// Édition de libellé inline (liste blanche, liste noire, IP vues).
const editingId = ref(null);          // id d'entrée en cours d'édition
const editingSeenIp = ref(null);      // IP vue sans entrée, en cours de nommage
const labelDraft = ref('');

function startEditEntry(row) {
    editingId.value = row.id;
    editingSeenIp.value = null;
    labelDraft.value = row.label ?? '';
}
function startEditSeen(row) {
    if (row.id) { startEditEntry(row); return; }
    editingSeenIp.value = row.ip;
    editingId.value = null;
    labelDraft.value = row.label ?? '';
}
function cancelEdit() {
    editingId.value = null;
    editingSeenIp.value = null;
    labelDraft.value = '';
}
function saveEntryLabel(row) {
    router.patch(`/admin/ip/${row.id}`, { label: labelDraft.value }, {
        preserveScroll: true, onSuccess: cancelEdit,
    });
}
function saveSeenLabel(row) {
    // Nommer une IP vue non classée : crée une entrée neutral avec le libellé.
    router.post('/admin/ip', { ip: row.ip, status: 'neutral', label: labelDraft.value }, {
        preserveScroll: true, onSuccess: cancelEdit,
    });
}

const statusLabel = { allowed: 'Autorisée', blocked: 'Bloquée', neutral: 'Non classée' };
const statusClass = {
    allowed: 'text-emerald-700 bg-emerald-50',
    blocked: 'text-destructive bg-destructive/10',
    neutral: 'text-muted-foreground bg-muted',
};
</script>

<template>
    <AdminLayout current="ip">
        <template #title>IP</template>

        <!-- Ajout manuel d'une IP (autoriser ou bloquer directement). -->
        <CutCard class="mb-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-4">Classer une adresse IP</h3>
                <div class="flex items-end gap-3 flex-wrap">
                    <div class="flex-1 min-w-[180px]">
                        <label class="text-xs text-muted-foreground mb-1 block">Adresse IP</label>
                        <Input v-model="form.ip" placeholder="203.0.113.10" />
                        <p v-if="form.errors.ip" class="text-xs text-destructive mt-1">{{ form.errors.ip }}</p>
                    </div>
                    <div class="flex-1 min-w-[180px]">
                        <label class="text-xs text-muted-foreground mb-1 block">Libellé (facultatif)</label>
                        <Input v-model="form.label" placeholder="Nouveau site" />
                        <p v-if="form.errors.label" class="text-xs text-destructive mt-1">{{ form.errors.label }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button :disabled="form.processing" @click="form.status = 'allowed'; submit()">
                            <ShieldCheck class="size-4" /> Autoriser
                        </Button>
                        <Button variant="outline" :disabled="form.processing" class="text-destructive border-destructive/40" @click="form.status = 'blocked'; submit()">
                            <ShieldBan class="size-4" /> Bloquer
                        </Button>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground mt-3">
                    Autorisée : débit illimité (exemptée du rate-limit). Bloquée : rejetée en 403.
                    Toute autre IP est fortement bridée (429 au dépassement). Les boucles locales ne sont jamais bloquées.
                </p>
            </div>
        </CutCard>

        <!-- Liste blanche. -->
        <CutCard class="mb-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-4 flex items-center gap-2">
                    <ShieldCheck class="size-4 text-emerald-700" /> IP autorisées
                </h3>
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
                        <TableRow v-if="!allowed.length"><TableCell colspan="5" class="text-muted-foreground text-center py-8">Aucune IP autorisée.</TableCell></TableRow>
                        <TableRow v-for="i in allowed" :key="i.id">
                            <TableCell class="font-mono text-sm">{{ i.ip }}</TableCell>
                            <TableCell>
                                <div v-if="editingId === i.id" class="flex items-center gap-1">
                                    <Input v-model="labelDraft" class="h-8" @keyup.enter="saveEntryLabel(i)" />
                                    <Button variant="ghost" size="sm" @click="saveEntryLabel(i)"><Check class="size-3.5" /></Button>
                                    <Button variant="ghost" size="sm" @click="cancelEdit"><X class="size-3.5" /></Button>
                                </div>
                                <span v-else class="inline-flex items-center gap-1.5">
                                    {{ i.label || '—' }}
                                    <Button variant="ghost" size="sm" class="text-muted-foreground" @click="startEditEntry(i)"><Pencil class="size-3" /></Button>
                                </span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">{{ i.by }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ i.at }}</TableCell>
                            <TableCell class="text-right whitespace-nowrap">
                                <Button variant="ghost" size="sm" class="text-destructive" title="Bloquer" @click="setStatus(i.id, 'blocked')"><ShieldBan class="size-3.5" /></Button>
                                <Button variant="ghost" size="sm" class="text-destructive" title="Retirer" @click="remove(i.id)"><Trash2 class="size-3.5" /></Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </CutCard>

        <!-- Liste noire. -->
        <CutCard class="mb-4">
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-4 flex items-center gap-2">
                    <ShieldBan class="size-4 text-destructive" /> IP bloquées
                </h3>
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
                        <TableRow v-if="!blocked.length"><TableCell colspan="5" class="text-muted-foreground text-center py-8">Aucune IP bloquée.</TableCell></TableRow>
                        <TableRow v-for="i in blocked" :key="i.id">
                            <TableCell class="font-mono text-sm">{{ i.ip }}</TableCell>
                            <TableCell>
                                <div v-if="editingId === i.id" class="flex items-center gap-1">
                                    <Input v-model="labelDraft" class="h-8" @keyup.enter="saveEntryLabel(i)" />
                                    <Button variant="ghost" size="sm" @click="saveEntryLabel(i)"><Check class="size-3.5" /></Button>
                                    <Button variant="ghost" size="sm" @click="cancelEdit"><X class="size-3.5" /></Button>
                                </div>
                                <span v-else class="inline-flex items-center gap-1.5">
                                    {{ i.label || '—' }}
                                    <Button variant="ghost" size="sm" class="text-muted-foreground" @click="startEditEntry(i)"><Pencil class="size-3" /></Button>
                                </span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">{{ i.by }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ i.at }}</TableCell>
                            <TableCell class="text-right whitespace-nowrap">
                                <Button variant="ghost" size="sm" class="text-emerald-700" title="Débloquer (autoriser)" @click="setStatus(i.id, 'allowed')"><ShieldCheck class="size-3.5" /></Button>
                                <Button variant="ghost" size="sm" class="text-muted-foreground" title="Débloquer (neutre)" @click="setStatus(i.id, 'neutral')"><X class="size-3.5" /></Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </CutCard>

        <!-- IP vues dans les logs. -->
        <CutCard>
            <div class="p-5">
                <h3 class="text-base font-medium text-[#002C52] mb-1">IP vues</h3>
                <p class="text-xs text-muted-foreground mb-4">Adresses observées dans les logs de requêtes. Nommez-les, autorisez-les ou bloquez-les d'un clic.</p>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Adresse IP</TableHead>
                            <TableHead>Statut</TableHead>
                            <TableHead>Libellé</TableHead>
                            <TableHead>App</TableHead>
                            <TableHead class="text-right">Appels</TableHead>
                            <TableHead>Première vue</TableHead>
                            <TableHead>Dernière vue</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="!seen.length"><TableCell colspan="8" class="text-muted-foreground text-center py-8">Aucune IP dans les logs pour l'instant.</TableCell></TableRow>
                        <TableRow v-for="r in seen" :key="r.ip">
                            <TableCell class="font-mono text-sm">{{ r.ip }}</TableCell>
                            <TableCell>
                                <span class="text-xs px-2 py-0.5 rounded-full" :class="statusClass[r.status]">{{ statusLabel[r.status] }}</span>
                            </TableCell>
                            <TableCell>
                                <div v-if="editingSeenIp === r.ip || (r.id && editingId === r.id)" class="flex items-center gap-1">
                                    <Input v-model="labelDraft" class="h-8" @keyup.enter="r.id ? saveEntryLabel(r) : saveSeenLabel(r)" />
                                    <Button variant="ghost" size="sm" @click="r.id ? saveEntryLabel(r) : saveSeenLabel(r)"><Check class="size-3.5" /></Button>
                                    <Button variant="ghost" size="sm" @click="cancelEdit"><X class="size-3.5" /></Button>
                                </div>
                                <span v-else class="inline-flex items-center gap-1.5">
                                    {{ r.label || '—' }}
                                    <Button variant="ghost" size="sm" class="text-muted-foreground" title="Nommer" @click="startEditSeen(r)"><Pencil class="size-3" /></Button>
                                </span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">{{ r.app || '—' }}</TableCell>
                            <TableCell class="text-right font-mono text-sm">{{ r.hits }}</TableCell>
                            <TableCell class="text-muted-foreground text-sm">{{ r.firstSeen }}</TableCell>
                            <TableCell class="text-muted-foreground text-sm">{{ r.lastSeen }}</TableCell>
                            <TableCell class="text-right whitespace-nowrap">
                                <Button v-if="r.status !== 'allowed'" variant="ghost" size="sm" class="text-emerald-700" title="Autoriser" @click="classify(r, 'allowed')"><ShieldCheck class="size-3.5" /></Button>
                                <Button v-if="r.status !== 'blocked'" variant="ghost" size="sm" class="text-destructive" title="Bloquer" @click="classify(r, 'blocked')"><ShieldBan class="size-3.5" /></Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </CutCard>
    </AdminLayout>
</template>
