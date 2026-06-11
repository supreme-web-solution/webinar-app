<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmailCampaignForm from '@/components/emails/EmailCampaignForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type CampaignPayload = {
    id: number;
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

const props = defineProps<{
    campaign: CampaignPayload;
    attendees: {
        subscribed_total: number;
        subscribed: Array<{
            id: number;
            name: string | null;
            email: string;
            imported_at: string | null;
            send_count: number;
            click_count: number;
            last_clicked_at: string | null;
            unsubscribe_url?: string;
        }>;
        unsubscribed_total: number;
        unsubscribed: Array<{
            id: number;
            name: string | null;
            email: string;
            unsubscribed_at?: string | null;
            delete_url?: string;
        }>;
    };
    attendeeImportUrl: string | null;
    attendeeActionUrls: {
        bulk_unsubscribe_url: string;
        bulk_delete_url: string;
    } | null;
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
    { title: props.campaign.title, href: `/admin/emails/${props.campaign.id}/edit` },
];
</script>

<template>
    <Head :title="`Edit ${campaign.title}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 md:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold">Edit Email Campaign</h1>
                    <p class="text-sm text-muted-foreground">
                        Update content, import attendees, and track CTA link clicks.
                    </p>
                </div>
                <div class="grid grid-cols-3 gap-2 text-sm md:grid-cols-3">
                    <div class="group relative rounded-md border px-3 py-2">
                        <p class="flex items-center gap-1 text-muted-foreground">
                            Recipients
                            <span class="ml-1 cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 16v-4m0-4h.01"/></svg>
                                <span class="absolute left-0 z-10 hidden w-48 rounded bg-background p-2 text-xs text-foreground shadow group-hover:block">
                                    Total imported email recipients for this campaign.
                                </span>
                            </span>
                        </p>
                        <p class="font-semibold">{{ stats.recipients }}</p>
                    </div>
                    <div class="group relative rounded-md border px-3 py-2">
                        <p class="flex items-center gap-1 text-muted-foreground">
                            Sent
                            <span class="ml-1 cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 16v-4m0-4h.01"/></svg>
                                <span class="absolute left-0 z-10 hidden w-48 rounded bg-background p-2 text-xs text-foreground shadow group-hover:block">
                                    Recipients who received at least one email from this campaign.
                                </span>
                            </span>
                        </p>
                        <p class="font-semibold">{{ stats.sent_recipients }}</p>
                    </div>
                    <div class="group relative rounded-md border px-3 py-2">
                        <p class="flex items-center gap-1 text-muted-foreground">
                            CTA Clicks
                            <span class="ml-1 cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 16v-4m0-4h.01"/></svg>
                                <span class="absolute left-0 z-10 hidden w-48 rounded bg-background p-2 text-xs text-foreground shadow group-hover:block">
                                    Total tracked clicks on the campaign CTA link.
                                </span>
                            </span>
                        </p>
                        <p class="font-semibold">{{ stats.clicks }}</p>
                    </div>
                </div>
            </div>

            <EmailCampaignForm
                mode="edit"
                method="put"
                :action-url="`/admin/emails/${campaign.id}`"
                :initial-values="campaign"
                :attendees="attendees"
                :attendee-import-url="attendeeImportUrl"
                :attendee-action-urls="attendeeActionUrls"
                :send-url="sendUrl"
            />
        </div>
    </AppLayout>
</template>
