<script setup>
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';

const { t } = useI18n();

const form = useForm({ code: '', recovery_code: '' });

function submit() {
    form.post('/two-factor-challenge');
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground grid place-items-center p-4">
        <Card class="w-full max-w-sm">
            <CardHeader class="text-center">
                <CardTitle>{{ t('auth.twoFactor.title') }}</CardTitle>
                <CardDescription>{{ t('auth.twoFactor.description') }}</CardDescription>
            </CardHeader>
            <CardContent>
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="code">{{ t('auth.twoFactor.codeLabel') }}</Label>
                        <Input id="code" v-model="form.code" inputmode="numeric" placeholder="000000" class="text-center tracking-[0.5em] text-lg" />
                        <p v-if="form.errors.code" class="text-xs text-destructive">{{ form.errors.code }}</p>
                    </div>
                    <Button type="submit" class="w-full" :disabled="form.processing">{{ t('auth.twoFactor.submit') }}</Button>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
