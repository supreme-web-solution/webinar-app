<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Icon } from '@iconify/vue';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
const toastType = ref<'success' | 'info'>('success');

const showToast = (message: string, type: 'success' | 'info' = 'success'): void => {
    toastMessage.value = message;
    toastType.value = type;
    window.setTimeout(() => {
        if (toastMessage.value === message) toastMessage.value = null;
    }, 3000);
};

const copyLink = async (link: string, label: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(link);
        showToast(`${label} copied.`);
    } catch {
        showToast(`Unable to copy ${label.toLowerCase()}.`, 'info');
    }
};

const deleteWebinar = (webinarId: number, title: string): void => {
    const ok = window.confirm(`Delete webinar "${title}" and all its data (attendees, chats, tracking)?`);
    if (!ok) return;
    router.delete(`/admin/webinars/${webinarId}`);
};

const videoSourceIcon = (source: string): string => {
    if (source === 'youtube') return 'solar:playback-speed-bold-duotone';
    if (source === 'vimeo') return 'solar:play-circle-bold-duotone';
    return 'solar:video-library-bold-duotone';
};
</script>

<template>
    <Head title="Webinars" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-5 p-4 pb-10 md:p-6">

            <!-- Toast -->
            <div
                v-if="toastMessage"
                class="rounded-lg border px-4 py-3 text-sm"
                :class="toastType === 'success'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                    : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300'"
            >
                {{ toastMessage }}
            </div>

            <!-- Page header -->
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Management</p>
                    <h1 class="mt-0.5 text-2xl font-bold tracking-tight text-foreground">Webinars</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Manage your webinar funnels, registration flows, and playback settings.
                    </p>
                </div>
                <Button as-child size="sm" class="mt-3 gap-1.5 h-9 px-4 font-semibold shadow-sm sm:mt-0">
                    <Link href="/admin/webinars/create">
                        <Icon icon="solar:add-circle-bold" class="size-4" />
                        New Webinar
                    </Link>
                </Button>
            </div>

            <!-- Webinars table card -->
            <Card class="border border-border/60 shadow-sm">
                <CardHeader class="border-b border-border/50 pb-3 pt-4 px-5 flex-row items-center justify-between space-y-0">
                    <div>
                        <CardTitle class="text-sm font-semibold">All Webinars</CardTitle>
                        <CardDescription class="text-xs mt-0.5">
                            {{ webinars.data.length }} webinar{{ webinars.data.length === 1 ? '' : 's' }} total
                        </CardDescription>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button variant="outline" size="sm" class="h-7 gap-1.5 px-2.5 text-xs border-border/60">
                            <Icon icon="solar:filter-linear" class="size-3" />
                            Filter
                        </Button>
                    </div>
                </CardHeader>

                <CardContent class="px-0 pb-0">
                    <!-- Empty state -->
                    <div
                        v-if="webinars.data.length === 0"
                        class="flex flex-col items-center justify-center px-6 py-16 text-center"
                    >
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-500 dark:bg-indigo-950/60 dark:text-indigo-400 mb-4">
                            <Icon icon="solar:monitor-camera-bold-duotone" class="size-7" />
                        </div>
                        <h3 class="text-base font-semibold text-foreground">No webinars yet</h3>
                        <p class="mt-1.5 max-w-sm text-sm text-muted-foreground">
                            Create your first webinar to start collecting registrations and tracking views.
                        </p>
                        <Button as-child size="sm" class="mt-5 gap-1.5 font-semibold shadow-sm">
                            <Link href="/admin/webinars/create">
                                <Icon icon="solar:add-circle-bold" class="size-4" />
                                Create your first webinar
                            </Link>
                        </Button>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border/50">
                                    <th class="px-5 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Webinar
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Source
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Status
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Registrants
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Views
                                    </th>
                                    <th class="px-5 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="webinar in webinars.data"
                                    :key="webinar.id"
                                    class="border-b border-border/30 last:border-0 hover:bg-muted/30 transition-colors"
                                >
                                    <!-- Title + meta -->
                                    <td class="px-5 py-3.5 align-middle">
                                        <div class="flex items-start gap-3">
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400 mt-0.5">
                                                <Icon :icon="videoSourceIcon(webinar.video_source)" class="size-4" />
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-semibold text-foreground leading-snug">{{ webinar.title }}</p>
                                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                                    <span
                                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                        :class="webinar.schedule_mode === 'auto'
                                                            ? 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-400'
                                                            : 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-400'"
                                                    >
                                                        <Icon
                                                            :icon="webinar.schedule_mode === 'auto' ? 'solar:infinity-bold' : 'solar:calendar-bold'"
                                                            class="mr-0.5 size-2.5"
                                                        />
                                                        {{ webinar.schedule_mode === 'auto' ? 'Auto' : 'Scheduled' }}
                                                    </span>
                                                    <span
                                                        v-if="webinar.schedule_mode === 'scheduled' && webinar.scheduled_at_label"
                                                        class="text-[11px] text-muted-foreground"
                                                    >
                                                        {{ webinar.scheduled_at_label }}
                                                    </span>
                                                    <span class="text-[10px] text-muted-foreground/60 font-mono hidden lg:inline">
                                                        {{ webinar.uuid }}
                                                    </span>
                                                </div>
                                                <p class="mt-0.5 text-xs text-muted-foreground">
                                                    <Icon icon="solar:user-linear" class="inline size-3 mr-0.5" />
                                                    {{ webinar.host_name }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Source -->
                                    <td class="px-4 py-3.5 align-middle">
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground capitalize">
                                            <Icon :icon="videoSourceIcon(webinar.video_source)" class="size-3.5 text-muted-foreground/60" />
                                            {{ webinar.video_source }}
                                        </span>
                                    </td>

                                    <!-- Status -->
                                    <td class="px-4 py-3.5 align-middle">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                            :class="
                                                webinar.has_ended
                                                    ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-400'
                                                    : webinar.is_published
                                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400'
                                                        : 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400'
                                            "
                                        >
                                            <span
                                                class="h-1 w-1 rounded-full"
                                                :class="webinar.has_ended ? 'bg-rose-500' : webinar.is_published ? 'bg-emerald-500' : 'bg-amber-500'"
                                            />
                                            {{ webinar.has_ended ? 'Ended' : webinar.is_published ? 'Published' : 'Draft' }}
                                        </span>
                                    </td>

                                    <!-- Registrants -->
                                    <td class="px-4 py-3.5 align-middle text-right">
                                        <div class="flex flex-col items-end">
                                            <span class="font-semibold tabular-nums text-foreground">{{ webinar.registrants_count.toLocaleString() }}</span>
                                        </div>
                                    </td>

                                    <!-- Views -->
                                    <td class="px-4 py-3.5 align-middle text-right">
                                        <span class="font-semibold tabular-nums text-foreground">{{ webinar.views_count.toLocaleString() }}</span>
                                    </td>

                                    <!-- Actions dropdown -->
                                    <td class="px-5 py-3.5 align-middle text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <Button as-child variant="ghost" size="sm" class="h-7 px-2.5 text-xs font-medium text-primary hover:text-primary/80">
                                                <Link :href="`/admin/webinars/${webinar.id}/edit`">
                                                    <Icon icon="solar:pen-bold" class="mr-1 size-3" />
                                                    Edit
                                                </Link>
                                            </Button>

                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child>
                                                    <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-muted-foreground hover:text-foreground">
                                                        <Icon icon="solar:menu-dots-bold" class="size-4" />
                                                        <span class="sr-only">More options</span>
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" class="w-52 rounded-xl border-border/60 shadow-lg">
                                                    <DropdownMenuItem
                                                        class="cursor-pointer gap-2 text-xs"
                                                        @click="copyLink(webinar.registration_link, 'Registration link')"
                                                    >
                                                        <Icon icon="solar:copy-linear" class="size-3.5 text-muted-foreground" />
                                                        Copy Registration Link
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        class="cursor-pointer gap-2 text-xs"
                                                        @click="copyLink(webinar.room_link, 'Room link')"
                                                    >
                                                        <Icon icon="solar:link-linear" class="size-3.5 text-muted-foreground" />
                                                        Copy Join Link
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem as-child class="gap-2 text-xs">
                                                        <Link :href="webinar.chat_link" class="cursor-pointer">
                                                            <Icon icon="solar:chat-round-dots-linear" class="size-3.5 text-muted-foreground" />
                                                            Moderate Chat
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem as-child class="gap-2 text-xs">
                                                        <Link
                                                            :href="webinar.notify_link"
                                                            method="post"
                                                            as="button"
                                                            class="w-full cursor-pointer"
                                                        >
                                                            <Icon icon="solar:bell-bing-linear" class="size-3.5 text-muted-foreground" />
                                                            Notify All Registrants
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        class="cursor-pointer gap-2 text-xs text-destructive focus:text-destructive focus:bg-destructive/10"
                                                        @click="deleteWebinar(webinar.id, webinar.title)"
                                                    >
                                                        <Icon icon="solar:trash-bin-2-linear" class="size-3.5" />
                                                        Delete Webinar
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

        </div>
    </AppLayout>
</template>
