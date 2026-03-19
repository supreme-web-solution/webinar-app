<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div>
                <h1 class="text-2xl font-semibold">All Webinar Chats</h1>
                <p class="text-sm text-muted-foreground">Open a webinar to view attendee conversations and reply.</p>
            </div>

            <div class="overflow-hidden rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3">Webinar</th>
                            <th class="px-4 py-3">Attendee Messages</th>
                            <th class="px-4 py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="webinar in webinars" :key="webinar.id" class="border-t">
                            <td class="px-4 py-3 font-medium">{{ webinar.title }}</td>
                            <td class="px-4 py-3">{{ webinar.attendee_messages_count }}</td>
                            <td class="px-4 py-3">
                                <Link :href="webinar.chat_url" class="text-primary underline underline-offset-4">
                                    Open Chat
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="webinars.length === 0">
                            <td colspan="3" class="px-4 py-8 text-center text-muted-foreground">
                                No webinar chats yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
