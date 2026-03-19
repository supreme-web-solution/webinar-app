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

const form = useForm({
    message: '',
});

const submit = (): void => {
    if (!activeRegistrant.value) {
        return;
    }

    form.post(`/admin/webinars/${props.webinar.id}/chat/${activeRegistrant.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <Head :title="`Chat: ${webinar.title}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 gap-4 rounded-xl p-4">
            <aside class="h-[78vh] w-80 shrink-0 overflow-hidden rounded-xl border bg-card">
                <div class="border-b bg-muted/40 px-4 py-3">
                    <h2 class="font-semibold">Attendees</h2>
                </div>
                <div class="h-[78vh] overflow-y-auto">
                    <Link
                        v-for="registrant in registrants"
                        :key="registrant.id"
                        :href="`/admin/webinars/${webinar.id}/chat?registrant_id=${registrant.id}`"
                        class="block border-b px-4 py-3 text-sm hover:bg-muted/50"
                        :class="registrant.id === selectedRegistrantId ? 'bg-muted' : ''"
                    >
                        <p class="font-medium">{{ registrant.name }}</p>
                        <p class="text-xs text-muted-foreground">{{ registrant.email }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">{{ registrant.chat_messages_count }} messages</p>
                    </Link>
                </div>
            </aside>

            <section class="flex h-[78vh] min-w-0 flex-1 flex-col overflow-hidden rounded-xl border bg-card">
                <div class="border-b px-4 py-3">
                    <h2 class="font-semibold">
                        {{ activeRegistrant ? `Conversation with ${activeRegistrant.name}` : 'Select attendee' }}
                    </h2>
                </div>

                <div class="flex-1 space-y-2 overflow-y-auto bg-muted/20 p-4">
                    <div
                        v-for="message in messages"
                        :key="message.id"
                        class="max-w-[78%] rounded-md border px-3 py-2 text-sm"
                        :class="message.sender_type === 'host' ? 'ml-auto bg-primary text-primary-foreground' : 'bg-background text-foreground'"
                    >
                        <p class="text-xs font-semibold opacity-80">{{ message.sender_name }}</p>
                        <p>{{ message.message }}</p>
                    </div>
                    <p v-if="messages.length === 0" class="text-sm text-muted-foreground">
                        No messages for this attendee yet.
                    </p>
                </div>

                <form class="border-t bg-background p-3" @submit.prevent="submit">
                    <div class="flex items-center gap-2">
                        <input
                            v-model="form.message"
                            :disabled="!activeRegistrant"
                            class="h-10 flex-1 rounded-md border border-input bg-transparent px-3 text-sm"
                            placeholder="Reply as host"
                        />
                        <button
                            type="submit"
                            :disabled="form.processing || !activeRegistrant"
                            class="h-10 rounded-md bg-primary px-4 text-sm text-primary-foreground disabled:opacity-50"
                        >
                            Send
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
