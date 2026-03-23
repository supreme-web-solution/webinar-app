<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type WebinarListItem = {
    id: number;
    uuid: string;
    title: string;
    schedule_mode: 'auto' | 'scheduled';
    has_ended: boolean;
    scheduled_at_label: string | null;
    scheduled_timezone: string;
    host_name: string;
    video_source: string;
    is_published: boolean;
    registrants_count: number;
    views_count: number;
    registration_link: string;
    room_link: string;
    chat_link: string;
    notify_link: string;
    updated_at: string | null;
};

defineProps<{
    webinars: {
        data: WebinarListItem[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Webinars', href: '/admin/webinars' },
];

const toastMessage = ref<string | null>(null);

const showToast = (message: string): void => {
    toastMessage.value = message;
    window.setTimeout(() => {
        if (toastMessage.value === message) {
            toastMessage.value = null;
        }
    }, 3000);
};

const copyLink = async (link: string, label: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(link);
        showToast(`${label} copied.`);
    } catch {
        showToast(`Unable to copy ${label.toLowerCase()}.`);
    }
};
</script>

<template>
    <Head title="Webinars" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div
                v-if="toastMessage"
                class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
            >
                {{ toastMessage }}
            </div>

            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Webinars</h1>
                    <p class="text-sm text-muted-foreground">
                        Manage your webinar funnels, registration flows, and playback settings.
                    </p>
                </div>
                <Link
                    href="/admin/webinars/create"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                >
                    Create Webinar
                </Link>
            </div>

            <div class="overflow-hidden rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">Source</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Registrants</th>
                            <th class="px-4 py-3">Views</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="webinar in webinars.data" :key="webinar.id" class="border-t">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ webinar.title }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    <span
                                        class="rounded-full px-2 py-0.5"
                                        :class="webinar.schedule_mode === 'auto' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700'"
                                    >
                                        {{ webinar.schedule_mode === 'auto' ? 'Auto' : 'Scheduled' }}
                                    </span>
                                    <span v-if="webinar.schedule_mode === 'scheduled' && webinar.scheduled_at_label" class="ml-2">
                                        {{ webinar.scheduled_at_label }} ({{ webinar.scheduled_timezone }})
                                    </span>
                                </p>
                                <p class="text-xs text-muted-foreground font-mono">{{ webinar.uuid }}</p>
                            </td>
                            <td class="px-4 py-3 capitalize">{{ webinar.video_source }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-1 text-xs"
                                    :class="
                                        webinar.has_ended
                                            ? 'bg-rose-100 text-rose-700'
                                            : webinar.is_published
                                                ? 'bg-emerald-100 text-emerald-700'
                                                : 'bg-amber-100 text-amber-700'
                                    "
                                >
                                    {{ webinar.has_ended ? 'Ended' : webinar.is_published ? 'Published' : 'Draft' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ webinar.registrants_count }}</td>
                            <td class="px-4 py-3">{{ webinar.views_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-3 text-xs">
                                    <Link
                                        :href="`/admin/webinars/${webinar.id}/edit`"
                                        class="text-primary underline underline-offset-4"
                                    >
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        class="text-primary underline underline-offset-4"
                                        @click="copyLink(webinar.registration_link, 'Registration link')"
                                    >
                                        Copy Registration Link
                                    </button>
                                    <button
                                        type="button"
                                        class="text-primary underline underline-offset-4"
                                        @click="copyLink(webinar.room_link, 'Room link')"
                                    >
                                        Copy Join Link
                                    </button>
                                    <Link
                                        :href="webinar.chat_link"
                                        class="text-primary underline underline-offset-4"
                                    >
                                        Chat
                                    </Link>
                                    <Link
                                        :href="webinar.notify_link"
                                        method="post"
                                        as="button"
                                        class="text-primary underline underline-offset-4"
                                    >
                                        Notify All
                                    </Link>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="webinars.data.length === 0">
                            <td class="px-4 py-8 text-center text-muted-foreground" colspan="6">
                                No webinars yet. Create your first webinar to get started.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
