<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { Head } from '@inertiajs/vue3';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type ActivityRow = {
    id: number;
    level: string;
    event: string;
    summary: string;
    context: Record<string, unknown> | null;
    created_at: string | null;
};

type DeliveryRow = {
    id: number;
    email: string;
    status: string;
    email_type: string | null;
    subject: string | null;
    accepted_at: string | null;
    delivered_at: string | null;
    bounced_at: string | null;
    bounce_type: string | null;
    bounce_description: string | null;
    created_at: string | null;
};

type FailedJobRow = {
    id: number;
    job: string;
    failed_at: string | null;
    error: string | null;
};

const props = defineProps<{
    queue: {
        queue_connection: string;
        email_queue: string;
        pending_on_email_queue: number;
        oldest_pending_seconds: number | null;
        failed_email_jobs: number;
        worker_hint: string | null;
    };
    failedJobs: FailedJobRow[];
    activity: ActivityRow[];
    recentDeliveries: DeliveryRow[];
    primaryProvider: string;
    webhookConfigured: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Email activity', href: '/admin/email-activity' },
];

function levelClass(level: string): string {
    switch (level) {
        case 'error':
        case 'critical':
            return 'bg-red-100 text-red-800';
        case 'warning':
            return 'bg-amber-100 text-amber-800';
        default:
            return 'bg-sky-100 text-sky-800';
    }
}

function statusClass(status: string): string {
    switch (status) {
        case 'delivered':
            return 'bg-emerald-100 text-emerald-800';
        case 'accepted':
            return 'bg-amber-100 text-amber-800';
        case 'bounced':
        case 'spam_complaint':
            return 'bg-red-100 text-red-800';
        case 'suppressed':
            return 'bg-gray-200 text-gray-700';
        default:
            return 'bg-gray-100 text-gray-700';
    }
}

function formatPendingAge(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }
    if (seconds < 60) {
        return `${seconds}s`;
    }
    const minutes = Math.floor(seconds / 60);
    return `${minutes}m`;
}
</script>

<template>
    <Head title="Email activity" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Email activity</h1>
                <p class="text-sm text-muted-foreground">
                    Quick check after you send — queue status, recent send results, and provider delivery (latest entries only).
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <Icon icon="solar:server-bold-duotone" class="size-5 text-muted-foreground" />
                        Queue &amp; workers
                    </CardTitle>
                    <CardDescription>Background sends use the &quot;{{ queue.email_queue }}&quot; queue</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border px-3 py-2">
                        <p class="text-xs text-muted-foreground">Connection</p>
                        <p class="font-mono text-sm font-medium">{{ queue.queue_connection }}</p>
                    </div>
                    <div class="rounded-lg border px-3 py-2">
                        <p class="text-xs text-muted-foreground">Pending jobs</p>
                        <p class="text-2xl font-bold">{{ queue.pending_on_email_queue }}</p>
                    </div>
                    <div class="rounded-lg border px-3 py-2">
                        <p class="text-xs text-muted-foreground">Oldest pending</p>
                        <p class="text-sm font-medium">{{ formatPendingAge(queue.oldest_pending_seconds) }}</p>
                    </div>
                    <div class="rounded-lg border px-3 py-2">
                        <p class="text-xs text-muted-foreground">Failed email jobs (total)</p>
                        <p class="text-2xl font-bold">{{ queue.failed_email_jobs }}</p>
                    </div>
                </CardContent>
                <CardContent v-if="queue.worker_hint" class="border-t pt-4">
                    <div
                        class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950"
                    >
                        <Icon icon="solar:danger-triangle-bold-duotone" class="mt-0.5 size-4 shrink-0" />
                        <p>{{ queue.worker_hint }}</p>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="failedJobs.length > 0">
                <CardHeader>
                    <CardTitle>Recent queue failures</CardTitle>
                    <CardDescription>Latest failed send jobs from the queue (not paginated)</CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="job in failedJobs"
                        :key="job.id"
                        class="rounded-lg border border-red-200 bg-red-50/50 px-3 py-2 text-sm"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ job.job }}</span>
                            <span class="text-xs text-muted-foreground">{{ job.failed_at ?? '—' }}</span>
                        </div>
                        <p class="mt-1 font-mono text-xs text-red-900">{{ job.error }}</p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Recent activity</CardTitle>
                    <CardDescription>Sends, batches, and errors recorded for your account</CardDescription>
                </CardHeader>
                <CardContent class="space-y-2">
                    <p v-if="activity.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                        No activity yet. Send a campaign or webinar email — results will show here.
                    </p>
                    <div
                        v-for="row in activity"
                        :key="row.id"
                        class="flex flex-col gap-1 rounded-lg border px-3 py-2 text-sm sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0 flex-1">
                            <span
                                class="mr-2 inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                                :class="levelClass(row.level)"
                            >
                                {{ row.level }}
                            </span>
                            <span>{{ row.summary }}</span>
                        </div>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ row.created_at ?? '—' }}</span>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Provider delivery (Postmark)</CardTitle>
                    <CardDescription>
                        Latest per-recipient status when using Postmark. Primary provider: {{ primaryProvider }}.
                    </CardDescription>
                </CardHeader>
                <CardContent v-if="primaryProvider !== 'postmark'" class="pb-6">
                    <p class="text-sm text-muted-foreground">
                        Delivery rows only populate for sends that go through Postmark. Other providers still appear in activity above.
                    </p>
                </CardContent>
                <CardContent v-if="!webhookConfigured" class="border-b pb-4">
                    <p class="text-sm text-amber-800">
                        Postmark webhooks are not configured — you will see &quot;accepted&quot; but may not see delivered/bounced updates.
                    </p>
                </CardContent>
                <CardContent class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead>
                            <tr class="border-b text-muted-foreground">
                                <th class="pb-2 pr-4 font-medium">When</th>
                                <th class="pb-2 pr-4 font-medium">Email</th>
                                <th class="pb-2 pr-4 font-medium">Status</th>
                                <th class="pb-2 font-medium">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="recentDeliveries.length === 0">
                                <td colspan="4" class="py-6 text-center text-muted-foreground">No delivery records yet.</td>
                            </tr>
                            <tr
                                v-for="row in recentDeliveries"
                                :key="row.id"
                                class="border-b border-border/60"
                            >
                                <td class="py-2 pr-4 text-xs text-muted-foreground">{{ row.created_at ?? '—' }}</td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ row.email }}</td>
                                <td class="py-2 pr-4">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                        :class="statusClass(row.status)"
                                    >
                                        {{ row.status.replaceAll('_', ' ') }}
                                    </span>
                                </td>
                                <td class="py-2 text-xs text-muted-foreground">
                                    <span v-if="row.bounce_description">{{ row.bounce_description }}</span>
                                    <span v-else-if="row.delivered_at">Delivered {{ row.delivered_at }}</span>
                                    <span v-else-if="row.accepted_at">Accepted {{ row.accepted_at }}</span>
                                    <span v-else>—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
