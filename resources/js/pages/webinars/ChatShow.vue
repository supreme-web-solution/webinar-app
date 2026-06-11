<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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

// Delete
const deletingMessageId = ref<number | null>(null);
const confirmClearAll = ref(false);

const deleteMessage = (messageId: number): void => {
    if (!activeRegistrant.value) {
return;
}

    deletingMessageId.value = messageId;
    router.delete(
        `/admin/webinars/${props.webinar.id}/chat/${activeRegistrant.value.id}/messages/${messageId}`,
        {
            preserveScroll: true,
            onFinish: () => {
 deletingMessageId.value = null; 
},
        },
    );
};

const clearAllMessages = (): void => {
    if (!activeRegistrant.value) {
return;
}

    confirmClearAll.value = false;
    router.delete(
        `/admin/webinars/${props.webinar.id}/chat/${activeRegistrant.value.id}/messages`,
        { preserveScroll: false },
    );
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
        <div class="flex h-[calc(100dvh-4rem)] flex-col overflow-hidden lg:flex-row lg:gap-4 lg:p-5">

            <!-- ═══════════════════════════════════════════════════════════
                 ATTENDEE LIST PANEL
                 Mobile:  full-screen, visible only when no registrant selected.
                 Desktop: fixed-width sidebar, always visible.
            ════════════════════════════════════════════════════════════ -->
            <aside
                class="flex-col overflow-hidden bg-card lg:flex lg:h-full lg:max-h-full lg:w-80 lg:shrink-0 lg:rounded-2xl lg:border lg:border-border/60 lg:shadow-sm"
                :class="activeRegistrant ? 'hidden lg:flex' : 'flex flex-1'"
            >
                <!-- List header -->
                <div class="shrink-0 border-b border-border/50 bg-card px-4 py-3.5">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400">
                            <Icon icon="solar:monitor-camera-bold-duotone" class="size-4" />
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold leading-tight text-foreground">{{ webinar.title }}</p>
                            <p class="text-[11px] text-muted-foreground">
                                {{ registrants.length }} attendee{{ registrants.length !== 1 ? 's' : '' }} with messages
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Registrant list -->
                <div class="flex-1 overflow-y-auto divide-y">
                    <Link
                        v-for="registrant in registrants"
                        :key="registrant.id"
                        :href="`/admin/webinars/${webinar.id}/chat?registrant_id=${registrant.id}`"
                        class="flex items-center gap-3 px-4 py-3.5 transition-colors hover:bg-muted/40"
                        :class="registrant.id === selectedRegistrantId ? 'bg-primary/5 border-l-2 border-l-primary' : 'border-l-2 border-l-transparent'"
                    >
                        <!-- Avatar with initials -->
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold transition-colors"
                            :class="registrant.id === selectedRegistrantId
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-primary/10 text-primary'"
                        >
                            {{ nameInitials(registrant.name) }}
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold leading-tight" :class="registrant.id === selectedRegistrantId ? 'text-primary' : 'text-foreground'">{{ registrant.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ registrant.email }}</p>
                        </div>

                        <!-- Message count badge + chevron -->
                        <div class="flex shrink-0 items-center gap-1.5">
                            <span
                                v-if="registrant.chat_messages_count > 0"
                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                :class="registrant.id === selectedRegistrantId
                                    ? 'bg-primary/20 text-primary'
                                    : 'bg-primary text-primary-foreground'"
                            >
                                {{ registrant.chat_messages_count }}
                            </span>
                            <Icon icon="solar:alt-arrow-right-bold" class="size-3.5 text-muted-foreground/40" />
                        </div>
                    </Link>

                    <div v-if="registrants.length === 0" class="flex flex-col items-center justify-center px-4 py-12 text-center">
                        <Icon icon="solar:chat-round-dots-bold-duotone" class="size-8 text-muted-foreground/30 mb-3" />
                        <p class="text-sm font-medium text-muted-foreground">No messages yet</p>
                        <p class="mt-1 text-xs text-muted-foreground/70">Attendee messages will appear here.</p>
                    </div>
                </div>
            </aside>

            <!-- ═══════════════════════════════════════════════════════════
                 CONVERSATION PANEL
                 Mobile:  full-screen, visible only when a registrant is selected.
                 Desktop: flex-1 panel, always visible.
            ════════════════════════════════════════════════════════════ -->
            <section
                class="flex-col overflow-hidden bg-card lg:flex lg:h-full lg:max-h-full lg:flex-1 lg:rounded-2xl lg:border lg:border-border/60 lg:shadow-sm"
                :class="activeRegistrant ? 'flex flex-1' : 'hidden lg:flex'"
            >
                <!-- Conversation header -->
                <div class="flex shrink-0 items-center gap-3 border-b border-border/50 bg-card px-4 py-3.5">
                    <!-- Mobile back button -->
                    <Link
                        :href="backToListUrl"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted lg:hidden"
                        aria-label="Back to list"
                    >
                        <Icon icon="solar:arrow-left-bold" class="size-5" />
                    </Link>

                    <div v-if="activeRegistrant" class="flex min-w-0 flex-1 items-center gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground text-xs font-bold shadow-sm">
                            {{ nameInitials(activeRegistrant.name) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold leading-tight text-foreground">{{ activeRegistrant.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ activeRegistrant.email }}</p>
                        </div>
                        <div class="ml-auto flex items-center gap-2">
                            <!-- Clear all confirm -->
                            <template v-if="messages.length > 0">
                                <template v-if="!confirmClearAll">
                                    <button
                                        type="button"
                                        class="flex items-center gap-1.5 rounded-lg border border-destructive/30 px-2.5 py-1 text-[11px] font-medium text-destructive/70 transition-colors hover:bg-destructive/10 hover:text-destructive"
                                        @click="confirmClearAll = true"
                                    >
                                        <Icon icon="solar:trash-bin-trash-bold" class="size-3.5" />
                                        Clear all
                                    </button>
                                </template>
                                <template v-else>
                                    <span class="text-[11px] text-destructive font-medium">Delete all messages?</span>
                                    <button
                                        type="button"
                                        class="rounded-lg bg-destructive px-2.5 py-1 text-[11px] font-semibold text-white transition-colors hover:bg-destructive/90"
                                        @click="clearAllMessages"
                                    >Yes, delete</button>
                                    <button
                                        type="button"
                                        class="rounded-lg border px-2.5 py-1 text-[11px] font-medium transition-colors hover:bg-muted"
                                        @click="confirmClearAll = false"
                                    >Cancel</button>
                                </template>
                            </template>
                        </div>
                    </div>

                    <div v-else class="flex flex-1 items-center gap-2.5 text-muted-foreground">
                        <Icon icon="solar:chat-round-dots-bold-duotone" class="size-5 opacity-40" />
                        <p class="text-sm font-medium">Select an attendee to view their chat</p>
                    </div>
                </div>

                <!-- Messages list -->
                <div class="flex-1 space-y-3 overflow-y-auto bg-muted/10 px-4 py-5">
                    <template v-if="messages.length > 0">
                        <div
                            v-for="message in messages"
                            :key="message.id"
                            class="group flex items-end gap-1.5"
                            :class="message.sender_type === 'attendee' ? 'justify-start' : 'justify-end'"
                        >
                            <!-- Delete button (attendee messages: shown after bubble; host/system: shown before bubble) -->
                            <button
                                v-if="message.sender_type !== 'attendee'"
                                type="button"
                                class="mb-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-muted-foreground/40 opacity-0 transition-opacity hover:bg-destructive/10 hover:text-destructive group-hover:opacity-100"
                                :disabled="deletingMessageId === message.id"
                                :title="'Delete message'"
                                @click="deleteMessage(message.id)"
                            >
                                <Icon icon="solar:trash-bin-minimalistic-bold" class="size-3.5" />
                            </button>

                            <div
                                class="max-w-[75%] rounded-2xl px-3.5 py-2.5 text-sm shadow-sm"
                                :class="message.sender_type === 'host'
                                    ? 'rounded-tr-none bg-primary text-primary-foreground'
                                    : message.sender_type === 'system'
                                        ? 'rounded-tr-none bg-primary/15 text-primary border border-primary/30 dark:bg-primary/20 dark:text-primary dark:border-primary/30'
                                        : 'rounded-tl-none bg-background text-foreground border border-border/60'"
                            >
                                <p class="text-[11px] font-bold leading-tight"
                                    :class="message.sender_type === 'host'
                                        ? 'opacity-70'
                                        : message.sender_type === 'system'
                                            ? 'opacity-60'
                                            : 'text-muted-foreground'"
                                >{{ message.sender_name }}</p>
                                <p class="mt-1 leading-relaxed">{{ message.message }}</p>
                                <p v-if="message.sent_at" class="mt-1.5 text-[10px] opacity-50 text-right">
                                    {{ message.sent_at }}
                                </p>
                            </div>

                            <!-- Delete button for attendee messages (shown after bubble) -->
                            <button
                                v-if="message.sender_type === 'attendee'"
                                type="button"
                                class="mb-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-muted-foreground/40 opacity-0 transition-opacity hover:bg-destructive/10 hover:text-destructive group-hover:opacity-100"
                                :disabled="deletingMessageId === message.id"
                                :title="'Delete message'"
                                @click="deleteMessage(message.id)"
                            >
                                <Icon icon="solar:trash-bin-minimalistic-bold" class="size-3.5" />
                            </button>
                        </div>
                    </template>

                    <div v-else class="flex flex-col items-center justify-center py-16 text-center">
                        <Icon
                            :icon="activeRegistrant ? 'solar:chat-round-dots-bold-duotone' : 'solar:users-group-rounded-bold-duotone'"
                            class="size-10 text-muted-foreground/20 mb-3"
                        />
                        <p class="text-sm font-medium text-muted-foreground">
                            {{ activeRegistrant ? 'No messages yet' : 'No attendee selected' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground/60">
                            {{ activeRegistrant
                                ? 'Send the first reply below to start the conversation.'
                                : 'Select an attendee from the list to view their chat.' }}
                        </p>
                    </div>
                </div>

                <!-- Reply input -->
                <form class="shrink-0 border-t border-border/50 bg-card px-4 py-3" @submit.prevent="submit">
                    <div class="flex items-center gap-2">
                        <div class="relative flex-1">
                            <input
                                v-model="form.message"
                                :disabled="!activeRegistrant"
                                class="h-11 w-full rounded-2xl border border-input bg-muted/40 pl-4 pr-12 text-sm placeholder:text-muted-foreground/60 focus:outline-none focus:ring-2 focus:ring-primary/30 disabled:cursor-not-allowed disabled:opacity-50"
                                placeholder="Reply as host…"
                                @keydown.enter.exact.prevent="submit"
                            />
                        </div>
                        <button
                            type="submit"
                            :disabled="form.processing || !activeRegistrant || !form.message.trim()"
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-sm transition-all hover:bg-primary/90 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <Icon icon="solar:plain-bold" class="size-4" />
                        </button>
                    </div>
                    <p class="mt-2 text-[11px] text-muted-foreground/50 text-center">
                        Press Enter to send · Replies are visible to the attendee in their chat
                    </p>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
