<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    smtp: {
        smtp_enabled: boolean;
        smtp_host: string;
        smtp_port: number | null;
        smtp_encryption: string;
        smtp_username: string;
        smtp_from_address: string;
        smtp_from_name: string;
        has_password: boolean;
        test_email: string;
    };
    status?: string;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'SMTP settings',
        href: '/settings/smtp',
    },
];

const form = useForm({
    smtp_enabled: props.smtp.smtp_enabled,
    smtp_host: props.smtp.smtp_host,
    smtp_port: props.smtp.smtp_port ? String(props.smtp.smtp_port) : '',
    smtp_encryption: props.smtp.smtp_encryption || 'tls',
    smtp_username: props.smtp.smtp_username,
    smtp_password: '',
    smtp_from_address: props.smtp.smtp_from_address,
    smtp_from_name: props.smtp.smtp_from_name,
    test_email: props.smtp.test_email || '',
});

const smtpPresets = [
    {
        key: 'gmail',
        label: 'Gmail',
        host: 'smtp.gmail.com',
        port: '587',
        encryption: 'tls',
        note: 'Use Google App Password (not your normal account password).',
    },
    {
        key: 'outlook',
        label: 'Outlook / Microsoft 365',
        host: 'smtp.office365.com',
        port: '587',
        encryption: 'tls',
        note: 'Use your mailbox credentials or app password if MFA is enabled.',
    },
    {
        key: 'zoho',
        label: 'Zoho Mail',
        host: 'smtp.zoho.com',
        port: '587',
        encryption: 'tls',
        note: 'Use an app-specific password for better reliability.',
    },
] as const;

const submit = (): void => {
    form.patch('/settings/smtp', {
        preserveScroll: true,
    });
};

const sendTestEmail = (): void => {
    form.post('/settings/smtp/test', {
        preserveScroll: true,
    });
};

const applyPreset = (preset: (typeof smtpPresets)[number]): void => {
    form.smtp_host = preset.host;
    form.smtp_port = preset.port;
    form.smtp_encryption = preset.encryption;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="SMTP settings" />

        <h1 class="sr-only">SMTP settings</h1>

        <SettingsLayout>
            <div class="space-y-6">
                <Heading
                    variant="small"
                    title="Custom SMTP"
                    description="Set your own SMTP account to send webinar emails from your brand inbox"
                />

                <div
                    v-if="status === 'smtp-settings-updated'"
                    class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                >
                    SMTP settings saved.
                </div>

                <div
                    v-if="status === 'smtp-test-sent'"
                    class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                >
                    Test email sent successfully.
                </div>

                <div
                    v-if="status === 'smtp-test-failed'"
                    class="rounded-md border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                >
                    SMTP test failed. Check credentials and try again.
                </div>

                <form class="space-y-6" @submit.prevent="submit">
                    <div class="space-y-2 rounded-md border bg-muted/20 p-3">
                        <p class="text-sm font-medium">Quick SMTP presets</p>
                        <p class="text-xs text-muted-foreground">
                            Pick a provider to auto-fill host, port, and encryption.
                        </p>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <button
                                v-for="preset in smtpPresets"
                                :key="preset.key"
                                type="button"
                                class="rounded-md border bg-background px-3 py-2 text-left transition hover:bg-muted"
                                @click="applyPreset(preset)"
                            >
                                <p class="text-sm font-medium">{{ preset.label }}</p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ preset.host }} : {{ preset.port }} ({{ preset.encryption.toUpperCase() }})
                                </p>
                            </button>
                        </div>
                        <p class="text-[11px] text-muted-foreground">
                            Tip: use app passwords for Gmail/Zoho/Outlook when 2FA is enabled.
                        </p>
                    </div>

                    <label class="flex items-start gap-3 rounded-md border px-3 py-3">
                        <input
                            v-model="form.smtp_enabled"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        <div>
                            <p class="text-sm font-medium">Enable custom SMTP</p>
                            <p class="text-xs text-muted-foreground">
                                When enabled, webinar emails use your SMTP account instead of the app default.
                            </p>
                        </div>
                    </label>
                    <InputError :message="form.errors.smtp_enabled" />

                    <div class="grid gap-2">
                        <Label for="smtp_host">SMTP host</Label>
                        <Input id="smtp_host" v-model="form.smtp_host" placeholder="smtp.gmail.com" />
                        <InputError :message="form.errors.smtp_host" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="smtp_port">Port</Label>
                            <Input id="smtp_port" v-model="form.smtp_port" placeholder="587" />
                            <InputError :message="form.errors.smtp_port" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="smtp_encryption">Encryption</Label>
                            <select
                                id="smtp_encryption"
                                v-model="form.smtp_encryption"
                                class="h-10 rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">None</option>
                            </select>
                            <InputError :message="form.errors.smtp_encryption" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="smtp_username">Username</Label>
                        <Input id="smtp_username" v-model="form.smtp_username" placeholder="you@domain.com" />
                        <InputError :message="form.errors.smtp_username" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="smtp_password">Password / app password</Label>
                        <Input id="smtp_password" v-model="form.smtp_password" type="password" placeholder="Enter SMTP password" />
                        <p v-if="smtp.has_password" class="text-xs text-muted-foreground">
                            A password is already saved. Leave this blank to keep it unchanged.
                        </p>
                        <InputError :message="form.errors.smtp_password" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="smtp_from_address">From email</Label>
                            <Input id="smtp_from_address" v-model="form.smtp_from_address" type="email" placeholder="noreply@domain.com" />
                            <InputError :message="form.errors.smtp_from_address" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="smtp_from_name">From name</Label>
                            <Input id="smtp_from_name" v-model="form.smtp_from_name" placeholder="Your Brand" />
                            <InputError :message="form.errors.smtp_from_name" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="test_email">Send test to</Label>
                        <Input id="test_email" v-model="form.test_email" type="email" placeholder="you@domain.com" />
                        <InputError :message="form.errors.test_email" />
                        <InputError :message="(form.errors as Record<string, string | undefined>).smtp_test" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button type="submit" :disabled="form.processing">
                            Save SMTP settings
                        </Button>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="sendTestEmail">
                            Send Test Email
                        </Button>
                        <p v-if="form.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</p>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
