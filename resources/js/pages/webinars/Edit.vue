<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import WebinarWizardForm from '@/components/webinars/WebinarWizardForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type WebinarPayload = {
    id: number;
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
        redirect_enabled: boolean;
        redirect_url: string;
        exit_popup_enabled: boolean;
        exit_popup_heading: string;
        exit_popup_body: string;
        exit_popup_cta_text: string;
        exit_popup_cta_url: string;
    };
    ai_settings: {
        enabled: boolean;
        auto_reply_enabled: boolean;
        assistant_name: string;
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
    views_60_seconds: number;
    views_watched_to_end: number;
    chat_messages: number;
    offers: number;
    cta_clicks: number;
    segment_below_50: number;
    segment_above_50: number;
    segment_completed_no_click: number;
};

const props = defineProps<{
    webinar: WebinarPayload;
    stats: Stats;
    aiSourceUrls: {
        index: string | null;
        url: string | null;
        transcript: string | null;
        video_transcript_generate: string | null;
        file: string | null;
        bulk_delete: string | null;
    };
    aiSources: Array<{
        id: number;
        type: string;
        title: string | null;
        source_url: string | null;
        status: string;
        error_message: string | null;
        processed_at: string | null;
        chunk_count: number;
        chunks_url: string;
        delete_url: string;
    }>;
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
        apollo_preview_url: string;
        apollo_fetch_url: string;
    } | null;
    apolloMaxFetch: number;
    timezoneOptions: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Webinars', href: '/admin/webinars' },
    { title: props.webinar.title, href: `/admin/webinars/${props.webinar.id}/edit` },
];

const baseUrl = computed(() => {
    if (typeof window !== 'undefined' && window.location?.origin) {
        return window.location.origin;
    }

    return '';
});

const registerLink = computed(() => `${baseUrl.value}/register/${props.webinar.uuid}`);
const joinLink = computed(() => `${baseUrl.value}/webinar/live/${props.webinar.uuid}`);
const copyStatus = ref('');

const copyLink = async (value: string, label: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(value);
        copyStatus.value = `${label} copied`;
    } catch {
        copyStatus.value = `Failed to copy ${label.toLowerCase()}`;
    }

    window.setTimeout(() => {
        copyStatus.value = '';
    }, 2500);
};
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
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-md border px-3 py-1.5 text-xs font-medium hover:bg-muted"
                            @click="void copyLink(registerLink, 'Register link')"
                        >
                            Copy Register Link
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center rounded-md border px-3 py-1.5 text-xs font-medium hover:bg-muted"
                            @click="void copyLink(joinLink, 'Join link')"
                        >
                            Copy Join Link
                        </button>
                        <span v-if="copyStatus" class="text-xs text-muted-foreground">{{ copyStatus }}</span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 text-sm md:grid-cols-5">
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Registrants</p>
                        <p class="font-semibold">{{ stats.registrants }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Views</p>
                        <p class="font-semibold">{{ stats.views }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Watched 60s+</p>
                        <p class="font-semibold">{{ stats.views_60_seconds }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Watched to End</p>
                        <p class="font-semibold">{{ stats.views_watched_to_end }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Messages</p>
                        <p class="font-semibold">{{ stats.chat_messages }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Offers</p>
                        <p class="font-semibold">{{ stats.offers }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">CTA Clicks</p>
                        <p class="font-semibold">{{ stats.cta_clicks }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Below 50%</p>
                        <p class="font-semibold">{{ stats.segment_below_50 }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Above 50%</p>
                        <p class="font-semibold">{{ stats.segment_above_50 }}</p>
                    </div>
                    <div class="rounded-md border px-3 py-2">
                        <p class="text-muted-foreground">Completed No Click</p>
                        <p class="font-semibold">{{ stats.segment_completed_no_click }}</p>
                    </div>
                </div>
            </div>

            <WebinarWizardForm
                mode="edit"
                method="put"
                :action-url="`/admin/webinars/${webinar.id}`"
                :initial-values="webinar"
                :ai-source-urls="aiSourceUrls"
                :ai-sources="aiSources"
                :attendees="attendees"
                :attendee-import-url="attendeeImportUrl"
                :attendee-action-urls="attendeeActionUrls"
                :apollo-max-fetch="apolloMaxFetch"
                :timezone-options="timezoneOptions"
            />
        </div>
    </AppLayout>
</template>
