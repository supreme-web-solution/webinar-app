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

type LogRow = {
    id: string;
    level: string;
    message: string;
    created_at: string | null;
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
    logs: LogRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Email log', href: '/admin/email-activity' },
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

function formatPendingAge(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }
    if (seconds < 60) {
        return `${seconds}s`;
    }

    return `${Math.floor(seconds / 60)}m`;
}
</script>

<template>
    <Head title="Email log" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Email log</h1>
                <p class="text-sm text-muted-foreground">
                    Latest email-related events — sends, queue jobs, and per-recipient results. Newest first (limited list, not full history).
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <Icon icon="solar:server-bold-duotone" class="size-5 text-muted-foreground" />
                        Send queue
                    </CardTitle>
                    <CardDescription>Whether background email jobs are backing up</CardDescription>
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
                        <p class="text-xs text-muted-foreground">Oldest waiting</p>
                        <p class="text-sm font-medium">{{ formatPendingAge(queue.oldest_pending_seconds) }}</p>
                    </div>
                    <div class="rounded-lg border px-3 py-2">
                        <p class="text-xs text-muted-foreground">Failed jobs (total)</p>
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

            <Card>
                <CardHeader>
                    <CardTitle>Latest log</CardTitle>
                    <CardDescription>Batches, individual recipients, and queue errors in one timeline</CardDescription>
                </CardHeader>
                <CardContent class="space-y-2">
                    <p v-if="logs.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                        Nothing logged yet. After you send mail, entries will show up here.
                    </p>
                    <div
                        v-for="row in logs"
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
                            <span class="break-words">{{ row.message }}</span>
                        </div>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ row.created_at ?? '—' }}</span>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
