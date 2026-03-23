<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

type Offer = {
    id: number;
    title: string;
    description: string | null;
    trigger_second: number;
    button_text: string;
    button_url: string;
    display_mode: 'chat' | 'popup' | 'pinned';
};

type ScheduledMessage = {
    id: number;
    trigger_second: number;
    sender_name: string | null;
    message: string;
};

type WebinarRoomPayload = {
    id: number;
    title: string;
    host_name: string;
    description: string | null;
    video_source: 'youtube' | 'vimeo' | 'direct';
    video_url: string;
    video_duration_seconds: number | null;
    min_viewers: number;
    max_viewers: number;
    offers: Offer[];
    scheduled_messages: ScheduledMessage[];
};

type RegistrantPayload = {
    name: string;
    email: string;
};

const props = defineProps<{
    webinar: WebinarRoomPayload;
    registrant: RegistrantPayload;
    chatToken?: string;
    accessRequired?: boolean;
    accessUrl?: string | null;
    roomEnded?: boolean;
    endedMessage?: string | null;
}>();

const gateForm = useForm({
    name: props.registrant.name ?? '',
    email: props.registrant.email ?? '',
});

const elapsedSeconds = ref(0);
const viewerCount = ref(props.webinar.min_viewers);
const chatInput = ref('');
const roomPanel = ref<'chat' | 'offers'>('chat');
const chatMessages = ref<Array<{ id: string; sender: string; message: string; self?: boolean; at?: string }>>([]);
const pinnedOffer = ref<Offer | null>(null);
const popupOffer = ref<Offer | null>(null);
const droppedOffers = ref<Offer[]>([]);
const iframeMuted = ref(true);
const directVideoRef = ref<HTMLVideoElement | null>(null);
const reactionBubbles = ref<Array<{ id: string; emoji: string; left: number }>>([]);

let timer: ReturnType<typeof setInterval> | null = null;
let viewersTimer: ReturnType<typeof setInterval> | null = null;
let chatPollTimer: ReturnType<typeof setInterval> | null = null;

const firedMessageIds = new Set<number>();
const firedOfferIds = new Set<number>();

const escapeHtml = (raw: string): string =>
    raw
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

const linkifyMessage = (message: string): string => {
    const escaped = escapeHtml(message);
    const urlPattern = /(https?:\/\/[^\s<]+)/g;

    return escaped.replace(urlPattern, (url) => {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2 break-all">${url}</a>`;
    });
};

const extractOfferIdFromMessage = (messageId: string): number | null => {
    if (!messageId.startsWith('offer-')) {
        return null;
    }

    const raw = Number(messageId.replace('offer-', ''));
    return Number.isFinite(raw) ? raw : null;
};

const onChatMessageClick = (event: MouseEvent, messageId: string): void => {
    const target = event.target as HTMLElement | null;
    if (!target) {
        return;
    }

    const anchor = target.closest('a');
    if (!anchor) {
        return;
    }

    const offerId = extractOfferIdFromMessage(messageId);
    if (!offerId) {
        return;
    }

    const offer = props.webinar.offers.find((item) => item.id === offerId);
    if (!offer) {
        return;
    }

    void trackOfferClick(offer, 'chat');
};

const trackOfferClick = async (offer: Offer, source: 'chat' | 'popup' | 'pinned' | 'offers-panel'): Promise<void> => {
    if (!props.chatToken) {
        return;
    }

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

    try {
        await fetch(`/webinar/${props.chatToken}/offers/${offer.id}/click`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
            keepalive: true,
            body: JSON.stringify({
                source,
                elapsed_seconds: elapsedSeconds.value,
            }),
        });
    } catch {
        // Ignore tracking failures so CTA navigation remains uninterrupted.
    }
};

const extractYouTubeVideoId = (rawUrl: string): string | null => {
    const cleaned = rawUrl.trim();

    if (cleaned === '') {
        return null;
    }

    if (/^[A-Za-z0-9_-]{11}$/.test(cleaned)) {
        return cleaned;
    }

    try {
        const parsed = new URL(cleaned);
        const host = parsed.hostname.toLowerCase();

        if (host.includes('youtu.be')) {
            const shortId = parsed.pathname.split('/').filter(Boolean)[0] ?? '';
            return /^[A-Za-z0-9_-]{11}$/.test(shortId) ? shortId : null;
        }

        if (host.includes('youtube.com') || host.includes('youtube-nocookie.com')) {
            const queryId = parsed.searchParams.get('v');
            if (queryId && /^[A-Za-z0-9_-]{11}$/.test(queryId)) {
                return queryId;
            }

            const segments = parsed.pathname.split('/').filter(Boolean);
            const marker = segments.findIndex((segment) => ['embed', 'shorts', 'live', 'v'].includes(segment));

            if (marker >= 0 && segments[marker + 1] && /^[A-Za-z0-9_-]{11}$/.test(segments[marker + 1])) {
                return segments[marker + 1];
            }

            const fallback = segments.find((segment) => /^[A-Za-z0-9_-]{11}$/.test(segment));
            return fallback ?? null;
        }
    } catch {
        // Fallback regex parsing for malformed URLs copied from rich text.
    }

    const regex = /(?:v=|youtu\.be\/|embed\/|shorts\/|live\/)([A-Za-z0-9_-]{11})/;
    const match = cleaned.match(regex);
    return match?.[1] ?? null;
};

const extractVimeoVideoId = (rawUrl: string): string | null => {
    const cleaned = rawUrl.trim();

    if (cleaned === '') {
        return null;
    }

    if (/^\d+$/.test(cleaned)) {
        return cleaned;
    }

    try {
        const parsed = new URL(cleaned);
        const segments = parsed.pathname.split('/').filter(Boolean);
        const numeric = segments.find((segment) => /^\d+$/.test(segment));
        if (numeric) {
            return numeric;
        }
    } catch {
        // Fallback regex parsing for malformed URLs copied from rich text.
    }

    const match = cleaned.match(/vimeo\.com\/(?:video\/)?(\d+)/);
    return match?.[1] ?? null;
};

const embedUrl = computed(() => {
    const url = props.webinar.video_url.trim();

    if (props.webinar.video_source === 'youtube') {
        const videoId = extractYouTubeVideoId(url);
        if (!videoId) {
            return url;
        }

        return `https://www.youtube.com/embed/${videoId}?autoplay=1&rel=0&modestbranding=1&playsinline=1&mute=${iframeMuted.value ? 1 : 0}`;
    }

    if (props.webinar.video_source === 'vimeo') {
        const videoId = extractVimeoVideoId(url);
        if (!videoId) {
            return url;
        }

        return `https://player.vimeo.com/video/${videoId}?autoplay=1&muted=${iframeMuted.value ? 1 : 0}`;
    }

    return url;
});

const prettyElapsed = computed(() => {
    const minutes = Math.floor(elapsedSeconds.value / 60)
        .toString()
        .padStart(2, '0');
    const seconds = (elapsedSeconds.value % 60).toString().padStart(2, '0');

    return `${minutes}:${seconds}`;
});

const pinnedStarterMessage = computed(() => {
    const name = props.registrant.name?.trim() ? props.registrant.name : 'test user';
    return `Welcome ${name}! The webinar is starting now.`;
});

const tickTimeline = (): void => {
    elapsedSeconds.value += 1;

    for (const message of props.webinar.scheduled_messages) {
        if (!firedMessageIds.has(message.id) && elapsedSeconds.value >= message.trigger_second) {
            firedMessageIds.add(message.id);
            chatMessages.value.push({
                id: `system-${message.id}`,
                sender: message.sender_name ?? props.webinar.host_name,
                message: message.message,
                at: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
            });
        }
    }

    for (const offer of props.webinar.offers) {
        if (!firedOfferIds.has(offer.id) && elapsedSeconds.value >= offer.trigger_second) {
            firedOfferIds.add(offer.id);
            droppedOffers.value.push(offer);

            if (offer.display_mode === 'pinned') {
                pinnedOffer.value = offer;
            }

            if (offer.display_mode === 'popup') {
                popupOffer.value = offer;
            }

            chatMessages.value.push({
                id: `offer-${offer.id}`,
                sender: `${props.webinar.host_name} (Offer)`,
                message: `${offer.title}: ${offer.button_url}`,
                at: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
            });
        }
    }
};

const tickViewers = (): void => {
    const delta = Math.floor(Math.random() * 9) - 4;
    const next = viewerCount.value + delta;
    viewerCount.value = Math.max(props.webinar.min_viewers, Math.min(props.webinar.max_viewers, next));
};

const sendChat = (): void => {
    if (!chatInput.value.trim()) {
        return;
    }

    if (!props.chatToken) {
        return;
    }

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

    fetch(`/webinar/${props.chatToken}/chat`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            Accept: 'application/json',
        },
        body: JSON.stringify({
            message: chatInput.value,
        }),
    }).finally(() => {
        chatInput.value = '';
        void loadServerChat();
    });
};

const enableSound = (): void => {
    iframeMuted.value = false;

    if (props.webinar.video_source === 'direct' && directVideoRef.value) {
        directVideoRef.value.muted = false;
        void directVideoRef.value.play();
    }
};

const sendReaction = (emoji: string): void => {
    const id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const left = Math.floor(Math.random() * 70) + 15;

    reactionBubbles.value.push({ id, emoji, left });

    window.setTimeout(() => {
        reactionBubbles.value = reactionBubbles.value.filter((item) => item.id !== id);
    }, 2000);
};

const loadServerChat = async (): Promise<void> => {
    if (!props.chatToken) {
        return;
    }

    try {
        const response = await fetch(`/webinar/${props.chatToken}/chat`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            return;
        }

        const payload = (await response.json()) as {
            messages?: Array<{ id: number; sender: string; message: string; self: boolean; at: string }>;
        };

        if (Array.isArray(payload.messages)) {
            const dbMessages = payload.messages.map((item) => ({
                id: `db-${item.id}`,
                sender: item.sender,
                message: item.message,
                self: item.self,
                at: item.at,
            }));

            const localOnlyMessages = chatMessages.value.filter((item) => !item.id.startsWith('db-'));
            chatMessages.value = [...dbMessages, ...localOnlyMessages];
        }
    } catch {
        // Silent fail keeps playback and local UX responsive.
    }
};

onMounted(() => {
    if (props.roomEnded || props.accessRequired) {
        return;
    }

    timer = setInterval(tickTimeline, 1000);
    viewersTimer = setInterval(tickViewers, 4000);
    void loadServerChat();
    chatPollTimer = setInterval(() => {
        void loadServerChat();
    }, 3000);
});

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }

    if (viewersTimer) {
        clearInterval(viewersTimer);
    }

    if (chatPollTimer) {
        clearInterval(chatPollTimer);
    }
});

const submitAccess = (): void => {
    if (!props.accessUrl) {
        return;
    }

    gateForm.post(props.accessUrl, {
        preserveScroll: true,
        onSuccess: () => {
            // Hide modal and enable real-time features
            // accessRequired is a prop, so we need to emit an event or update parent state
            // For now, reload only SPA state, not full page
            // If parent controls accessRequired, emit event
            // Otherwise, reload page via Inertia
            // Try to update modal state locally
            // If accessRequired is managed by parent, emit event
            // If not, fallback to window.location.reload()
            // But prefer SPA update
            // Example:
            // this.$emit('access-granted')
            // For local state:
            // accessRequired.value = false
            // But accessRequired is a prop, so can't set directly
            // So, reload SPA page without full reload
            // Inertia.reload({ only: ['registrant', 'chatToken'] })
            // Or use router.reload()
            // For now, use Inertia.reload to update SPA state
            const inertia = (window as Window & { Inertia?: { reload: (options?: { only?: string[] }) => void } }).Inertia;
            if (inertia) {
                inertia.reload({ only: ['registrant', 'chatToken'] });
            } else {
                // fallback: reload page
                window.location.reload();
            }
        },
    });
};
</script>

<template>
    <Head :title="webinar.title" />

    <div class="relative mx-auto flex min-h-screen w-full max-w-350 flex-col gap-4 p-4 lg:flex-row lg:items-stretch">
        <div
            v-if="roomEnded"
            class="flex min-h-[60vh] w-full flex-col items-center justify-center rounded-xl border bg-card p-8 text-center shadow-sm"
        >
            <h1 class="text-2xl font-semibold">Webinar ended</h1>
            <p class="mt-2 max-w-xl text-sm text-muted-foreground">
                {{ endedMessage || 'This webinar has ended. Please contact the host for replay options.' }}
            </p>
        </div>
        <section v-if="!roomEnded" class="order-1 flex min-w-0 flex-1 flex-col gap-4">
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-semibold">{{ webinar.title }}</h1>
                        <p class="text-sm text-muted-foreground">Host: {{ webinar.host_name }}</p>
                    </div>
                    <div class="text-right text-xs sm:text-sm">
                        <p class="font-semibold text-rose-600">LIVE</p>
                        <p class="text-muted-foreground">{{ viewerCount }} in meeting</p>
                        <p class="text-muted-foreground">{{ prettyElapsed }}</p>
                    </div>
                </div>

                <div class="relative overflow-hidden rounded-lg border bg-black">
                    <iframe
                        v-if="webinar.video_source !== 'direct'"
                        :src="embedUrl"
                        class="aspect-video w-full"
                        allow="autoplay; encrypted-media"
                        allowfullscreen
                    />
                    <video
                        v-else
                        ref="directVideoRef"
                        class="aspect-video w-full"
                        :src="embedUrl"
                        :muted="iframeMuted"
                        controls
                        autoplay
                    />

                    <div class="pointer-events-none absolute inset-0">
                        <span
                            v-for="reaction in reactionBubbles"
                            :key="reaction.id"
                            class="reaction-bubble"
                            :style="{ left: `${reaction.left}%` }"
                        >
                            {{ reaction.emoji }}
                        </span>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        :disabled="!iframeMuted"
                        class="cursor-pointer rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        @click="enableSound"
                    >
                        {{ iframeMuted ? 'Enable Sound' : 'Sound Enabled' }}
                    </button>
                    <button type="button" class="rounded-md border px-2 py-1 text-lg" @click="sendReaction('👍')">👍</button>
                    <button type="button" class="rounded-md border px-2 py-1 text-lg" @click="sendReaction('❤️')">❤️</button>
                    <button type="button" class="rounded-md border px-2 py-1 text-lg" @click="sendReaction('🔥')">🔥</button>
                    <button type="button" class="rounded-md border px-2 py-1 text-lg" @click="sendReaction('👏')">👏</button>
                </div>
            </div>

            <div v-if="pinnedOffer" class="rounded-xl border border-amber-300 bg-amber-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Pinned Offer</p>
                <h3 class="mt-1 text-lg font-semibold text-amber-900">{{ pinnedOffer.title }}</h3>
                <p class="mt-1 text-sm text-amber-800">{{ pinnedOffer.description }}</p>
                <a
                    class="mt-3 inline-block rounded-md bg-amber-700 px-4 py-2 text-sm text-white"
                    :href="pinnedOffer.button_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    @click="void trackOfferClick(pinnedOffer, 'pinned')"
                >
                    {{ pinnedOffer.button_text }}
                </a>
            </div>
        </section>

        <aside v-if="!roomEnded" class="order-2 flex h-[78vh] w-full flex-col overflow-hidden rounded-xl border bg-card shadow-sm lg:h-auto lg:w-95 lg:min-w-90 lg:max-w-105 lg:self-stretch">
            <div class="border-b px-4 py-3">
                <h2 class="font-semibold">In-call chat</h2>
                <p class="text-xs text-muted-foreground">You are {{ registrant.name || 'Guest' }}</p>
            </div>

            <div class="flex items-center gap-2 border-b bg-muted/20 px-3 py-2">
                <button
                    type="button"
                    class="rounded-md border px-3 py-1 text-xs"
                    :class="roomPanel === 'chat' ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background'"
                    @click="roomPanel = 'chat'"
                >
                    Chat
                </button>
                <button
                    type="button"
                    class="rounded-md border px-3 py-1 text-xs"
                    :class="roomPanel === 'offers' ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background'"
                    @click="roomPanel = 'offers'"
                >
                    Offers ({{ droppedOffers.length }})
                </button>
            </div>

            <div class="border-b bg-amber-50 px-4 py-2 text-sm text-amber-900">
                <p class="font-medium">Pinned message</p>
                <p>{{ pinnedStarterMessage }}</p>
            </div>

            <div v-if="roomPanel === 'chat'" class="flex-1 space-y-3 overflow-y-auto bg-muted/30 px-3 py-4">
                <div
                    v-for="message in chatMessages"
                    :key="message.id"
                    class="max-w-[85%] rounded-md border px-3 py-2 text-sm"
                    :class="message.self ? 'ml-auto border-primary/20 bg-primary text-primary-foreground' : 'mr-auto border-sky-200 bg-sky-50 text-sky-950'"
                >
                    <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                        <p class="font-semibold" :class="message.self ? 'text-primary-foreground/90' : 'text-sky-800'">
                            {{ message.sender }}
                        </p>
                        <p :class="message.self ? 'text-primary-foreground/70' : 'text-sky-700/70'">{{ message.at }}</p>
                    </div>
                    <p
                        class="leading-relaxed"
                        :class="message.self ? 'text-primary-foreground' : 'text-sky-950'"
                        v-html="linkifyMessage(message.message)"
                        @click="onChatMessageClick($event, message.id)"
                    />
                </div>
            </div>

            <div v-else class="flex-1 space-y-3 overflow-y-auto bg-muted/30 px-3 py-4">
                <div
                    v-for="offer in droppedOffers"
                    :key="offer.id"
                    class="rounded-lg border border-amber-200 bg-amber-50 p-3"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Dropped Offer</p>
                    <h3 class="mt-1 text-sm font-semibold text-amber-900">{{ offer.title }}</h3>
                    <p v-if="offer.description" class="mt-1 text-xs text-amber-800">{{ offer.description }}</p>
                    <a
                        class="mt-3 inline-block rounded-md bg-amber-700 px-3 py-2 text-xs text-white"
                        :href="offer.button_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        @click="void trackOfferClick(offer, 'offers-panel')"
                    >
                        {{ offer.button_text }}
                    </a>
                </div>
                <div v-if="droppedOffers.length === 0" class="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">
                    No offer has dropped yet. Offers will appear here.
                </div>
            </div>

            <form class="border-t bg-background p-3" @submit.prevent="sendChat">
                <label class="mb-2 block text-xs text-muted-foreground">Send a message to the meeting</label>
                <div class="flex items-center gap-2">
                    <input
                        v-model="chatInput"
                        class="h-10 flex-1 rounded-md border border-input bg-transparent px-3 text-sm"
                        placeholder="Type your message"
                    />
                    <button
                        type="submit"
                        :disabled="Boolean(accessRequired)"
                        class="h-10 rounded-md bg-primary px-4 text-sm text-primary-foreground disabled:opacity-50"
                    >
                        Send
                    </button>
                </div>
            </form>
        </aside>

        <div
            v-if="accessRequired && !roomEnded"
            class="absolute inset-0 z-40 flex items-center justify-center bg-black/40 backdrop-blur-sm"
        >
            <form
                class="w-full max-w-md rounded-xl border bg-card p-6 shadow-2xl"
                @submit.prevent="submitAccess"
            >
                <h2 class="text-xl font-semibold">Join Webinar</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Enter your name and email to continue. If you registered before, you will be allowed in immediately.
                </p>

                <div class="mt-4 space-y-3">
                    <div class="grid gap-2">
                        <label class="text-sm font-medium">Name *</label>
                        <input
                            v-model="gateForm.name"
                            class="h-10 rounded-md border border-input bg-transparent px-3 text-sm"
                            placeholder="Your full name"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <label class="text-sm font-medium">Email *</label>
                        <input
                            v-model="gateForm.email"
                            type="email"
                            class="h-10 rounded-md border border-input bg-transparent px-3 text-sm"
                            placeholder="you@example.com"
                            required
                        />
                    </div>
                </div>

                <button
                    type="submit"
                    :disabled="gateForm.processing"
                    class="mt-5 h-10 w-full rounded-md bg-primary px-4 text-sm text-primary-foreground disabled:opacity-50"
                >
                    Continue to Webinar
                </button>
            </form>
        </div>

        <div
            v-if="popupOffer && !roomEnded"
            class="fixed bottom-4 right-4 z-50 max-w-sm rounded-xl border bg-white p-4 shadow-lg"
        >
            <h3 class="font-semibold">{{ popupOffer.title }}</h3>
            <p class="mt-1 text-sm text-muted-foreground">{{ popupOffer.description }}</p>
            <div class="mt-3 flex items-center gap-2">
                <a
                    class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground"
                    :href="popupOffer.button_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    @click="void trackOfferClick(popupOffer, 'popup')"
                >
                    {{ popupOffer.button_text }}
                </a>
                <button class="text-xs text-muted-foreground" @click="popupOffer = null">Dismiss</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.reaction-bubble {
    position: absolute;
    bottom: 12%;
    font-size: 2rem;
    filter: drop-shadow(0 2px 2px rgb(0 0 0 / 35%));
    animation: float-up 2s ease-out forwards;
}

@keyframes float-up {
    0% {
        opacity: 0;
        transform: translateY(0) scale(0.8);
    }

    20% {
        opacity: 1;
        transform: translateY(-20px) scale(1);
    }

    100% {
        opacity: 0;
        transform: translateY(-180px) scale(1.1);
    }
}
</style>
