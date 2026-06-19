<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
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

type DeliveryRow = {
    id: number;
    email: string;
    status: string;
    email_type: string | null;
    source_type: string;
    subject: string | null;
    accepted_at: string | null;
    delivered_at: string | null;
    bounced_at: string | null;
    bounce_type: string | null;
    created_at: string | null;
};

const props = defineProps<{
    days: number;
    stats: {
        accepted: number;
        delivered: number;
        bounced: number;
        spam_complaint: number;
        suppressed: number;
        pending: number;
        delivery_rate: number | null;
    };
    byEmailType: Record<string, number>;
    recentDeliveries: DeliveryRow[];
    webhookConfigured: boolean;
    primaryProvider: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Postmark delivery', href: '/admin/postmark-delivery' },
];

const statCards = computed(() => [
    {
        label: 'Accepted by Postmark',
        value: props.stats.accepted,
        icon: 'solar:letter-bold-duotone',
        hint: 'API accepted — queued for delivery',
    },
    {
        label: 'Delivered',
        value: props.stats.delivered,
        icon: 'solar:check-circle-bold-duotone',
        hint: 'Confirmed by Postmark webhook',
    },
    {
        label: 'Pending delivery',
        value: props.stats.pending,
        icon: 'solar:clock-circle-bold-duotone',
        hint: 'Accepted but no delivery webhook yet',
    },
    {
        label: 'Bounced',
        value: props.stats.bounced,
        icon: 'solar:close-circle-bold-duotone',
        hint: 'Recipient server rejected',
    },
    {
        label: 'Suppressed',
        value: props.stats.suppressed,
        icon: 'solar:forbidden-circle-bold-duotone',
        hint: 'Inactive at Postmark (406) or similar',
    },
    {
        label: 'Spam complaints',
        value: props.stats.spam_complaint,
        icon: 'solar:danger-triangle-bold-duotone',
        hint: 'Marked as spam by recipient',
    },
]);

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

function formatEmailType(value: string | null): string {
    if (!value) {
        return '—';
    }

    return value.replaceAll('_', ' ');
}
</script>

<template>
    <Head title="Postmark delivery" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Postmark delivery</h1>
                    <p class="text-sm text-muted-foreground">
                        Tracks emails sent via Postmark only (last {{ days }} days). Resend, SMTP, and other providers are not included.
                    </p>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <span class="text-muted-foreground">Period:</span>
                    <Link
                        href="/admin/postmark-delivery?days=7"
                        class="rounded-md px-2 py-1"
                        :class="days === 7 ? 'bg-primary text-primary-foreground' : 'bg-muted'"
                    >7d</Link>
                    <Link
                        href="/admin/postmark-delivery?days=30"
                        class="rounded-md px-2 py-1"
                        :class="days === 30 ? 'bg-primary text-primary-foreground' : 'bg-muted'"
                    >30d</Link>
                    <Link
                        href="/admin/postmark-delivery?days=90"
                        class="rounded-md px-2 py-1"
                        :class="days === 90 ? 'bg-primary text-primary-foreground' : 'bg-muted'"
                    >90d</Link>
                </div>
            </div>

            <Card v-if="primaryProvider !== 'postmark'" class="border-amber-200 bg-amber-50">
                <CardContent class="flex items-start gap-3 pt-6 text-sm text-amber-900">
                    <Icon icon="solar:info-circle-bold-duotone" class="mt-0.5 size-5 shrink-0" />
                    <p>
                        Your primary email provider is <strong>{{ primaryProvider }}</strong>. Only sends that actually go through Postmark appear here.
                    </p>
                </CardContent>
            </Card>

            <Card v-if="!webhookConfigured" class="border-amber-200 bg-amber-50">
                <CardContent class="flex items-start gap-3 pt-6 text-sm text-amber-900">
                    <Icon icon="solar:info-circle-bold-duotone" class="mt-0.5 size-5 shrink-0" />
                    <div>
                        <p class="font-medium">Delivery webhooks not configured</p>
                        <p class="mt-1">
                            Set <code class="rounded bg-amber-100 px-1">POSTMARK_WEBHOOK_TOKEN</code> in Forge and add a Postmark webhook URL:
                            <code class="mt-1 block break-all rounded bg-amber-100 px-2 py-1">https://your-domain.com/webhooks/postmark/YOUR_TOKEN</code>
                            Enable <strong>Delivery</strong>, <strong>Bounce</strong>, and <strong>Spam complaint</strong> events.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Card v-for="card in statCards" :key="card.label">
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">{{ card.label }}</CardTitle>
                        <Icon :icon="card.icon" class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ card.value.toLocaleString() }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ card.hint }}</p>
                    </CardContent>
                </Card>
            </div>

            <Card v-if="stats.delivery_rate !== null">
                <CardHeader>
                    <CardTitle>Delivery rate</CardTitle>
                    <CardDescription>Delivered ÷ (delivered + bounced) for resolved Postmark outcomes</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="text-3xl font-bold">{{ stats.delivery_rate }}%</div>
                </CardContent>
            </Card>

            <Card v-if="Object.keys(byEmailType).length > 0">
                <CardHeader>
                    <CardTitle>By email type</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="(count, type) in byEmailType"
                            :key="type"
                            class="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                        >
                            <span class="capitalize">{{ formatEmailType(type) }}</span>
                            <span class="font-semibold">{{ count }}</span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Recent Postmark sends</CardTitle>
                    <CardDescription>Latest 100 records in the selected period</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead>
                            <tr class="border-b text-muted-foreground">
                                <th class="pb-2 pr-4 font-medium">Email</th>
                                <th class="pb-2 pr-4 font-medium">Status</th>
                                <th class="pb-2 pr-4 font-medium">Type</th>
                                <th class="pb-2 pr-4 font-medium">Accepted</th>
                                <th class="pb-2 pr-4 font-medium">Delivered</th>
                                <th class="pb-2 font-medium">Bounced</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="recentDeliveries.length === 0">
                                <td colspan="6" class="py-8 text-center text-muted-foreground">
                                    No Postmark delivery records yet. Send via Postmark to populate this page.
                                </td>
                            </tr>
                            <tr
                                v-for="row in recentDeliveries"
                                :key="row.id"
                                class="border-b border-border/60"
                            >
                                <td class="py-2 pr-4 font-mono text-xs">{{ row.email }}</td>
                                <td class="py-2 pr-4">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                        :class="statusClass(row.status)"
                                    >
                                        {{ row.status.replaceAll('_', ' ') }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4 capitalize">{{ formatEmailType(row.email_type) }}</td>
                                <td class="py-2 pr-4 text-xs text-muted-foreground">{{ row.accepted_at ?? '—' }}</td>
                                <td class="py-2 pr-4 text-xs text-muted-foreground">{{ row.delivered_at ?? '—' }}</td>
                                <td class="py-2 text-xs text-muted-foreground">
                                    {{ row.bounced_at ?? '—' }}
                                    <span v-if="row.bounce_type" class="block text-[11px]">{{ row.bounce_type }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
