<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Icon } from '@iconify/vue';
import WebinarWizardForm from '@/components/webinars/WebinarWizardForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Defaults = {
    title_prefix: string;
    title: string;
    schedule_mode: 'auto' | 'scheduled';
    host_name: string;
    description: string;
    scheduled_at: string;
    scheduled_timezone: string;
    video_source: 'youtube' | 'vimeo' | 'direct';
    video_url: string;
    video_duration_seconds: number | null;
    thumbnail_path: string;
    uuid: string | null;
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
        redirect_enabled: boolean;
        redirect_url: string;
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

defineProps<{
    defaults: Defaults;
    attendees: {
        subscribed_total: number;
        subscribed: Array<{ id: number; name: string; email: string; registered_at?: string | null; unsubscribe_url?: string }>;
        unsubscribed_total: number;
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
    { title: 'Create', href: '/admin/webinars/create' },
];
</script>

<template>
    <Head title="Create Webinar" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 pb-10 md:p-6">

            <!-- Page header -->
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400 shadow-sm">
                    <Icon icon="solar:monitor-camera-bold-duotone" class="size-6" />
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Webinars</p>
                    <h1 class="mt-0.5 text-2xl font-bold tracking-tight text-foreground">Create Webinar</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Go through each step to fully configure your webinar experience before publishing.
                    </p>
                </div>
            </div>

            <WebinarWizardForm
                mode="create"
                method="post"
                action-url="/admin/webinars"    
                :initial-values="defaults"
                :attendees="attendees"
                :attendee-import-url="attendeeImportUrl"
                :attendee-action-urls="attendeeActionUrls"
                :timezone-options="timezoneOptions"
            />
        </div>
    </AppLayout>
</template>
