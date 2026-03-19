<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WebinarWizardForm from '@/components/webinars/WebinarWizardForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type WebinarPayload = {
    id: number;
    title: string;
    host_name: string;
    description: string;
    scheduled_at: string;
    scheduled_timezone: string;
    video_source: 'youtube' | 'vimeo' | 'direct';
    video_url: string;
    video_duration_seconds: number | null;
    thumbnail_path: string;
    uuid: string;
    min_viewers: number;
    max_viewers: number;
    is_published: boolean;
    email_settings: {
        send_confirmation: boolean;
        send_reminder: boolean;
        send_follow_up: boolean;
    };
    playback_settings: {
        show_fake_viewers: boolean;
    };
    registration_settings: {
        buttons: Array<{
            label: string;
            enabled: boolean;
            is_primary: boolean;
            urgency_mode: 'none' | 'minutes' | 'live';
            urgency_minutes: number | null;
            position?: number;
        }>;
    };
    offers: Array<{
        id?: number;
        title: string;
        description: string;
        trigger_second: number;
        button_text: string;
        button_url: string;
        display_mode: 'chat' | 'popup' | 'pinned';
    }>;
};

type Stats = {
    registrants: number;
    views: number;
    chat_messages: number;
    offers: number;
};

const props = defineProps<{
    webinar: WebinarPayload;
    stats: Stats;
    attendees: {
        subscribed: Array<{ id: number; name: string; email: string; registered_at?: string | null; unsubscribe_url?: string }>;
        unsubscribed: Array<{ id: number; name: string; email: string; unsubscribed_at?: string | null; delete_url?: string }>;
    };
    attendeeImportUrl: string | null;
    attendeeActionUrls: {
        bulk_unsubscribe_url: string;
        bulk_delete_url: string;
    } | null;
    timezoneOptions: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Webinars', href: '/admin/webinars' },
    { title: props.webinar.title, href: `/admin/webinars/${props.webinar.id}/edit` },
];
</script>

<template>
    <Head :title="`Edit ${webinar.title}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold">Edit Webinar</h1>
                    <p class="text-sm text-muted-foreground">
                        Update settings, automation, and publishing options.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Registrants</p>
                        <p class="font-semibold">{{ stats.registrants }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Views</p>
                        <p class="font-semibold">{{ stats.views }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Messages</p>
                        <p class="font-semibold">{{ stats.chat_messages }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Offers</p>
                        <p class="font-semibold">{{ stats.offers }}</p>
                    </div>
                </div>
            </div>

            <WebinarWizardForm
                mode="edit"
                method="put"
                :action-url="`/admin/webinars/${webinar.id}`"
                :initial-values="webinar"
                :attendees="attendees"
                :attendee-import-url="attendeeImportUrl"
                :attendee-action-urls="attendeeActionUrls"
                :timezone-options="timezoneOptions"
            />
        </div>
    </AppLayout>
</template>
