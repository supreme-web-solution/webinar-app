<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type CampaignListItem = {
    id: number;
    title: string;
    title_prefix: string;
    sender_name: string;
    cta_label: string;
    recipients_count: number;
    clicks_count: number;
    clicked_recipients_count: number;
    updated_at: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    campaigns: {
        data: CampaignListItem[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Emails', href: '/admin/emails' },
];

const selectedCampaignIds = ref<number[]>([]);

const toggleCampaignSelection = (campaignId: number, checked: boolean): void => {
    if (checked) {
        if (!selectedCampaignIds.value.includes(campaignId)) {
            selectedCampaignIds.value.push(campaignId);
        }

        return;
    }

    selectedCampaignIds.value = selectedCampaignIds.value.filter((id) => id !== campaignId);
};

const toggleSelectAllOnPage = (checked: boolean): void => {
    if (!checked) {
        selectedCampaignIds.value = [];

        return;
    }

    selectedCampaignIds.value = props.campaigns.data.map((campaign) => campaign.id);
};

const allSelectedOnPage = (): boolean => {
    return props.campaigns.data.length > 0
        && selectedCampaignIds.value.length === props.campaigns.data.length;
};

const deleteCampaign = (campaignId: number, title: string): void => {
    if (!window.confirm(`Delete campaign "${title}"? This cannot be undone.`)) {
        return;
    }

    router.delete(`/admin/emails/${campaignId}`, {
        preserveScroll: true,
    });
};

const bulkDeleteCampaigns = (): void => {
    if (selectedCampaignIds.value.length === 0) {
        return;
    }

    if (!window.confirm(`Delete ${selectedCampaignIds.value.length} selected campaign(s)?`)) {
        return;
    }

    selectedCampaignIds.value.forEach((id) => {
        router.delete(`/admin/emails/${id}`, {
            preserveScroll: true,
        });
    });

    selectedCampaignIds.value = [];
};

watch(
    () => props.campaigns.data.map((campaign) => campaign.id),
    (currentIds) => {
        selectedCampaignIds.value = selectedCampaignIds.value.filter((id) => currentIds.includes(id));
    },
);

const readableLabel = (label: string): string => {
    return label
        .replace(/&laquo;/g, '<<')
        .replace(/&raquo;/g, '>>')
        .replace(/&#039;/g, "'")
        .replace(/<[^>]*>/g, '')
        .trim();
};
</script>

<template>
    <Head title="Email Campaigns" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 md:p-6">
            <!-- Page header -->
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Management</p>
                    <h1 class="mt-0.5 text-2xl font-bold tracking-tight text-foreground">Email Campaigns</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Create and manage campaign emails with attendee CSV imports and click tracking.
                    </p>
                </div>
                <div class="mt-3 sm:mt-0">
                    <Button as-child size="sm" class="h-9 gap-1.5 px-4 font-semibold shadow-sm">
                        <Link href="/admin/emails/create">
                            <Icon icon="solar:add-circle-bold" class="size-4" />
                            New Campaign
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Campaigns table card -->
            <Card class="border border-border/60 shadow-sm">
                <CardHeader class="flex-row items-center justify-between space-y-0 border-b border-border/50 px-5 pb-3 pt-4">
                    <div>
                        <CardTitle class="text-sm font-semibold">All Campaigns</CardTitle>
                        <CardDescription class="mt-0.5 text-xs">
                            Showing {{ campaigns.from ?? 0 }}-{{ campaigns.to ?? 0 }} of {{ campaigns.total }} campaign(s)
                        </CardDescription>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="destructive"
                            size="sm"
                            class="h-7 gap-1.5 px-2.5 text-xs"
                            :disabled="selectedCampaignIds.length === 0"
                            @click="bulkDeleteCampaigns"
                        >
                            <Icon icon="solar:trash-bin-2-linear" class="size-3.5" />
                            Delete Selected ({{ selectedCampaignIds.length }})
                        </Button>
                    </div>
                </CardHeader>

                <CardContent class="px-0 pb-0">
                    <!-- Empty state -->
                    <div
                        v-if="campaigns.total === 0"
                        class="flex flex-col items-center justify-center px-6 py-16 text-center"
                    >
                        <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-500 dark:bg-indigo-950/60 dark:text-indigo-400">
                            <Icon icon="solar:letter-bold-duotone" class="size-7" />
                        </div>
                        <h3 class="text-base font-semibold text-foreground">No campaigns yet</h3>
                        <p class="mt-1.5 max-w-sm text-sm text-muted-foreground">
                            Create your first email campaign to start importing attendees and tracking CTA clicks.
                        </p>
                        <Button as-child size="sm" class="mt-5 gap-1.5 font-semibold shadow-sm">
                            <Link href="/admin/emails/create">
                                <Icon icon="solar:add-circle-bold" class="size-4" />
                                Create your first campaign
                            </Link>
                        </Button>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border/50">
                                    <th class="px-3 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        <input
                                            type="checkbox"
                                            :checked="allSelectedOnPage()"
                                            :aria-label="allSelectedOnPage() ? 'Unselect all campaigns' : 'Select all campaigns'"
                                            class="h-4 w-4 rounded border-border"
                                            @change="toggleSelectAllOnPage(($event.target as HTMLInputElement).checked)"
                                        />
                                    </th>
                                    <th class="px-5 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Campaign
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Sender
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        CTA
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Recipients
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Clicks
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Unique Clickers
                                    </th>
                                    <th class="px-5 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="campaign in campaigns.data"
                                    :key="campaign.id"
                                    class="border-b border-border/30 transition-colors last:border-0 hover:bg-muted/30"
                                >
                                    <td class="px-3 py-3.5 align-middle">
                                        <input
                                            type="checkbox"
                                            :checked="selectedCampaignIds.includes(campaign.id)"
                                            :aria-label="`Select campaign ${campaign.title}`"
                                            class="h-4 w-4 rounded border-border"
                                            @change="toggleCampaignSelection(campaign.id, ($event.target as HTMLInputElement).checked)"
                                        />
                                    </td>
                                    <td class="px-5 py-3.5 align-middle">
                                        <div class="flex items-start gap-3">
                                            <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                                                <Icon icon="solar:letter-bold-duotone" class="size-4" />
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-semibold leading-snug text-foreground">
                                                    {{ campaign.title }}
                                                </p>
                                                <p class="mt-0.5 text-xs text-muted-foreground">
                                                    {{ campaign.title_prefix }}
                                                </p>
                                                <p class="mt-1 text-[11px] text-muted-foreground/70">
                                                    Updated {{ campaign.updated_at || '-' }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3.5 align-middle">
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground">
                                            <Icon icon="solar:user-linear" class="size-3.5 text-muted-foreground/60" />
                                            {{ campaign.sender_name }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 align-middle">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-950/50 dark:text-violet-400">
                                            <Icon icon="solar:link-round-bold" class="size-3" />
                                            {{ campaign.cta_label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 align-middle text-right">
                                        <span class="font-semibold tabular-nums text-foreground">
                                            {{ campaign.recipients_count.toLocaleString() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 align-middle text-right">
                                        <span class="font-semibold tabular-nums text-foreground">
                                            {{ campaign.clicks_count.toLocaleString() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 align-middle text-right">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="campaign.clicked_recipients_count > 0
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400'
                                                : 'bg-muted text-muted-foreground'"
                                        >
                                            {{ campaign.clicked_recipients_count.toLocaleString() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 align-middle text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <Button as-child variant="ghost" size="sm" class="h-7 px-2.5 text-xs font-medium text-primary hover:text-primary/80">
                                                <Link :href="`/admin/emails/${campaign.id}/edit`">
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
                                                    <DropdownMenuItem as-child class="gap-2 text-xs">
                                                        <Link :href="`/admin/emails/${campaign.id}/edit`" class="cursor-pointer">
                                                            <Icon icon="solar:pen-bold" class="size-3.5 text-muted-foreground" />
                                                            Edit Campaign
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem as-child class="gap-2 text-xs">
                                                        <Link
                                                            :href="`/admin/emails/${campaign.id}/send`"
                                                            method="post"
                                                            as="button"
                                                            class="w-full cursor-pointer"
                                                        >
                                                            <Icon icon="solar:letter-bold-duotone" class="size-3.5 text-muted-foreground" />
                                                            Send to All Recipients
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        class="cursor-pointer gap-2 text-xs text-destructive focus:bg-destructive/10 focus:text-destructive"
                                                        @click="deleteCampaign(campaign.id, campaign.title)"
                                                    >
                                                        <Icon icon="solar:trash-bin-2-linear" class="size-3.5" />
                                                        Delete Campaign
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div
                            v-if="campaigns.links?.length > 3"
                            class="flex flex-wrap items-center gap-2 border-t border-border/40 px-5 py-3"
                        >
                            <template v-for="link in campaigns.links" :key="link.label">
                                <Button
                                    v-if="link.url"
                                    as-child
                                    :variant="link.active ? 'default' : 'outline'"
                                    size="sm"
                                    class="h-7 text-xs"
                                >
                                    <Link :href="link.url">{{ readableLabel(link.label) }}</Link>
                                </Button>
                                <Button
                                    v-else
                                    variant="outline"
                                    size="sm"
                                    class="h-7 text-xs"
                                    disabled
                                >
                                    {{ readableLabel(link.label) }}
                                </Button>
                            </template>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
