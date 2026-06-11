<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type WebinarChatRow = {
    id: number;
    title: string;
    attendee_messages_count: number;
    chat_url: string;
};

defineProps<{
    webinars: WebinarChatRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Chat', href: '/admin/chats' },
];
</script>

<template>
    <Head title="Chat" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-5 p-4 pb-10 md:p-6">

            <!-- Page header -->
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400 shadow-sm">
                    <Icon icon="solar:chat-round-dots-bold-duotone" class="size-6" />
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Management</p>
                    <h1 class="mt-0.5 text-2xl font-bold tracking-tight text-foreground">Webinar Chats</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Open a webinar to view attendee conversations and reply as host.
                    </p>
                </div>
            </div>

            <!-- Chats card -->
            <Card class="border border-border/60 shadow-sm">
                <CardHeader class="border-b border-border/50 pb-3 pt-4 px-5 flex-row items-center justify-between space-y-0">
                    <div>
                        <CardTitle class="text-sm font-semibold">All Webinars</CardTitle>
                        <CardDescription class="text-xs mt-0.5">
                            {{ webinars.length }} webinar{{ webinars.length === 1 ? '' : 's' }} with chat enabled
                        </CardDescription>
                    </div>
                </CardHeader>

                <CardContent class="px-0 pb-0">
                    <!-- Empty state -->
                    <div v-if="webinars.length === 0" class="flex flex-col items-center justify-center px-6 py-16 text-center">
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-100 text-violet-500 dark:bg-violet-950/60 dark:text-violet-400 mb-4">
                            <Icon icon="solar:chat-round-dots-bold-duotone" class="size-7" />
                        </div>
                        <h3 class="text-base font-semibold text-foreground">No chats yet</h3>
                        <p class="mt-1.5 max-w-sm text-sm text-muted-foreground">
                            Once attendees send messages during a webinar, their conversations will appear here.
                        </p>
                    </div>

                    <div v-else class="divide-y divide-border/40">
                        <Link
                            v-for="webinar in webinars"
                            :key="webinar.id"
                            :href="webinar.chat_url"
                            class="flex items-center gap-4 px-5 py-4 transition-colors hover:bg-muted/30 group"
                        >
                            <!-- Icon badge -->
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400">
                                <Icon icon="solar:monitor-camera-bold-duotone" class="size-5" />
                            </div>

                            <!-- Title + subtitle -->
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-foreground text-sm leading-snug group-hover:text-primary transition-colors">
                                    {{ webinar.title }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Click to moderate attendee conversations
                                </p>
                            </div>

                            <!-- Message count badge -->
                            <div class="flex shrink-0 items-center gap-3">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="webinar.attendee_messages_count > 0
                                        ? 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-400'
                                        : 'bg-muted text-muted-foreground'"
                                >
                                    <Icon icon="solar:chat-line-bold" class="size-3" />
                                    {{ webinar.attendee_messages_count.toLocaleString() }}
                                    {{ webinar.attendee_messages_count === 1 ? 'message' : 'messages' }}
                                </span>
                                <Icon icon="solar:arrow-right-linear" class="size-4 text-muted-foreground/50 group-hover:text-primary transition-colors" />
                            </div>
                        </Link>
                    </div>
                </CardContent>
            </Card>

        </div>
    </AppLayout>
</template>
