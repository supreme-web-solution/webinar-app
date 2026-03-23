<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Calendar,
    Copy,
    Eye,
    MessageSquare,
    Plus,
    Sparkles,
    Tv,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type RecentWebinar = {
    id: number;
    title: string;
    uuid: string;
    host_name: string;
    schedule_mode: 'auto' | 'scheduled';
    has_ended: boolean;
    is_published: boolean;
    video_source: string;
    registrants_count: number;
    views_count: number;
    scheduled_at_label: string | null;
    scheduled_timezone: string;
    updated_at: string | null;
    edit_url: string;
    registration_link: string;
    chat_link: string;
};

const props = defineProps<{
    user: { name: string };
    stats: {
        total_webinars: number;
        published_webinars: number;
        draft_webinars: number;
        total_registrants: number;
        total_views: number;
        total_chat_messages: number;
    };
    recentWebinars: RecentWebinar[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];

const toastMessage = ref<string | null>(null);

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 12) {
        return 'Good morning';
    }
    if (h < 18) {
        return 'Good afternoon';
    }
    return 'Good evening';
});

const firstName = computed(() => {
    const parts = props.user.name.trim().split(/\s+/);
    return parts[0] ?? 'there';
});

const showToast = (message: string): void => {
    toastMessage.value = message;
    window.setTimeout(() => {
        if (toastMessage.value === message) {
            toastMessage.value = null;
        }
    }, 3200);
};

const copyLink = async (link: string, label: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(link);
        showToast(`${label} copied to clipboard.`);
    } catch {
        showToast('Unable to copy. Try selecting the link manually.');
    }
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4 pb-8">
            <div
                v-if="toastMessage"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200"
            >
                {{ toastMessage }}
            </div>

            <!-- Hero -->
            <section
                class="relative overflow-hidden rounded-2xl border bg-gradient-to-br from-primary/[0.07] via-background to-violet-500/[0.06] p-6 shadow-sm md:p-8"
            >
                <div
                    class="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full bg-primary/10 blur-3xl"
                />
                <div
                    class="pointer-events-none absolute -bottom-20 left-1/3 h-40 w-40 rounded-full bg-violet-500/10 blur-3xl"
                />

                <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-2xl space-y-2">
                        <p
                            class="inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-background/80 px-3 py-1 text-xs font-medium text-primary backdrop-blur-sm"
                        >
                            <Sparkles class="h-3.5 w-3.5" aria-hidden="true" />
                            Webinar overview
                        </p>
                        <h1 class="text-2xl font-semibold tracking-tight md:text-3xl">
                            {{ greeting }}, {{ firstName }}
                        </h1>
                        <p class="text-sm text-muted-foreground md:text-base">
                            Track registrations, views, and engagement across your webinars. Create a
                            new session or jump back into one you are editing.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <Button as-child size="default" class="gap-1.5 shadow-sm">
                            <Link href="/admin/webinars/create">
                                <Plus class="h-4 w-4" aria-hidden="true" />
                                New webinar
                            </Link>
                        </Button>
                        <Button as-child variant="outline" size="default" class="gap-1.5 bg-background/60 backdrop-blur-sm">
                            <Link href="/admin/webinars">
                                All webinars
                                <ArrowRight class="h-4 w-4" aria-hidden="true" />
                            </Link>
                        </Button>
                        <Button as-child variant="outline" size="default" class="gap-1.5 bg-background/60 backdrop-blur-sm">
                            <Link href="/admin/chats">
                                <MessageSquare class="h-4 w-4" aria-hidden="true" />
                                Chats
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>

            <!-- Stats -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card class="border shadow-sm transition-shadow hover:shadow-md">
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium"> Webinars </CardTitle>
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400"
                        >
                            <Tv class="h-4 w-4" aria-hidden="true" />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-semibold tabular-nums">{{ stats.total_webinars }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ stats.published_webinars }} published
                            <span v-if="stats.draft_webinars > 0">
                                · {{ stats.draft_webinars }} draft{{ stats.draft_webinars === 1 ? '' : 's' }}
                            </span>
                        </p>
                    </CardContent>
                </Card>

                <Card class="border shadow-sm transition-shadow hover:shadow-md">
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium"> Registrants </CardTitle>
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                        >
                            <Users class="h-4 w-4" aria-hidden="true" />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-semibold tabular-nums">{{ stats.total_registrants }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Across all of your webinars</p>
                    </CardContent>
                </Card>

                <Card class="border shadow-sm transition-shadow hover:shadow-md">
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium"> Room views </CardTitle>
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400"
                        >
                            <Eye class="h-4 w-4" aria-hidden="true" />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-semibold tabular-nums">{{ stats.total_views }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Total join sessions recorded</p>
                    </CardContent>
                </Card>

                <Card class="border shadow-sm transition-shadow hover:shadow-md">
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium"> Chat messages </CardTitle>
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400"
                        >
                            <MessageSquare class="h-4 w-4" aria-hidden="true" />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-semibold tabular-nums">{{ stats.total_chat_messages }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Host and attendee activity</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Recent webinars -->
            <Card class="border shadow-sm">
                <CardHeader class="flex flex-col gap-1 border-b pb-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <CardTitle>Recent webinars</CardTitle>
                        <CardDescription>
                            Last updated sessions — open an event to edit, copy links, or moderate chat.
                        </CardDescription>
                    </div>
                    <Button as-child variant="outline" size="sm" class="mt-2 w-full sm:mt-0 sm:w-auto">
                        <Link href="/admin/webinars"> View all </Link>
                    </Button>
                </CardHeader>
                <CardContent class="pt-6">
                    <div
                        v-if="recentWebinars.length === 0"
                        class="flex flex-col items-center justify-center rounded-xl border border-dashed bg-muted/30 px-6 py-16 text-center"
                    >
                        <div
                            class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                        >
                            <Tv class="h-7 w-7" aria-hidden="true" />
                        </div>
                        <h3 class="text-lg font-semibold">No webinars yet</h3>
                        <p class="mt-2 max-w-sm text-sm text-muted-foreground">
                            Create your first on-demand webinar to start collecting registrations and
                            tracking views.
                        </p>
                        <Button as-child class="mt-6 gap-1.5">
                            <Link href="/admin/webinars/create">
                                <Plus class="h-4 w-4" aria-hidden="true" />
                                Create your first webinar
                            </Link>
                        </Button>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="pb-3 pr-4 font-medium">Webinar</th>
                                    <th class="pb-3 pr-4 font-medium">Schedule</th>
                                    <th class="pb-3 pr-4 font-medium">Status</th>
                                    <th class="pb-3 pr-4 font-medium">Registrants</th>
                                    <th class="pb-3 pr-4 font-medium">Views</th>
                                    <th class="pb-3 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="w in recentWebinars"
                                    :key="w.id"
                                    class="border-b border-border/60 last:border-0"
                                >
                                    <td class="py-3 pr-4 align-top">
                                        <p class="font-medium leading-snug">{{ w.title }}</p>
                                        <p class="mt-0.5 text-xs text-muted-foreground">
                                            {{ w.host_name }} ·
                                            <span class="capitalize">{{ w.video_source }}</span>
                                        </p>
                                    </td>
                                    <td class="py-3 pr-4 align-top">
                                        <div
                                            v-if="w.schedule_mode === 'scheduled' && w.scheduled_at_label"
                                            class="flex items-start gap-1.5 text-muted-foreground"
                                        >
                                            <Calendar class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            <span>
                                                {{ w.scheduled_at_label }}
                                                <span class="block text-[11px] opacity-80">{{ w.scheduled_timezone }}</span>
                                            </span>
                                        </div>
                                        <span v-else class="text-muted-foreground">Auto</span>
                                    </td>
                                    <td class="py-3 pr-4 align-top">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                                            :class="
                                                w.has_ended
                                                    ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/50 dark:text-rose-300'
                                                    : w.is_published
                                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
                                                        : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200'
                                            "
                                        >
                                            {{ w.has_ended ? 'Ended' : w.is_published ? 'Published' : 'Draft' }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 align-top tabular-nums">{{ w.registrants_count }}</td>
                                    <td class="py-3 pr-4 align-top tabular-nums">{{ w.views_count }}</td>
                                    <td class="py-3 align-top">
                                        <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                            <Link
                                                :href="w.edit_url"
                                                class="font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                Edit
                                            </Link>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-0.5 font-medium text-primary underline-offset-4 hover:underline"
                                                @click="copyLink(w.registration_link, 'Registration link')"
                                            >
                                                <Copy class="h-3 w-3" aria-hidden="true" />
                                                Copy link
                                            </button>
                                            <Link
                                                :href="w.chat_link"
                                                class="font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                Chat
                                            </Link>
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
