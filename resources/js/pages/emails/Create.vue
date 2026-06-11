<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { Head } from '@inertiajs/vue3';
import EmailCampaignForm from '@/components/emails/EmailCampaignForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type CampaignDefaults = {
    title_prefix: string;
    title: string;
    sender_name: string;
    body: string;
    cta_label: string;
    cta_url: string;
    settings: {
        send_on_import: boolean;
    };
};

defineProps<{
    defaults: CampaignDefaults;
    attendees: {
        subscribed_total: number;
        subscribed: [];
        unsubscribed_total: number;
        unsubscribed: [];
    };
    attendeeImportUrl: string | null;
    attendeeActionUrls: null;
    sendUrl: string | null;
    stats: {
        recipients: number;
        sent_recipients: number;
        clicks: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Emails', href: '/admin/emails' },
    { title: 'Create', href: '/admin/emails/create' },
];
</script>

<template>
    <Head title="Create Email Campaign" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 pb-10 md:p-6">
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 shadow-sm dark:bg-indigo-950/60 dark:text-indigo-400">
                    <Icon icon="solar:letter-bold-duotone" class="size-6" />
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Emails</p>
                    <h1 class="mt-0.5 text-2xl font-bold tracking-tight text-foreground">Create Email Campaign</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Set your content, import attendees, and track CTA link clicks.
                    </p>
                </div>
            </div>

            <EmailCampaignForm
                mode="create"
                method="post"
                action-url="/admin/emails"
                :initial-values="defaults"
                :attendees="attendees"
                :attendee-import-url="attendeeImportUrl"
                :attendee-action-urls="attendeeActionUrls"
                :send-url="sendUrl"
            />
        </div>
    </AppLayout>
</template>
