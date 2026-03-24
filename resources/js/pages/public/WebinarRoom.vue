<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { getEcho } from '@/lib/echo';

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
    playback_settings?: {
        show_fake_viewers?: boolean;
        redirect_enabled?: boolean;
        redirect_url?: string;
    };
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
const mobileTab = ref<'video' | 'chat'>('video');
const unreadCount = ref(0);
const chatMessages = ref<Array<{ id: string; sender: string; message: string; self?: boolean; at?: string }>>([]);
const pinnedOffer = ref<Offer | null>(null);
const popupOffer = ref<Offer | null>(null);
const droppedOffers = ref<Offer[]>([]);
const iframeMuted = ref(true);
const videoEnded = ref(false);
const iframeRef = ref<HTMLIFrameElement | null>(null);
const directVideoRef = ref<HTMLVideoElement | null>(null);
const reactionBubbles = ref<Array<{ id: string; emoji: string; left: number }>>([]);
const chatScrollContainer = ref<HTMLElement | null>(null);

let timer: ReturnType<typeof setInterval> | null = null;
let viewersTimer: ReturnType<typeof setInterval> | null = null;
let chatPollTimer: ReturnType<typeof setInterval> | null = null;
let playbackKeepAliveTimer: ReturnType<typeof setInterval> | null = null;
let chatChannelName: string | null = null;

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
    const origin = typeof window !== 'undefined' ? window.location.origin : '';

    if (props.webinar.video_source === 'youtube') {
        const videoId = extractYouTubeVideoId(url);
        if (!videoId) {
            return url;
        }

        const params = [
            'autoplay=1',
            'mute=1',
            'controls=0',
            'disablekb=1',
            'fs=0',
            'loop=0',
            'iv_load_policy=3',
            'modestbranding=1',
            'rel=0',
            'showinfo=0',
            'cc_load_policy=0',
            'playsinline=1',
            'enablejsapi=1',
            `origin=${encodeURIComponent(origin)}`,
        ].join('&');

        return `https://www.youtube-nocookie.com/embed/${videoId}?${params}`;
    }

    if (props.webinar.video_source === 'vimeo') {
        const videoId = extractVimeoVideoId(url);
        if (!videoId) {
            return url;
        }

        const params = [
            'autoplay=1',
            'muted=1',
            'controls=0',
            'loop=0',
            'title=0',
            'byline=0',
            'portrait=0',
            'keyboard=0',
            'dnt=1',
        ].join('&');

        return `https://player.vimeo.com/video/${videoId}?${params}`;
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
    const name = props.registrant.name?.trim() ? props.registrant.name : 'Guest';
    return `Welcome ${name}! The webinar is starting now.`;
});

const stopAllTimers = (): void => {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }

    if (viewersTimer) {
        clearInterval(viewersTimer);
        viewersTimer = null;
    }

    if (chatPollTimer) {
        clearInterval(chatPollTimer);
        chatPollTimer = null;
    }

    if (playbackKeepAliveTimer) {
        clearInterval(playbackKeepAliveTimer);
        playbackKeepAliveTimer = null;
    }
};

const appendDbMessage = (item: { id: number; sender: string; message: string; self: boolean; at: string }): void => {
    const newId = `db-${item.id}`;
    if (chatMessages.value.some((message) => message.id === newId)) {
        return;
    }

    chatMessages.value.push({
        id: newId,
        sender: item.sender,
        message: item.message,
        self: item.self,
        at: item.at,
    });

    if (!item.self && mobileTab.value === 'video') {
        unreadCount.value += 1;
    }

    void scrollChatToBottom();
};

const scrollChatToBottom = async (): Promise<void> => {
    await nextTick();
    const el = chatScrollContainer.value;
    if (!el) {
        return;
    }

    el.scrollTop = el.scrollHeight;
};

const redirectAfterEndIfEnabled = (): void => {
    if (!props.webinar.playback_settings?.redirect_enabled) {
        return;
    }

    const rawUrl = props.webinar.playback_settings.redirect_url?.trim() ?? '';
    if (rawUrl === '') {
        return;
    }

    try {
        const parsed = new URL(rawUrl, window.location.origin);
        if (!['http:', 'https:'].includes(parsed.protocol)) {
            return;
        }

        window.location.href = parsed.toString();
    } catch {
        // Ignore invalid redirect URLs so room can safely finish.
    }
};

const endMeeting = (): void => {
    if (videoEnded.value) {
        return;
    }

    videoEnded.value = true;
    stopAllTimers();

    if (props.webinar.video_source === 'youtube' && iframeRef.value?.contentWindow) {
        iframeRef.value.contentWindow.postMessage(JSON.stringify({
            event: 'command', func: 'pauseVideo', args: [],
        }), '*');
    }

    if (props.webinar.video_source === 'vimeo' && iframeRef.value?.contentWindow) {
        iframeRef.value.contentWindow.postMessage(JSON.stringify({ method: 'pause' }), '*');
    }

    if (props.webinar.video_source === 'direct' && directVideoRef.value) {
        directVideoRef.value.pause();
    }

    window.setTimeout(redirectAfterEndIfEnabled, 300);
};

const onIframeLoad = (): void => {
    const iframe = iframeRef.value;
    if (!iframe?.contentWindow) {
        return;
    }

    if (props.webinar.video_source === 'youtube') {
        iframe.contentWindow.postMessage(JSON.stringify({ event: 'listening' }), '*');
    }

    if (props.webinar.video_source === 'vimeo') {
        iframe.contentWindow.postMessage(JSON.stringify({ method: 'addEventListener', value: 'ended' }), '*');
    }
};

const onIframeMessage = (event: MessageEvent): void => {
    if (videoEnded.value) {
        return;
    }

    try {
        let data = event.data;
        if (typeof data === 'string') {
            data = JSON.parse(data);
        }

        if (data?.event === 'onStateChange' && data?.info === 0) {
            endMeeting();
            return;
        }

        if (data?.event === 'infoDelivery' && data?.info?.playerState === 0) {
            endMeeting();
            return;
        }

        if (data?.event === 'ended' || data?.event === 'finish') {
            endMeeting();
        }
    } catch {
        // Not JSON or unrelated message.
    }
};

const onDirectVideoEnded = (): void => {
    endMeeting();
};

const tryResumePlayback = (): void => {
    if (videoEnded.value || iframeMuted.value) {
        return;
    }

    const dur = props.webinar.video_duration_seconds;
    if (dur && elapsedSeconds.value >= dur - 5) {
        return;
    }

    if (props.webinar.video_source === 'youtube' && iframeRef.value?.contentWindow) {
        iframeRef.value.contentWindow.postMessage(JSON.stringify({
            event: 'command',
            func: 'playVideo',
            args: [],
        }), '*');
    }

    if (props.webinar.video_source === 'vimeo' && iframeRef.value?.contentWindow) {
        iframeRef.value.contentWindow.postMessage(JSON.stringify({
            method: 'play',
        }), '*');
    }

    if (props.webinar.video_source === 'direct' && directVideoRef.value) {
        void directVideoRef.value.play();
    }
};

const tickTimeline = (): void => {
    elapsedSeconds.value += 1;

    if (
        !videoEnded.value
        && props.webinar.video_duration_seconds
        && elapsedSeconds.value >= props.webinar.video_duration_seconds
    ) {
        endMeeting();
        return;
    }

    for (const message of props.webinar.scheduled_messages) {
        if (!firedMessageIds.has(message.id) && elapsedSeconds.value >= message.trigger_second) {
            firedMessageIds.add(message.id);
            chatMessages.value.push({
                id: `system-${message.id}`,
                sender: message.sender_name ?? props.webinar.host_name,
                message: message.message,
                at: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
            });
            void scrollChatToBottom();

            if (mobileTab.value === 'video') {
                unreadCount.value += 1;
            }
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
            void scrollChatToBottom();

            if (mobileTab.value === 'video') {
                unreadCount.value += 1;
            }
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
        void scrollChatToBottom();
    });
};

const enableSound = (): void => {
    iframeMuted.value = false;

    if (props.webinar.video_source === 'youtube' && iframeRef.value?.contentWindow) {
        const win = iframeRef.value.contentWindow;
        win.postMessage(JSON.stringify({ event: 'command', func: 'unMute', args: [] }), '*');
        win.postMessage(JSON.stringify({ event: 'command', func: 'setVolume', args: [100] }), '*');
        win.postMessage(JSON.stringify({ event: 'command', func: 'playVideo', args: [] }), '*');
    }

    if (props.webinar.video_source === 'vimeo' && iframeRef.value?.contentWindow) {
        const win = iframeRef.value.contentWindow;
        win.postMessage(JSON.stringify({ method: 'setVolume', value: '1' }), '*');
        win.postMessage(JSON.stringify({ method: 'play' }), '*');
    }

    if (props.webinar.video_source === 'direct' && directVideoRef.value) {
        directVideoRef.value.muted = false;
        void directVideoRef.value.play();
    }

    window.setTimeout(tryResumePlayback, 150);
    window.setTimeout(tryResumePlayback, 1500);
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
            const prevDbCount = chatMessages.value.filter((m) => m.id.startsWith('db-')).length;

            const dbMessages = payload.messages.map((item) => ({
                id: `db-${item.id}`,
                sender: item.sender,
                message: item.message,
                self: item.self,
                at: item.at,
            }));

            const localOnlyMessages = chatMessages.value.filter((item) => !item.id.startsWith('db-'));
            chatMessages.value = [...dbMessages, ...localOnlyMessages];
            void scrollChatToBottom();

            const newDbCount = dbMessages.length;
            if (mobileTab.value === 'video' && newDbCount > prevDbCount) {
                unreadCount.value += newDbCount - prevDbCount;
            }
        }
    } catch {
        // Silent fail keeps playback and local UX responsive.
    }
};

const startRealtimeChat = (): boolean => {
    if (!props.chatToken) {
        return false;
    }

    const echo = getEcho();
    if (!echo) {
        return false;
    }

    chatChannelName = `webinar.chat.${props.chatToken}`;
    echo.channel(chatChannelName).listen('.chat.message.sent', (payload: {
        id: number;
        sender: string;
        message: string;
        self: boolean;
        at: string;
    }) => {
        appendDbMessage(payload);
    });

    return true;
};

const openChatTab = (): void => {
    mobileTab.value = 'chat';
    unreadCount.value = 0;
    window.setTimeout(tryResumePlayback, 200);
};

const openVideoTab = (): void => {
    mobileTab.value = 'video';
    window.setTimeout(tryResumePlayback, 100);
};

const onDocumentVisibilityChange = (): void => {
    if (document.visibilityState === 'visible') {
        tryResumePlayback();
    }
};

onMounted(() => {
    if (props.roomEnded || props.accessRequired) {
        return;
    }

    window.addEventListener('message', onIframeMessage);
    document.addEventListener('visibilitychange', onDocumentVisibilityChange);

    timer = setInterval(tickTimeline, 1000);
    viewersTimer = setInterval(tickViewers, 4000);
    playbackKeepAliveTimer = setInterval(tryResumePlayback, 12000);
    void loadServerChat();
    void scrollChatToBottom();

    if (!startRealtimeChat()) {
        chatPollTimer = setInterval(() => {
            void loadServerChat();
        }, 10000);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('message', onIframeMessage);
    document.removeEventListener('visibilitychange', onDocumentVisibilityChange);
    const echo = getEcho();
    if (echo && chatChannelName) {
        echo.leave(chatChannelName);
    }
    chatChannelName = null;
    stopAllTimers();
});

const submitAccess = (): void => {
    if (!props.accessUrl) {
        return;
    }

    gateForm.post(props.accessUrl, {
        preserveScroll: true,
        onSuccess: () => {
            const inertia = (window as Window & { Inertia?: { reload: (options?: { only?: string[] }) => void } }).Inertia;
            if (inertia) {
                inertia.reload({ only: ['registrant', 'chatToken'] });
            } else {
                window.location.reload();
            }
        },
    });
};
</script>

<template>
    <Head :title="webinar.title" />

    <!--
        Mobile:  flex-col, fills dvh so video tab and chat tab each fill the screen.
        Desktop: flex-row side-by-side with normal scrolling.
    -->
    <div class="relative mx-auto flex h-dvh w-full max-w-350 flex-col overflow-hidden lg:h-[calc(100dvh-2rem)] lg:flex-row lg:items-stretch lg:gap-4 lg:overflow-hidden lg:p-4">

        <!-- ── Room ended banner ──────────────────────────────────────── -->
        <div
            v-if="roomEnded"
            class="flex min-h-[60vh] w-full flex-col items-center justify-center rounded-xl border bg-card p-8 text-center shadow-sm"
        >
            <h1 class="text-2xl font-semibold">Webinar ended</h1>
            <p class="mt-2 max-w-xl text-sm text-muted-foreground">
                {{ endedMessage || 'This webinar has ended. Please contact the host for replay options.' }}
            </p>
        </div>

        <!-- ── MOBILE HEADER + TAB BAR (hidden on lg+) ───────────────── -->
        <div v-if="!roomEnded" class="shrink-0 border-b bg-card lg:hidden">
            <!-- Compact info row -->
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <h1 class="truncate text-sm font-semibold leading-tight">{{ webinar.title }}</h1>
                    <p class="text-xs text-muted-foreground">{{ viewerCount }} watching · {{ webinar.host_name }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2 text-xs">
                    <span v-if="!videoEnded" class="flex items-center gap-1 font-semibold text-rose-600">
                        <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-rose-600" />
                        LIVE
                    </span>
                    <span v-else class="font-semibold text-muted-foreground">ENDED</span>
                    <span class="tabular-nums text-muted-foreground">{{ prettyElapsed }}</span>
                </div>
            </div>
            <!-- Tab switcher -->
            <div class="flex border-t">
                <button
                    type="button"
                    class="flex flex-1 items-center justify-center gap-1.5 border-b-2 py-2.5 text-sm font-medium transition-colors"
                    :class="mobileTab === 'video'
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="openVideoTab"
                >
                    Video
                </button>
                <button
                    type="button"
                    class="flex flex-1 items-center justify-center gap-1.5 border-b-2 py-2.5 text-sm font-medium transition-colors"
                    :class="mobileTab === 'chat'
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="openChatTab"
                >
                    Chat
                    <span
                        v-if="unreadCount > 0"
                        class="rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] leading-none text-white"
                    >{{ unreadCount }}</span>
                </button>
            </div>
        </div>

        <!-- ── VIDEO SECTION ──────────────────────────────────────────── -->
        <!--
            Always kept in DOM (even when on chat tab on mobile) so the iframe
            keeps playing. CSS class hides it visually on mobile when on chat tab.
        -->
        <section
            v-if="!roomEnded"
            class="order-1 min-w-0 flex-1 flex-col gap-3 overflow-y-auto p-3 lg:flex lg:gap-4 lg:p-0"
            :class="mobileTab === 'video'
                ? 'flex'
                : 'flex pointer-events-none fixed -left-[10000px] top-0 h-px w-px opacity-0 lg:static lg:pointer-events-auto lg:h-auto lg:w-auto lg:opacity-100'"
        >
            <div class="rounded-xl border bg-card p-3 shadow-sm lg:p-4">
                <!-- Desktop-only title/info row -->
                <div class="mb-3 hidden items-center justify-between gap-4 lg:flex">
                    <div>
                        <h1 class="text-xl font-semibold">{{ webinar.title }}</h1>
                        <p class="text-sm text-muted-foreground">Host: {{ webinar.host_name }}</p>
                    </div>
                    <div class="text-right text-xs sm:text-sm">
                        <p v-if="!videoEnded" class="font-semibold text-rose-600">LIVE</p>
                        <p v-else class="font-semibold text-muted-foreground">ENDED</p>
                        <p class="text-muted-foreground">{{ viewerCount }} in meeting</p>
                        <p class="text-muted-foreground">{{ prettyElapsed }}</p>
                    </div>
                </div>

                <!-- Video container -->
                <div class="relative overflow-hidden rounded-lg border bg-black">
                    <!-- Meeting ended overlay -->
                    <div
                        v-if="videoEnded"
                        class="absolute inset-0 z-30 flex flex-col items-center justify-center bg-gray-900/95"
                    >
                        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-white/10">
                            <svg class="h-7 w-7 text-white/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                            </svg>
                        </div>
                        <h2 class="mt-4 text-xl font-semibold text-white">Meeting Ended</h2>
                        <p class="mt-2 max-w-xs text-center text-sm text-white/60">
                            This webinar session has concluded. Thank you for attending.
                        </p>
                    </div>

                    <!-- Click-blocker: always covers iframe to hide YouTube/Vimeo UI on hover -->
                    <div v-if="!videoEnded" class="absolute inset-0 z-10" />

                    <!-- Center sound CTA overlay -->
                    <div
                        v-if="!videoEnded && iframeMuted"
                        class="absolute inset-0 z-25 flex items-center justify-center bg-black/35 p-4"
                    >
                        <button
                            type="button"
                            class="rounded-full border border-white/30 bg-white/95 px-5 py-2.5 text-sm font-semibold text-slate-900 shadow-lg"
                            @click="enableSound"
                        >
                            Enable Sound
                        </button>
                    </div>

                    <iframe
                        v-if="webinar.video_source !== 'direct'"
                        ref="iframeRef"
                        :src="embedUrl"
                        class="aspect-video w-full"
                        allow="autoplay; encrypted-media; picture-in-picture"
                        allowfullscreen
                        playsinline
                        frameborder="0"
                        @load="onIframeLoad"
                    />
                    <video
                        v-else
                        ref="directVideoRef"
                        class="aspect-video w-full"
                        :src="embedUrl"
                        :muted="iframeMuted"
                        playsinline
                        autoplay
                        @ended="onDirectVideoEnded"
                    />

                    <div class="pointer-events-none absolute inset-0 z-20">
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

                <!-- Controls: reactions -->
                <div v-if="!videoEnded" class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" class="rounded-md border px-2 py-1 text-lg" @click="sendReaction('👍')">👍</button>
                    <button type="button" class="rounded-md border px-2 py-1 text-lg" @click="sendReaction('❤️')">❤️</button>
                    <button type="button" class="rounded-md border px-2 py-1 text-lg" @click="sendReaction('🔥')">🔥</button>
                    <button type="button" class="rounded-md border px-2 py-1 text-lg" @click="sendReaction('👏')">👏</button>
                </div>
            </div>

            <!-- Pinned offer (below video) -->
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

        <!-- ── CHAT ASIDE ──────────────────────────────────────────────── -->
        <!--
            On mobile: fills remaining height when on chat tab.
            On desktop: fixed-width sidebar, always visible.
        -->
        <aside
            v-if="!roomEnded"
            class="order-2 flex-col overflow-hidden bg-card shadow-sm lg:h-full lg:w-95 lg:min-w-90 lg:max-w-105 lg:self-stretch lg:rounded-xl lg:border"
            :class="mobileTab === 'chat' ? 'flex flex-1' : 'hidden lg:flex'"
        >
            <!-- Desktop-only header -->
            <div class="hidden border-b px-4 py-3 lg:block">
                <h2 class="font-semibold">In-call chat</h2>
                <p class="text-xs text-muted-foreground">You are {{ registrant.name || 'Guest' }}</p>
            </div>

            <!-- Chat / Offers sub-tabs -->
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

            <!-- Pinned welcome message -->
            <div class="border-b bg-amber-50 px-4 py-2 text-sm text-amber-900">
                <p class="font-medium">Pinned message</p>
                <p>{{ pinnedStarterMessage }}</p>
            </div>

            <!-- Chat messages -->
            <div
                v-if="roomPanel === 'chat'"
                ref="chatScrollContainer"
                class="flex-1 space-y-3 overflow-y-auto bg-muted/30 px-3 py-4"
            >
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

            <!-- Offers panel -->
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

            <!-- Chat input -->
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

        <!-- ── Access gate modal ──────────────────────────────────────── -->
        <div
            v-if="accessRequired && !roomEnded"
            class="absolute inset-0 z-40 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
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

        <!-- ── Popup offer ────────────────────────────────────────────── -->
        <div
            v-if="popupOffer && !roomEnded"
            class="fixed bottom-4 right-4 z-50 w-[calc(100vw-2rem)] max-w-sm rounded-xl border bg-white p-4 shadow-lg sm:w-auto"
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
                <button class="text-xs text-muted-foreground" type="button" @click="popupOffer = null">Dismiss</button>
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
