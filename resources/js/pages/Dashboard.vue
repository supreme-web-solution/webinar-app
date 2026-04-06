<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Icon } from '@iconify/vue';
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
        segment_below_50: number;
        segment_above_50: number;
        segment_completed_no_click: number;
    };
    recentWebinars: RecentWebinar[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
];

const toastMessage = ref<string | null>(null);

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
});

const firstName = computed(() => {
    const parts = props.user.name.trim().split(/\s+/);
    return parts[0] ?? 'there';
});

const showToast = (message: string): void => {
    toastMessage.value = message;
    window.setTimeout(() => {
        if (toastMessage.value === message) toastMessage.value = null;
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

// Compute webinar health score (ratio of published vs total)
const publishedRatio = computed(() => {
    if (!props.stats.total_webinars) return 0;
    return Math.round((props.stats.published_webinars / props.stats.total_webinars) * 100);
});

// engagement rate: chat messages per view
const engagementRate = computed(() => {
    if (!props.stats.total_views) return 0;
    return ((props.stats.total_chat_messages / props.stats.total_views) * 100).toFixed(1);
});

// conversion rate: views per registrant
const conversionRate = computed(() => {
    if (!props.stats.total_registrants) return 0;
    return Math.min(100, Math.round((props.stats.total_views / props.stats.total_registrants) * 100));
});

// Top webinars by registrants (for performance section)
const topWebinars = computed(() => {
    return [...props.recentWebinars]
        .sort((a, b) => b.registrants_count - a.registrants_count)
        .slice(0, 5);
});

const maxRegistrants = computed(() => {
    return Math.max(1, ...topWebinars.value.map((w) => w.registrants_count));
});

const statCards = computed(() => [
    {
        title: 'Total Webinars',
        value: props.stats.total_webinars,
        sub: `${props.stats.published_webinars} published Â· ${props.stats.draft_webinars} draft`,
        icon: 'solar:monitor-camera-bold-duotone',
        color: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
        trend: null,
    },
    {
        title: 'Registrants',
        value: props.stats.total_registrants.toLocaleString(),
        sub: 'Across all webinars',
        icon: 'solar:users-group-rounded-bold-duotone',
        color: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        trend: '+12%',
    },
    {
        title: 'Room Views',
        value: props.stats.total_views.toLocaleString(),
        sub: 'Total join sessions recorded',
        icon: 'solar:eye-bold-duotone',
        color: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
        trend: '+8%',
    },
    {
        title: 'Chat Messages',
        value: props.stats.total_chat_messages.toLocaleString(),
        sub: 'Host & attendee activity',
        icon: 'solar:chat-round-dots-bold-duotone',
        color: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        trend: '+24%',
    },
    // {
    //     title: 'Below 50% Watch',
    //     value: props.stats.segment_below_50.toLocaleString(),
    //     sub: 'Needs stronger follow-up hook',
    //     icon: 'solar:danger-circle-bold-duotone',
    //     color: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    //     trend: null,
    // },
    // {
    //     title: 'Above 50% Watch',
    //     value: props.stats.segment_above_50.toLocaleString(),
    //     sub: 'Warm leads near conversion',
    //     icon: 'solar:chart-2-bold-duotone',
    //     color: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    //     trend: null,
    // },
    // {
    //     title: 'Completed No Click',
    //     value: props.stats.segment_completed_no_click.toLocaleString(),
    //     sub: 'High intent but CTA missed',
    //     icon: 'solar:target-bold-duotone',
    //     color: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    //     trend: null,
    // },
]);
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-5 p-4 pb-10 md:p-6">

            <!-- Toast -->
            <div
                v-if="toastMessage"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200"
            >
                {{ toastMessage }}
            </div>

            <!-- â”€â”€ Page header â”€â”€ -->
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        Check your latest webinar activity
                    </p>
                    <h1 class="mt-0.5 text-2xl font-bold tracking-tight text-foreground">
                        {{ greeting }}, {{ firstName }}
                    </h1>
                </div>
                <div class="flex flex-wrap gap-2 mt-3 sm:mt-0">
                    <Button as-child size="sm" class="gap-1.5 shadow-sm h-8 px-3 text-xs font-semibold">
                        <Link href="/admin/webinars/create">
                            <Icon icon="solar:add-circle-bold" class="size-3.5" />
                            New Webinar
                        </Link>
                    </Button>
                    <Button as-child variant="outline" size="sm" class="gap-1.5 h-8 px-3 text-xs font-medium border-border/60 bg-background">
                        <Link href="/admin/webinars">
                            All Webinars
                            <Icon icon="solar:arrow-right-linear" class="size-3.5" />
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- â”€â”€ Stat cards row â”€â”€ -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card
                    v-for="card in statCards"
                    :key="card.title"
                    class="border border-border/60 shadow-sm bg-card hover:shadow-md transition-shadow"
                >
                    <CardHeader class="flex flex-row items-start justify-between space-y-0 pb-2 pt-4 px-5">
                        <CardTitle class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ card.title }}
                        </CardTitle>
                        <div :class="`flex h-9 w-9 items-center justify-center rounded-xl ${card.color}`">
                            <Icon :icon="card.icon" class="size-4" />
                        </div>
                    </CardHeader>
                    <CardContent class="px-5 pb-4">
                        <div class="flex items-end justify-between">
                            <p class="text-3xl font-bold tabular-nums text-foreground">{{ card.value }}</p>
                            <span
                                v-if="card.trend"
                                class="inline-flex items-center gap-0.5 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 mb-1"
                            >
                                <Icon icon="solar:arrow-up-linear" class="size-2.5" />
                                {{ card.trend }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ card.sub }}</p>
                    </CardContent>
                </Card>
            </div>

            <!-- â”€â”€ Middle row â”€â”€ -->
            <div class="grid gap-4 lg:grid-cols-3">

                <!-- Active Webinars table (2/3 width) -->
                <Card class="border border-border/60 shadow-sm bg-card lg:col-span-2">
                    <CardHeader class="flex flex-row items-center justify-between border-b border-border/50 pb-3 pt-4 px-5">
                        <div>
                            <CardTitle class="text-sm font-semibold">Active Webinars</CardTitle>
                            <CardDescription class="text-xs mt-0.5">Recently updated sessions</CardDescription>
                        </div>
                        <Button as-child variant="ghost" size="sm" class="h-7 px-2.5 text-xs text-muted-foreground hover:text-foreground">
                            <Link href="/admin/webinars">
                                View all
                                <Icon icon="solar:arrow-right-linear" class="size-3 ml-1" />
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent class="px-0 pb-0">
                        <!-- Empty state -->
                        <div
                            v-if="recentWebinars.length === 0"
                            class="flex flex-col items-center justify-center px-6 py-12 text-center"
                        >
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground mb-3">
                                <Icon icon="solar:monitor-camera-bold-duotone" class="size-5" />
                            </div>
                            <p class="text-sm font-medium">No webinars yet</p>
                            <p class="text-xs text-muted-foreground mt-1 max-w-xs">
                                Create your first webinar to start collecting registrations and tracking views.
                            </p>
                            <Button as-child size="sm" class="mt-4 gap-1.5 text-xs">
                                <Link href="/admin/webinars/create">
                                    <Icon icon="solar:add-circle-bold" class="size-3.5" />
                                    Create webinar
                                </Link>
                            </Button>
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full min-w-135 text-sm">
                                <thead>
                                    <tr class="border-b border-border/50">
                                        <th class="pb-2.5 pl-5 pr-4 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                            Course
                                        </th>
                                        <th class="pb-2.5 pr-4 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                            Participants
                                        </th>
                                        <th class="pb-2.5 pr-4 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                            Status
                                        </th>
                                        <th class="pb-2.5 pr-5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                            Views
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="w in recentWebinars.slice(0, 5)"
                                        :key="w.id"
                                        class="border-b border-border/30 last:border-0 hover:bg-muted/30 transition-colors"
                                    >
                                        <td class="py-3 pl-5 pr-4 align-middle">
                                            <div class="flex items-center gap-3">
                                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                                                    <Icon icon="solar:monitor-camera-bold-duotone" class="size-3.5" />
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="truncate text-sm font-medium text-foreground max-w-45">{{ w.title }}</p>
                                                    <p class="text-[11px] text-muted-foreground">{{ w.host_name }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3 pr-4 align-middle">
                                            <div class="flex items-center gap-1.5">
                                                <div class="flex -space-x-1">
                                                    <div
                                                        v-for="i in Math.min(3, w.registrants_count)"
                                                        :key="i"
                                                        class="h-5 w-5 rounded-full ring-1 ring-background"
                                                        :class="['bg-indigo-400', 'bg-violet-400', 'bg-emerald-400'][i - 1]"
                                                    />
                                                </div>
                                                <span v-if="w.registrants_count > 3" class="text-xs text-muted-foreground font-medium">
                                                    +{{ w.registrants_count - 3 }}
                                                </span>
                                                <span v-else-if="w.registrants_count === 0" class="text-xs text-muted-foreground">0</span>
                                            </div>
                                        </td>
                                        <td class="py-3 pr-4 align-middle">
                                            <span
                                                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                                :class="
                                                    w.has_ended
                                                        ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-400'
                                                        : w.is_published
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400'
                                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400'
                                                "
                                            >
                                                <span class="h-1 w-1 rounded-full"
                                                    :class="w.has_ended ? 'bg-rose-500' : w.is_published ? 'bg-emerald-500' : 'bg-amber-500'"
                                                />
                                                {{ w.has_ended ? 'Ended' : w.is_published ? 'Live' : 'Draft' }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-5 align-middle text-right tabular-nums text-sm font-medium text-foreground">
                                            {{ w.views_count.toLocaleString() }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <!-- Growth Status Overview (1/3 width) -->
                <Card class="border border-border/60 shadow-sm bg-card">
                    <CardHeader class="border-b border-border/50 pb-3 pt-4 px-5">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm font-semibold">Growth Overview</CardTitle>
                            <Icon icon="solar:arrow-right-up-bold-duotone" class="size-4 text-muted-foreground" />
                        </div>
                        <CardDescription class="text-xs mt-0.5">Audience health metrics</CardDescription>
                    </CardHeader>
                    <CardContent class="px-5 pb-5 pt-4">
                        <!-- Donut chart (CSS-based) -->
                        <div class="flex flex-col items-center gap-4">
                            <div class="relative flex items-center justify-center">
                                <!-- Donut ring using conic-gradient -->
                                <div
                                    class="h-32 w-32 rounded-full"
                                    :style="`background: conic-gradient(
                                        hsl(246 80% 60%) 0% ${publishedRatio}%,
                                        hsl(280 65% 60%) ${publishedRatio}% ${publishedRatio + Math.round((stats.draft_webinars / Math.max(1, stats.total_webinars)) * 100)}%,
                                        hsl(220 13% 88%) ${publishedRatio + Math.round((stats.draft_webinars / Math.max(1, stats.total_webinars)) * 100)}% 100%
                                    )`"
                                />
                                <div class="absolute h-20 w-20 rounded-full bg-card flex flex-col items-center justify-center">
                                    <span class="text-xl font-bold text-foreground">{{ stats.total_webinars }}</span>
                                    <span class="text-[10px] text-muted-foreground font-medium">Total</span>
                                </div>
                            </div>

                            <!-- Legend -->
                            <div class="w-full space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2.5 w-2.5 rounded-full bg-indigo-500" />
                                        <span class="text-xs text-muted-foreground">Published</span>
                                    </div>
                                    <span class="text-xs font-semibold text-foreground">{{ stats.published_webinars }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2.5 w-2.5 rounded-full bg-violet-500" />
                                        <span class="text-xs text-muted-foreground">Drafts</span>
                                    </div>
                                    <span class="text-xs font-semibold text-foreground">{{ stats.draft_webinars }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2.5 w-2.5 rounded-full bg-muted-foreground/30" />
                                        <span class="text-xs text-muted-foreground">Not assigned</span>
                                    </div>
                                    <span class="text-xs font-semibold text-foreground">
                                        {{ Math.max(0, stats.total_webinars - stats.published_webinars - stats.draft_webinars) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Activity mini-row -->
                            <div class="w-full flex items-center justify-between rounded-lg bg-muted/50 px-3 py-2">
                                <div class="flex items-center gap-1.5">
                                    <div class="flex -space-x-1">
                                        <div class="h-5 w-5 rounded-full bg-indigo-400 ring-1 ring-background" />
                                        <div class="h-5 w-5 rounded-full bg-emerald-400 ring-1 ring-background" />
                                        <div class="h-5 w-5 rounded-full bg-amber-400 ring-1 ring-background" />
                                    </div>
                                    <span class="text-xs text-muted-foreground">+{{ stats.total_registrants }}</span>
                                </div>
                                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ publishedRatio }}% live</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- â”€â”€ Bottom row â”€â”€ -->
            <div class="grid gap-4 lg:grid-cols-3">

                <!-- Webinar performance (progress bars) -->
                <Card class="border border-border/60 shadow-sm bg-card">
                    <CardHeader class="border-b border-border/50 pb-3 pt-4 px-5">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm font-semibold">Webinar Performance</CardTitle>
                            <Icon icon="solar:arrow-right-up-bold-duotone" class="size-4 text-muted-foreground" />
                        </div>
                        <CardDescription class="text-xs mt-0.5">Top sessions by registrants</CardDescription>
                    </CardHeader>
                    <CardContent class="px-5 pb-5 pt-4">
                        <div v-if="topWebinars.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                            No webinar data yet
                        </div>
                        <div v-else class="space-y-4">
                            <div v-for="w in topWebinars" :key="w.id" class="space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium text-foreground truncate max-w-35">{{ w.title }}</span>
                                    <span class="text-xs font-semibold text-foreground tabular-nums">{{ w.registrants_count }}</span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-linear-to-r from-indigo-500 to-violet-500 transition-all duration-700"
                                        :style="`width: ${(w.registrants_count / maxRegistrants) * 100}%`"
                                    />
                                </div>
                            </div>
                        </div>
                        <p class="mt-4 text-[11px] text-muted-foreground">
                            Top performer shows the strongest registrant count.
                        </p>
                    </CardContent>
                </Card>

                <!-- Engagement overview -->
                <Card class="border border-border/60 shadow-sm bg-card">
                    <CardHeader class="border-b border-border/50 pb-3 pt-4 px-5">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm font-semibold">Engagement Overview</CardTitle>
                            <Icon icon="solar:arrow-right-up-bold-duotone" class="size-4 text-muted-foreground" />
                        </div>
                        <CardDescription class="text-xs mt-0.5">Total spending breakdown</CardDescription>
                    </CardHeader>
                    <CardContent class="px-5 pb-5 pt-4">
                        <div class="space-y-4">
                            <!-- Top metric -->
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-muted-foreground">Total views</p>
                                    <p class="text-2xl font-bold text-foreground tabular-nums mt-0.5">{{ stats.total_views.toLocaleString() }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-muted-foreground text-right">Avg. per webinar</p>
                                    <p class="text-xl font-bold text-foreground tabular-nums mt-0.5 text-right">
                                        {{ stats.total_webinars ? Math.round(stats.total_views / stats.total_webinars).toLocaleString() : 'â€”' }}
                                    </p>
                                </div>
                            </div>

                            <!-- Visual flow bars -->
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-24 text-right text-xs font-medium text-muted-foreground shrink-0">Registrants</div>
                                    <div class="flex-1 h-6 rounded-lg overflow-hidden bg-muted flex items-center">
                                        <div
                                            class="h-full bg-indigo-500 flex items-center justify-end pr-2 text-[10px] font-semibold text-white rounded-lg"
                                            :style="`width: ${Math.max(10, conversionRate)}%`"
                                        >{{ stats.total_registrants }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="w-24 text-right text-xs font-medium text-muted-foreground shrink-0">Views</div>
                                    <div class="flex-1 h-6 rounded-lg overflow-hidden bg-muted flex items-center">
                                        <div
                                            class="h-full bg-violet-500 flex items-center justify-end pr-2 text-[10px] font-semibold text-white rounded-lg"
                                            :style="`width: ${Math.max(10, Math.min(100, stats.total_registrants > 0 ? Math.round((stats.total_views / stats.total_registrants) * 60) : 30))}%`"
                                        >{{ stats.total_views }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="w-24 text-right text-xs font-medium text-muted-foreground shrink-0">Chat msgs</div>
                                    <div class="flex-1 h-6 rounded-lg overflow-hidden bg-muted flex items-center">
                                        <div
                                            class="h-full bg-amber-500 flex items-center justify-end pr-2 text-[10px] font-semibold text-white rounded-lg"
                                            :style="`width: ${Math.max(10, Math.min(100, stats.total_views > 0 ? Math.round((stats.total_chat_messages / stats.total_views) * 100) : 20))}%`"
                                        >{{ stats.total_chat_messages }}</div>
                                    </div>
                                </div>
                            </div>
                            <p class="text-[11px] text-muted-foreground">
                                Registrants take the largest share, chat messages the smallest.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Completion rate card -->
                <Card class="border border-border/60 shadow-sm bg-card">
                    <CardHeader class="border-b border-border/50 pb-3 pt-4 px-5">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm font-semibold">View-Through Rate</CardTitle>
                            <Icon icon="solar:arrow-right-up-bold-duotone" class="size-4 text-muted-foreground" />
                        </div>
                        <CardDescription class="text-xs mt-0.5">Registrant to view conversion</CardDescription>
                    </CardHeader>
                    <CardContent class="px-5 pb-5 pt-4">
                        <!-- Bar chart (CSS-based) -->
                        <div class="flex items-end gap-1.5 h-20 mb-3">
                            <div
                                v-for="(w, i) in recentWebinars.slice(0, 7)"
                                :key="w.id"
                                class="flex-1 rounded-t-sm transition-all duration-500"
                                :class="i === 0 ? 'bg-indigo-500' : i === 1 ? 'bg-indigo-400' : 'bg-indigo-300/60'"
                                :style="`height: ${Math.max(8, (w.views_count / Math.max(1, ...recentWebinars.map(x => x.views_count))) * 80)}px`"
                            />
                            <div v-if="recentWebinars.length === 0" class="flex-1 bg-muted rounded-t-sm" style="height: 80px" />
                        </div>

                        <!-- Big percentage numbers -->
                        <div class="grid grid-cols-2 gap-3 mt-2">
                            <div class="rounded-xl bg-linear-to-br from-indigo-500 to-violet-600 p-4 text-white">
                                <p class="text-2xl font-bold">{{ conversionRate }}%</p>
                                <p class="text-xs opacity-80 mt-0.5">Complete</p>
                                <p class="text-[10px] opacity-60 mt-1">Registrant → View</p>
                            </div>
                            <div class="rounded-xl bg-muted/80 p-4">
                                <p class="text-2xl font-bold text-foreground">{{ engagementRate }}%</p>
                                <p class="text-xs text-muted-foreground mt-0.5">Engaged</p>
                                <p class="text-[10px] text-muted-foreground/70 mt-1">View → Chat msg</p>
                            </div>
                        </div>
                        <p class="mt-3 text-[11px] text-muted-foreground">
                            Conversion rate is derived from registrants who joined a session.
                        </p>
                    </CardContent>
                </Card>

            </div>

        </div>
    </AppLayout>
</template>
