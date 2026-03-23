<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type WebinarInfo = {
    id: number;
    title: string;
};

type Registrant = {
    id: number;
    name: string;
    email: string;
    chat_messages_count: number;
};

type ChatMessage = {
    id: number;
    sender_type: 'host' | 'attendee' | 'system';
    sender_name: string;
    message: string;
    sent_at: string | null;
};

const props = defineProps<{
    webinar: WebinarInfo;
    registrants: Registrant[];
    selectedRegistrantId: number;
    messages: ChatMessage[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Chat', href: '/admin/chats' },
    { title: props.webinar.title, href: `/admin/webinars/${props.webinar.id}/chat` },
];

const activeRegistrant = computed(() =>
    props.registrants.find((item) => item.id === props.selectedRegistrantId) ?? null,
);

const backToListUrl = `/admin/webinars/${props.webinar.id}/chat`;

const nameInitials = (name: string): string => {
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
        return `${parts[0][0]}${parts[1][0]}`.toUpperCase();
    }
    return name.slice(0, 2).toUpperCase();
};

const form = useForm({ message: '' });

const submit = (): void => {
    if (!activeRegistrant.value) {
        return;
    }

    form.post(`/admin/webinars/${props.webinar.id}/chat/${activeRegistrant.value.id}`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head :title="`Chat: ${webinar.title}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <!--
            Mobile:  h-dvh flex-col.
                     – When no registrant selected → full-screen contact list.
                     – When registrant selected    → full-screen conversation.
            Desktop: side-by-side panel layout, both always visible.
        -->
        <div class="flex h-[calc(100dvh-4rem)] flex-col overflow-hidden lg:flex-row lg:gap-4 lg:p-4">

            <!-- ═══════════════════════════════════════════════════════════
                 ATTENDEE LIST PANEL
                 Mobile:  full-screen, visible only when no registrant selected.
                 Desktop: fixed-width sidebar, always visible.
            ════════════════════════════════════════════════════════════ -->
            <aside
                class="flex-col overflow-hidden bg-card lg:flex lg:h-full lg:max-h-full lg:w-80 lg:shrink-0 lg:rounded-xl lg:border lg:shadow-sm"
                :class="activeRegistrant ? 'hidden lg:flex' : 'flex flex-1'"
            >
                <!-- List header -->
                <div class="shrink-0 border-b bg-card px-4 py-3">
                    <h2 class="text-base font-semibold">{{ webinar.title }}</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ registrants.length }} attendee{{ registrants.length !== 1 ? 's' : '' }} with messages
                    </p>
                </div>

                <!-- Registrant list -->
                <div class="flex-1 overflow-y-auto divide-y">
                    <Link
                        v-for="registrant in registrants"
                        :key="registrant.id"
                        :href="`/admin/webinars/${webinar.id}/chat?registrant_id=${registrant.id}`"
                        class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50 active:bg-muted"
                        :class="registrant.id === selectedRegistrantId ? 'bg-muted/60' : ''"
                    >
                        <!-- Avatar with initials -->
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                            {{ nameInitials(registrant.name) }}
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium leading-tight">{{ registrant.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ registrant.email }}</p>
                        </div>

                        <!-- Message count badge -->
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span
                                v-if="registrant.chat_messages_count > 0"
                                class="rounded-full bg-primary px-2 py-0.5 text-[11px] font-medium text-primary-foreground"
                            >
                                {{ registrant.chat_messages_count }}
                            </span>
                            <!-- Chevron arrow -->
                            <svg class="h-4 w-4 text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </Link>

                    <p v-if="registrants.length === 0" class="px-4 py-10 text-center text-sm text-muted-foreground">
                        No attendee messages yet.
                    </p>
                </div>
            </aside>

            <!-- ═══════════════════════════════════════════════════════════
                 CONVERSATION PANEL
                 Mobile:  full-screen, visible only when a registrant is selected.
                 Desktop: flex-1 panel, always visible.
            ════════════════════════════════════════════════════════════ -->
            <section
                class="flex-col overflow-hidden bg-card lg:flex lg:h-full lg:max-h-full lg:flex-1 lg:rounded-xl lg:border lg:shadow-sm"
                :class="activeRegistrant ? 'flex flex-1' : 'hidden lg:flex'"
            >
                <!-- Conversation header with back button (mobile) -->
                <div class="flex shrink-0 items-center gap-3 border-b px-4 py-3">
                    <!-- Mobile back button -->
                    <Link
                        :href="backToListUrl"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted lg:hidden"
                        aria-label="Back to list"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>

                    <div v-if="activeRegistrant" class="flex min-w-0 flex-1 items-center gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                            {{ nameInitials(activeRegistrant.name) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold leading-tight">{{ activeRegistrant.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ activeRegistrant.email }}</p>
                        </div>
                    </div>

                    <p v-else class="text-sm font-medium text-muted-foreground">
                        Select an attendee to view their chat
                    </p>
                </div>

                <!-- Messages list -->
                <div class="flex-1 space-y-2 overflow-y-auto bg-muted/20 px-4 py-4">
                    <template v-if="messages.length > 0">
                        <div
                            v-for="message in messages"
                            :key="message.id"
                            class="flex"
                            :class="message.sender_type === 'host' ? 'justify-end' : 'justify-start'"
                        >
                            <div
                                class="max-w-[75%] rounded-2xl px-3 py-2 text-sm shadow-sm"
                                :class="message.sender_type === 'host'
                                    ? 'rounded-tr-sm bg-primary text-primary-foreground'
                                    : 'rounded-tl-sm bg-background text-foreground border'"
                            >
                                <p class="text-[11px] font-semibold leading-tight opacity-70">{{ message.sender_name }}</p>
                                <p class="mt-0.5 leading-relaxed">{{ message.message }}</p>
                                <p v-if="message.sent_at" class="mt-1 text-[10px] opacity-50 text-right">
                                    {{ message.sent_at }}
                                </p>
                            </div>
                        </div>
                    </template>

                    <p v-else class="py-12 text-center text-sm text-muted-foreground">
                        {{ activeRegistrant
                            ? 'No messages with this attendee yet. Send the first reply below.'
                            : 'Select an attendee from the list to start a conversation.' }}
                    </p>
                </div>

                <!-- Reply input -->
                <form class="shrink-0 border-t bg-background p-3" @submit.prevent="submit">
                    <div class="flex items-center gap-2">
                        <input
                            v-model="form.message"
                            :disabled="!activeRegistrant"
                            class="h-10 flex-1 rounded-full border border-input bg-muted/40 px-4 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                            placeholder="Reply as host…"
                            @keydown.enter.exact.prevent="submit"
                        />
                        <button
                            type="submit"
                            :disabled="form.processing || !activeRegistrant || !form.message.trim()"
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity disabled:opacity-40"
                        >
                            <svg class="h-4 w-4 -rotate-45" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" />
                            </svg>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
