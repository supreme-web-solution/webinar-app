<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Icon } from '@iconify/vue';
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
        exit_popup_enabled?: boolean;
        exit_popup_heading?: string;
        exit_popup_body?: string;
        exit_popup_cta_text?: string;
        exit_popup_cta_url?: string;
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
const tracked60Seconds = ref(false);
const tracked50Percent = ref(false);
const trackedToEnd = ref(false);
const iframeRef = ref<HTMLIFrameElement | null>(null);
const directVideoRef = ref<HTMLVideoElement | null>(null);
const reactionBubbles = ref<Array<{ id: string; emoji: string; left: number }>>([]);
const chatScrollContainer = ref<HTMLElement | null>(null);
const showExitPopup = ref(false);
const exitPopupDismissed = ref(false);

const hasExitPopupConfig = computed(() => {
    const settings = props.webinar.playback_settings;
    return Boolean(settings?.exit_popup_enabled && settings?.exit_popup_cta_url?.trim());
});

let timer: ReturnType<typeof setInterval> | null = null;
let viewersTimer: ReturnType<typeof setInterval> | null = null;
let chatPollTimer: ReturnType<typeof setInterval> | null = null;
let playbackKeepAliveTimer: ReturnType<typeof setInterval> | null = null;
let chatChannelName: string | null = null;
let hasLoadedChat = false;

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

const trackWatchMilestone = async (
    milestone: 'watched_60_seconds' | 'watched_50_percent' | 'watched_to_end',
    watchDurationSeconds?: number,
): Promise<void> => {
    if (!props.chatToken) {
        return;
    }

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

    try {
        await fetch(`/webinar/${props.chatToken}/watch`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
            keepalive: true,
            body: JSON.stringify({
                milestone,
                watch_duration_seconds: watchDurationSeconds ?? elapsedSeconds.value,
            }),
        });
    } catch {
        // Tracking should never block playback.
    }
};

const trackGeneralCtaClick = async (
    source: 'exit-popup' | 'redirect',
    url: string,
    eventType: 'webinar_cta_link_clicked' | 'webinar_redirect_triggered' = 'webinar_cta_link_clicked',
): Promise<void> => {
    if (!props.chatToken || !url.trim()) {
        return;
    }

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

    try {
        await fetch(`/webinar/${props.chatToken}/cta-click`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
            keepalive: true,
            body: JSON.stringify({
                source,
                url,
                elapsed_seconds: elapsedSeconds.value,
                event_type: eventType,
            }),
        });
    } catch {
        // Tracking should never block playback/redirect actions.
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

const halfwayWatchThreshold = computed(() => {
    const duration = props.webinar.video_duration_seconds && props.webinar.video_duration_seconds > 0
        ? props.webinar.video_duration_seconds
        : 5400;

    return Math.max(1, Math.floor(duration * 0.5) + 1);
});

const pinnedStarterMessage = computed(() => {
    const name = props.registrant.name?.trim() ? props.registrant.name : 'Guest';
    return `Welcome ${name}! The webinar is starting now.`;
});

const onExitIntent = (event: MouseEvent): void => {
    if (exitPopupDismissed.value || showExitPopup.value || videoEnded.value || props.roomEnded) {
        return;
    }

    if (!hasExitPopupConfig.value) {
        return;
    }

    // Trigger only when cursor leaves the page boundary from the top edge.
    const isLeavingDocument = event.relatedTarget === null;
    if (isLeavingDocument && event.clientY <= 10) {
        showExitPopup.value = true;
    }
};

const dismissExitPopup = (): void => {
    showExitPopup.value = false;
    exitPopupDismissed.value = true;
};

const onBeforeWindowUnload = (event: BeforeUnloadEvent): void => {
    if (!hasExitPopupConfig.value || props.roomEnded || props.accessRequired || videoEnded.value) {
        return;
    }

    // Browsers do not allow custom modal UI during unload.
    // Trigger native confirmation on refresh/close/navigation attempts.
    event.preventDefault();
    event.returnValue = '';
};

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

        void trackGeneralCtaClick('redirect', parsed.toString(), 'webinar_redirect_triggered');

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

    if (!trackedToEnd.value) {
        trackedToEnd.value = true;
        void trackWatchMilestone('watched_to_end', elapsedSeconds.value);
    }

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

    if (!tracked50Percent.value && elapsedSeconds.value >= halfwayWatchThreshold.value && props.chatToken) {
        tracked50Percent.value = true;
        void trackWatchMilestone('watched_50_percent', elapsedSeconds.value);
    }

    if (!tracked60Seconds.value && elapsedSeconds.value >= 60 && props.chatToken) {
        tracked60Seconds.value = true;
        void trackWatchMilestone('watched_60_seconds', 60);
    }

    const dur = props.webinar.video_duration_seconds;

    if (dur && !videoEnded.value && elapsedSeconds.value >= dur - 3 && playbackKeepAliveTimer) {
        clearInterval(playbackKeepAliveTimer);
        playbackKeepAliveTimer = null;
    }

    if (!videoEnded.value && dur && elapsedSeconds.value >= dur) {
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

    // Load history only when the user actually opens the chat tab.
    // This prevents one DB-backed request per viewer at join time.
    if (!hasLoadedChat) {
        hasLoadedChat = true;
        void loadServerChat();
    }
};

const openVideoTab = (): void => {
    mobileTab.value = 'video';
    window.setTimeout(tryResumePlayback, 100);
};

const onDocumentVisibilityChange = (): void => {
    if (document.visibilityState === 'visible' && !videoEnded.value) {
        tryResumePlayback();
    }
};

onMounted(() => {
    if (props.roomEnded || props.accessRequired) {
        return;
    }

    window.addEventListener('message', onIframeMessage);
    window.addEventListener('beforeunload', onBeforeWindowUnload);
    document.addEventListener('visibilitychange', onDocumentVisibilityChange);
    document.addEventListener('mouseout', onExitIntent);

    timer = setInterval(tickTimeline, 1000);
    viewersTimer = setInterval(tickViewers, 4000);
    playbackKeepAliveTimer = setInterval(tryResumePlayback, 12000);
    
    const realtimeStarted = startRealtimeChat();
    if (!realtimeStarted) {
        // Longer + jittered polling so a burst doesn't synchronize DB hits.
        const pollIntervalMs = 15000 + Math.floor(Math.random() * 5000);
        chatPollTimer = setInterval(() => {
            void loadServerChat();
        }, pollIntervalMs);

        // Initial fetch when websockets fail.
        hasLoadedChat = true;
        void loadServerChat();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('message', onIframeMessage);
    window.removeEventListener('beforeunload', onBeforeWindowUnload);
    document.removeEventListener('visibilitychange', onDocumentVisibilityChange);
    document.removeEventListener('mouseout', onExitIntent);
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
            class="flex min-h-[60vh] w-full flex-col items-center justify-center rounded-2xl border border-border/60 bg-card p-8 text-center shadow-sm"
        >
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-muted text-muted-foreground mb-5">
                <Icon icon="solar:monitor-camera-bold-duotone" class="size-8" />
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">Webinar Ended</h1>
            <p class="mt-2 max-w-xl text-sm text-muted-foreground">
                {{ endedMessage || 'This webinar has ended. Please contact the host for replay options.' }}
            </p>
        </div>

        <!-- ── MOBILE HEADER + TAB BAR (hidden on lg+) ───────────────── -->
        <div v-if="!roomEnded" class="shrink-0 border-b border-border/50 bg-card lg:hidden">
            <!-- Compact info row -->
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <h1 class="truncate text-sm font-bold leading-tight text-foreground">{{ webinar.title }}</h1>
                    <p class="text-xs text-muted-foreground">{{ viewerCount }} watching · {{ webinar.host_name }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2 text-xs">
                    <span v-if="!videoEnded" class="flex items-center gap-1 rounded-full bg-rose-100 px-2.5 py-0.5 font-bold text-rose-600 dark:bg-rose-950/50 dark:text-rose-400">
                        <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-rose-500" />
                        LIVE
                    </span>
                    <span v-else class="rounded-full bg-muted px-2.5 py-0.5 font-semibold text-muted-foreground">ENDED</span>
                    <span class="tabular-nums font-mono text-muted-foreground">{{ prettyElapsed }}</span>
                </div>
            </div>
            <!-- Tab switcher -->
            <div class="flex border-t border-border/50">
                <button
                    type="button"
                    class="flex flex-1 items-center justify-center gap-1.5 border-b-2 py-2.5 text-sm font-semibold transition-colors"
                    :class="mobileTab === 'video'
                        ? 'border-primary text-primary'
                        : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="openVideoTab"
                >
                    <Icon icon="solar:play-stream-bold-duotone" class="size-4" />
                    Video
                </button>
                <button
                    type="button"
                    class="flex flex-1 items-center justify-center gap-1.5 border-b-2 py-2.5 text-sm font-semibold transition-colors"
                    :class="mobileTab === 'chat'
                        ? 'border-primary text-primary'
                        : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="openChatTab"
                >
                    <Icon icon="solar:chat-round-dots-bold-duotone" class="size-4" />
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
            <div class="rounded-2xl border border-border/60 bg-card p-3 shadow-sm lg:p-4">
                <!-- Desktop-only title/info row -->
                <div class="mb-3 hidden items-center justify-between gap-4 lg:flex">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                            <Icon icon="solar:monitor-camera-bold-duotone" class="size-5" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="truncate text-lg font-bold leading-tight text-foreground">{{ webinar.title }}</h1>
                            <p class="text-xs text-muted-foreground flex items-center gap-1">
                                <Icon icon="solar:user-bold" class="size-3" />
                                {{ webinar.host_name }}
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1 text-xs">
                        <span v-if="!videoEnded" class="flex items-center gap-1.5 rounded-full bg-rose-100 px-2.5 py-1 font-bold text-rose-600 dark:bg-rose-950/50 dark:text-rose-400">
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-rose-500" />
                            LIVE
                        </span>
                        <span v-else class="rounded-full bg-muted px-2.5 py-1 font-semibold text-muted-foreground">ENDED</span>
                        <span class="flex items-center gap-1 text-muted-foreground">
                            <Icon icon="solar:users-group-rounded-bold" class="size-3" />
                            {{ viewerCount }} watching
                        </span>
                        <span class="font-mono tabular-nums text-muted-foreground">{{ prettyElapsed }}</span>
                    </div>
                </div>

                <!-- Video container -->
                <div class="relative overflow-hidden rounded-lg border bg-black">
                    <!-- Meeting ended overlay -->
                    <div
                        v-if="videoEnded"
                        class="absolute inset-0 z-30 flex flex-col items-center justify-center bg-gray-950/95"
                    >
                        <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10 text-white/80">
                            <Icon icon="solar:monitor-camera-bold-duotone" class="size-8" />
                        </div>
                        <h2 class="mt-4 text-xl font-bold text-white">Webinar Ended</h2>
                        <p class="mt-2 max-w-xs text-center text-sm text-white/50">
                            This session has concluded. Thank you for attending.
                        </p>
                    </div>

                    <!-- Click-blocker: always covers iframe to hide YouTube/Vimeo UI on hover -->
                    <div v-if="!videoEnded" class="absolute inset-0 z-10" />

                    <!-- Center sound CTA overlay -->
                    <div
                        v-if="!videoEnded && iframeMuted"
                        class="absolute inset-0 z-25 flex items-center justify-center bg-black/50 p-4"
                    >
                        <button
                            type="button"
                            class="flex items-center gap-2.5 rounded-2xl border border-white/20 bg-white px-6 py-3 text-sm font-bold text-slate-900 shadow-2xl transition hover:bg-white/90"
                            @click="enableSound"
                        >
                            <Icon icon="solar:volume-loud-bold-duotone" class="size-5 text-indigo-600" />
                            Click to Enable Sound
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
            <div v-if="pinnedOffer" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-800/40 dark:bg-amber-950/30">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-200 text-amber-700 dark:bg-amber-900/60 dark:text-amber-400">
                        <Icon icon="solar:tag-price-bold-duotone" class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">Pinned Offer</p>
                        <h3 class="mt-0.5 text-sm font-bold text-amber-900 dark:text-amber-200">{{ pinnedOffer.title }}</h3>
                        <p v-if="pinnedOffer.description" class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ pinnedOffer.description }}</p>
                        <a
                            class="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-amber-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-amber-700"
                            :href="pinnedOffer.button_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            @click="void trackOfferClick(pinnedOffer, 'pinned')"
                        >
                            <Icon icon="solar:arrow-right-up-bold" class="size-3" />
                            {{ pinnedOffer.button_text }}
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── CHAT ASIDE ──────────────────────────────────────────────── -->
        <!--
            On mobile: fills remaining height when on chat tab.
            On desktop: fixed-width sidebar, always visible.
        -->
        <aside
            v-if="!roomEnded"
            class="order-2 flex-col overflow-hidden bg-card shadow-sm lg:h-full lg:w-95 lg:min-w-90 lg:max-w-105 lg:self-stretch lg:rounded-2xl lg:border lg:border-border/60"
            :class="mobileTab === 'chat' ? 'flex flex-1' : 'hidden lg:flex'"
        >
            <!-- Desktop-only header -->
            <div class="hidden border-b border-border/50 bg-card px-4 py-3.5 lg:block">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400">
                        <Icon icon="solar:chat-round-dots-bold-duotone" class="size-4" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-foreground leading-tight">In-call chat</p>
                        <p class="text-[11px] text-muted-foreground">{{ registrant.name || 'Guest' }}</p>
                    </div>
                    <span v-if="!videoEnded" class="ml-auto flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-600 dark:bg-rose-950/50 dark:text-rose-400">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-rose-500" />
                        LIVE
                    </span>
                </div>
            </div>

            <!-- Chat / Offers sub-tabs -->
            <div class="flex items-center gap-2 border-b border-border/50 bg-muted/20 px-3 py-2.5">
                <button
                    type="button"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
                    :class="roomPanel === 'chat' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground hover:bg-muted'"
                    @click="roomPanel = 'chat'"
                >
                    <Icon icon="solar:chat-round-dots-bold" class="size-3.5" />
                    Chat
                </button>
                <button
                    type="button"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
                    :class="roomPanel === 'offers' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground hover:bg-muted'"
                    @click="roomPanel = 'offers'"
                >
                    <Icon icon="solar:tag-price-bold" class="size-3.5" />
                    Offers
                    <span
                        v-if="droppedOffers.length > 0"
                        class="rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] leading-none text-white"
                    >{{ droppedOffers.length }}</span>
                </button>
            </div>

            <!-- Pinned welcome message -->
            <div class="flex items-start gap-2 border-b border-amber-200/60 bg-amber-50/80 px-3 py-2.5 dark:border-amber-800/30 dark:bg-amber-950/20">
                <Icon icon="solar:pin-bold" class="mt-0.5 size-3.5 shrink-0 text-amber-600" />
                <p class="text-xs text-amber-900 dark:text-amber-300"><span class="font-semibold">Pinned:</span> {{ pinnedStarterMessage }}</p>
            </div>

            <!-- Chat messages -->
            <div
                v-if="roomPanel === 'chat'"
                ref="chatScrollContainer"
                class="flex-1 space-y-3 overflow-y-auto bg-muted/10 px-3 py-4"
            >
                <div
                    v-for="message in chatMessages"
                    :key="message.id"
                    class="flex"
                    :class="message.self ? 'justify-end' : 'justify-start'"
                >
                    <div
                        class="max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm shadow-sm"
                        :class="message.self
                            ? 'rounded-tr-none bg-primary text-primary-foreground'
                            : 'rounded-tl-none bg-background text-foreground border border-border/60'"
                    >
                        <div class="mb-1 flex items-center justify-between gap-2 text-[11px]">
                            <p class="font-bold" :class="message.self ? 'opacity-80' : 'text-muted-foreground'">{{ message.sender }}</p>
                            <p class="opacity-50">{{ message.at }}</p>
                        </div>
                        <p
                            class="leading-relaxed"
                            v-html="linkifyMessage(message.message)"
                            @click="onChatMessageClick($event, message.id)"
                        />
                    </div>
                </div>
            </div>

            <!-- Offers panel -->
            <div v-else class="flex-1 space-y-3 overflow-y-auto bg-muted/10 px-3 py-4">
                <div
                    v-for="offer in droppedOffers"
                    :key="offer.id"
                    class="rounded-2xl border border-amber-200 bg-amber-50 p-3.5 shadow-sm dark:border-amber-800/40 dark:bg-amber-950/30"
                >
                    <div class="flex items-start gap-2.5">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-200 text-amber-700">
                            <Icon icon="solar:tag-price-bold-duotone" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-amber-600">Offer</p>
                            <h3 class="mt-0.5 text-sm font-bold text-amber-900 dark:text-amber-200">{{ offer.title }}</h3>
                            <p v-if="offer.description" class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ offer.description }}</p>
                            <a
                                class="mt-2.5 inline-flex items-center gap-1.5 rounded-xl bg-amber-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-amber-700"
                                :href="offer.button_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                @click="void trackOfferClick(offer, 'offers-panel')"
                            >
                                <Icon icon="solar:arrow-right-up-bold" class="size-3" />
                                {{ offer.button_text }}
                            </a>
                        </div>
                    </div>
                </div>
                <div v-if="droppedOffers.length === 0" class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border py-10 text-center">
                    <Icon icon="solar:tag-price-bold-duotone" class="size-8 text-muted-foreground/30 mb-3" />
                    <p class="text-sm font-medium text-muted-foreground">No offers yet</p>
                    <p class="mt-1 text-xs text-muted-foreground/60">Offers will appear here when they drop.</p>
                </div>
            </div>

            <!-- Chat input -->
            <form class="shrink-0 border-t border-border/50 bg-card px-3 py-3" @submit.prevent="sendChat">
                <div class="flex items-center gap-2">
                    <input
                        v-model="chatInput"
                        class="h-11 flex-1 rounded-2xl border border-input bg-muted/40 px-4 text-sm placeholder:text-muted-foreground/60 focus:outline-none focus:ring-2 focus:ring-primary/30"
                        placeholder="Send a message…"
                        @keydown.enter.exact.prevent="sendChat"
                    />
                    <button
                        type="submit"
                        :disabled="Boolean(accessRequired) || !chatInput.trim()"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-sm transition hover:bg-primary/90 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <Icon icon="solar:plain-bold" class="size-4" />
                    </button>
                </div>
            </form>
        </aside>

        <!-- ── Access gate modal ──────────────────────────────────────── -->
        <div
            v-if="accessRequired && !roomEnded"
            class="absolute inset-0 z-40 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
        >
            <form
                class="w-full max-w-md rounded-2xl border border-border/60 bg-card p-6 shadow-2xl"
                @submit.prevent="submitAccess"
            >
                <div class="mb-5 flex items-start gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                        <Icon icon="solar:monitor-camera-bold-duotone" class="size-6" />
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-foreground">Join Webinar</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Enter your details to join. Registered attendees get instant access.
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-foreground">Full Name <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <Icon icon="solar:user-linear" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                v-model="gateForm.name"
                                class="h-11 w-full rounded-xl border border-input bg-background pl-9 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                                placeholder="Your full name"
                                required
                            />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-foreground">Email Address <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <Icon icon="solar:letter-linear" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                v-model="gateForm.email"
                                type="email"
                                class="h-11 w-full rounded-xl border border-input bg-background pl-9 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                                placeholder="you@example.com"
                                required
                            />
                        </div>
                    </div>
                </div>

                <button
                    type="submit"
                    :disabled="gateForm.processing"
                    class="mt-5 flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 text-sm font-bold text-primary-foreground shadow-sm transition hover:bg-primary/90 disabled:opacity-50"
                >
                    <Icon icon="solar:login-2-bold" class="size-4" />
                    {{ gateForm.processing ? 'Joining…' : 'Continue to Webinar' }}
                </button>
            </form>
        </div>

        <!-- ── Popup offer ────────────────────────────────────────────── -->
        <div
            v-if="popupOffer && !roomEnded"
            class="fixed bottom-4 right-4 z-50 w-[calc(100vw-2rem)] max-w-sm rounded-2xl border border-amber-200 bg-white p-4 shadow-2xl sm:w-auto dark:border-amber-800/40 dark:bg-neutral-900"
        >
            <div class="flex items-start gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
                    <Icon icon="solar:tag-price-bold-duotone" class="size-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-amber-600">Special Offer</p>
                    <h3 class="mt-0.5 text-sm font-bold text-foreground">{{ popupOffer.title }}</h3>
                    <div
                        v-if="popupOffer.description"
                        class="prose prose-sm mt-1 max-h-28 overflow-y-auto text-xs text-muted-foreground prose-p:my-1 dark:prose-invert"
                        v-html="popupOffer.description"
                    />
                    <div class="mt-3 flex items-center gap-2">
                        <a
                            class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-3.5 py-1.5 text-xs font-bold text-primary-foreground shadow-sm transition hover:bg-primary/90"
                            :href="popupOffer.button_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            @click="void trackOfferClick(popupOffer, 'popup')"
                        >
                            <Icon icon="solar:arrow-right-up-bold" class="size-3" />
                            {{ popupOffer.button_text }}
                        </a>
                        <button class="text-xs text-muted-foreground hover:text-foreground transition" type="button" @click="popupOffer = null">Dismiss</button>
                    </div>
                </div>
                <button type="button" class="shrink-0 text-muted-foreground hover:text-foreground transition" @click="popupOffer = null">
                    <Icon icon="solar:close-circle-bold" class="size-4" />
                </button>
            </div>
        </div>

        <!-- ── Exit intent popup ──────────────────────────────────────── -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="showExitPopup"
                class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                @click.self="dismissExitPopup"
            >
                <div class="relative w-full max-w-md rounded-2xl border border-border/60 bg-card p-6 shadow-2xl">
                    <!-- Close button -->
                    <button
                        type="button"
                        class="absolute right-4 top-4 text-muted-foreground transition hover:text-foreground"
                        @click="dismissExitPopup"
                    >
                        <Icon icon="solar:close-circle-bold" class="size-5" />
                    </button>

                    <!-- Icon badge -->
                    <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 text-rose-600 dark:bg-rose-950/50 dark:text-rose-400">
                        <Icon icon="solar:hand-shake-bold-duotone" class="size-7" />
                    </div>

                    <!-- Heading -->
                    <h2 class="text-xl font-bold text-foreground">
                        {{ webinar.playback_settings?.exit_popup_heading || 'Wait — are you sure?' }}
                    </h2>

                    <!-- Body text -->
                    <div
                        v-if="webinar.playback_settings?.exit_popup_body"
                        class="prose prose-sm mt-2 max-h-36 overflow-y-auto text-muted-foreground leading-relaxed prose-p:my-1 prose-ul:my-1 prose-ol:my-1 prose-li:my-0.5 dark:prose-invert"
                        v-html="webinar.playback_settings.exit_popup_body"
                    />

                    <!-- CTAs -->
                    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <a
                            v-if="webinar.playback_settings?.exit_popup_cta_url"
                            :href="webinar.playback_settings.exit_popup_cta_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-primary-foreground shadow-sm transition hover:bg-primary/90"
                            @click="
                                void trackGeneralCtaClick(
                                    'exit-popup',
                                    webinar.playback_settings.exit_popup_cta_url,
                                    'webinar_cta_link_clicked',
                                );
                                dismissExitPopup();
                            "
                        >
                            <Icon icon="solar:arrow-right-up-bold" class="size-4" />
                            {{ webinar.playback_settings.exit_popup_cta_text || 'Get the Offer' }}
                        </a>
                        <button
                            type="button"
                            class="flex-1 rounded-xl border border-border px-4 py-2.5 text-sm font-medium text-muted-foreground transition hover:bg-muted sm:flex-none"
                            @click="dismissExitPopup"
                        >
                            Stay &amp; Watch
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
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
