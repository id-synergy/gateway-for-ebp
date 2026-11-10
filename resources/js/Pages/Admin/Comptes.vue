<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import CutCard from '@/components/CutCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plug, FolderOpen, Plus, ArrowRight } from 'lucide-vue-next';

defineProps({ accounts: { type: Array, default: () => [] } });

const showForm = ref(false);
const form = useForm({
    label: '', client_id: '', client_secret: '',
    subscription_key_primary: '', subscription_key_secondary: '',
    environment: 'production',
});

function submit() {
    form.post('/admin/comptes', { onSuccess: () => { form.reset(); showForm.value = false; } });
}

const stateBadge = {
    healthy: { text: 'Sain', variant: 'default' },
    expiring: { text: 'Expire bientôt', variant: 'secondary' },
    broken: { text: 'Rompu', variant: 'destructive' },
};

function connect(id) {
    window.location.href = `/oauth/ebp/connect?account=${id}`;
}
</script>

<template>
    <AdminLayout current="comptes">
        <template #title>Comptes EBP</template>
        <template #actions>
            <Button size="lg" @click="showForm = !showForm"><Plus class="size-5" /> Ajouter un compte EBP</Button>
        </template>

        <!-- Formulaire de création -->
        <CutCard v-if="showForm" class="mb-4">
            <div class="p-5 space-y-4">
                <h3 class="text-base font-medium text-[#002C52]">Nouveau compte EBP</h3>
                <p class="text-xs text-muted-foreground -mt-2">
                    Saisissez les identifiants reçus d'EBP (ClientId/Secret par mail, clé d'abonnement depuis le profil développeur). Vous connecterez ensuite le compte via OAuth.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <Label>Libellé</Label>
                        <Input v-model="form.label" placeholder="Société — prod" />
                        <p v-if="form.errors.label" class="text-xs text-destructive">{{ form.errors.label }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>Environnement EBP</Label>
                        <select v-model="form.environment" class="gw-select w-full h-11 rounded-xl border border-input bg-white px-4 text-sm">
                            <option value="production">Production</option>
                            <option value="preproduction">Préproduction</option>
                            <option value="demo">Démo (serveur simulé)</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <Label>ClientId</Label>
                        <Input v-model="form.client_id" placeholder="reçu par mail EBP" />
                        <p v-if="form.errors.client_id" class="text-xs text-destructive">{{ form.errors.client_id }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>ClientSecret</Label>
                        <Input v-model="form.client_secret" type="password" placeholder="reçu par mail EBP" />
                        <p v-if="form.errors.client_secret" class="text-xs text-destructive">{{ form.errors.client_secret }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>Clé d'abonnement primaire</Label>
                        <Input v-model="form.subscription_key_primary" type="password" placeholder="profil développeur" />
                        <p v-if="form.errors.subscription_key_primary" class="text-xs text-destructive">{{ form.errors.subscription_key_primary }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label>Clé secondaire (optionnel)</Label>
                        <Input v-model="form.subscription_key_secondary" type="password" placeholder="secours / rotation" />
                    </div>
                </div>
                <Button :disabled="form.processing" @click="submit">Créer le compte</Button>
            </div>
        </CutCard>

        <div class="space-y-4">
            <CutCard v-if="!accounts.length">
                <EmptyState :icon="Plug" title="Aucun compte EBP connecté"
                    text="Après avoir créé le compte EBP en base (ClientId, ClientSecret, clé d'abonnement), connectez-le pour initier le flux OAuth." />
            </CutCard>
            <CutCard v-for="a in accounts" :key="a.label" corner="tl" :badge="false">
                <div class="p-5">
                    <div class="flex items-center justify-between mb-5">
                        <div class="flex items-center gap-3">
                            <div class="size-10 rounded-full bg-primary text-primary-foreground grid place-items-center"><Plug class="size-5" /></div>
                            <div>
                                <div class="text-base font-medium text-[#002C52]">{{ a.label }}</div>
                                <div class="text-xs text-muted-foreground">Clé d'abonnement : {{ a.key }}</div>
                            </div>
                        </div>
                        <Badge :variant="(stateBadge[a.state] || stateBadge.broken).variant">{{ (stateBadge[a.state] || stateBadge.broken).text }}</Badge>
                    </div>

                    <div class="grid grid-cols-2 gap-4 text-sm mb-5">
                        <div class="rounded-xl bg-[#F6F2E4] px-4 py-3">
                            <div class="text-muted-foreground text-xs mb-0.5">Expiration du refresh token</div>
                            <div class="font-medium text-[#002C52]">{{ a.expires }}</div>
                        </div>
                        <div class="rounded-xl bg-[#F6F2E4] px-4 py-3">
                            <div class="text-muted-foreground text-xs mb-0.5">Dernier renouvellement</div>
                            <div class="font-medium text-[#002C52]">{{ a.lastRefresh }}</div>
                        </div>
                    </div>

                    <div class="text-xs text-muted-foreground mb-2">Dossiers</div>
                    <div class="space-y-1.5">
                        <div v-for="f in a.folders" :key="f.id" class="flex items-center gap-2.5 text-sm rounded-xl bg-primary/5 border border-primary/10 px-3 py-2.5">
                            <span class="size-7 rounded-full bg-primary/10 text-primary grid place-items-center shrink-0"><FolderOpen class="size-3.5" /></span>
                            <span class="font-medium text-[#002C52]">{{ f.label }}</span>
                            <span class="text-muted-foreground">#{{ f.id }}</span>
                            <Badge variant="outline" class="ml-auto">{{ f.short }}</Badge>
                        </div>
                    </div>

                    <div class="flex gap-2 mt-5">
                        <Button variant="outline" size="sm" @click="connect(a.id)">Reconnecter</Button>
                        <Link :href="`/admin/comptes/${a.id}`">
                            <Button variant="outline" size="sm">Voir le détail <ArrowRight class="size-3.5" /></Button>
                        </Link>
                    </div>
                </div>
            </CutCard>
        </div>
    </AdminLayout>
</template>
