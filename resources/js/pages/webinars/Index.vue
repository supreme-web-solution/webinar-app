<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Icon } from '@iconify/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BreadcrumbItem } from '@/types';

type WebinarListItem = {
    id: number;
    uuid: string;
    title: string;
    schedule_mode: 'auto' | 'scheduled';
    has_ended: boolean;
    scheduled_at_label: string | null;
    scheduled_timezone: string;
    host_name: string;
    video_source: string;
    is_published: boolean;
    registrants_count: number;
    views_count: number;
    registration_link: string;
    room_link: string;
    chat_link: string;
    notify_link: string;
    updated_at: string | null;
};

const props = defineProps<{
    webinars: {
        data: WebinarListItem[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Webinars', href: '/admin/webinars' },
];

const toastMessage = ref<string | null>(null);
const toastType = ref<'success' | 'info'>('success');
const selectedWebinarIds = ref<number[]>([]);
const filtersOpen = ref(false);
const filterSearch = ref('');
const filterSource = ref<'all' | 'youtube' | 'vimeo' | 'direct'>('all');
const filterStatus = ref<'all' | 'published' | 'draft' | 'ended'>('all');
const filterScheduleMode = ref<'all' | 'auto' | 'scheduled'>('all');

type AiStep = 'brief' | 'script' | 'video';
type AiVideoStatus = 'idle' | 'requesting' | 'pending' | 'processing' | 'completed' | 'failed';

const aiModalOpen = ref(false);
const aiStep = ref<AiStep>('brief');
const aiScript = ref('');
const aiScriptModel = ref<string | null>(null);
const aiVideoStatus = ref<AiVideoStatus>('idle');
const aiVideoId = ref<string | null>(null);
const aiVideoUrl = ref<string | null>(null);
const aiVideoProvider = ref<'heygen' | 'cloudinary' | null>(null);
const aiWebinarId = ref<number | null>(null);
const aiVideoMessage = ref<string | null>(null);
const aiVideoPhase = ref<string>('idle');
const aiComposeStage = ref<string>('');
const aiVideoProgressPercent = ref(0);
const aiLoadingScript = ref(false);
const aiLoadingVideo = ref(false);
const aiCreatingWebinar = ref(false);
const aiLoadingHeygenOptions = ref(false);
const aiHeygenOptionsError = ref<string | null>(null);
const allowAiModalClose = ref(false);
let aiPollTimer: number | null = null;
let aiVideoOverlayTimer: number | null = null;
let voicePreviewAudio: HTMLAudioElement | null = null;
const voicePreviewAudioCache = new Map<string, string>();
const voicePreviewLoadingVoiceId = ref<string | null>(null);
const voicePreviewPlayingVoiceId = ref<string | null>(null);

type HeygenAvatarOption = {
    id: string;
    name: string;
    preview_url?: string | null;
    gender?: string | null;
};

type OpenAiVoiceOption = {
    id: string;
    label: string;
    gender?: string | null;
    style?: string | null;
};

type SlidePlanItem = {
    title: string;
    bullets: string[];
};

const avatarOptions = ref<HeygenAvatarOption[]>([]);
const openAiVoiceOptions = ref<OpenAiVoiceOption[]>([]);
const aiAvatarPage = ref(1);
const aiOptionsPageSize = 20;
const aiVideoOverlayMessages = [
    'Analyzing your script...',
    'Structuring content into scenes...',
    'Generating webinar outline...',
    'Preparing slides from your content...',
    'Designing slide layouts...',
    'Optimizing script for voice delivery...',
    'Generating voice narration...',
    'Splitting audio into segments...',
    'Preparing avatar intro...',
    'Syncing avatar with voice...',
    'Rendering intro video...',
    'Rendering slide visuals...',
    'Merging slides with narration...',
    'Applying transitions and timing...',
    'Finalizing video composition...',
    'Optimizing video quality...',
    'Almost ready...',
];
const aiVideoOverlayMessageIndex = ref(0);
const aiIntroScript = ref('');
const aiRemainingScript = ref('');
const aiSlidePlan = ref<SlidePlanItem[]>([]);
const aiStatusReadFailureCount = ref(0);

const webinarTypeOptions = [
    { label: 'Sales Webinar', value: 'Sales Webinar' },
    { label: 'Training Webinar', value: 'Training Webinar' },
    { label: 'Workshop Webinar', value: 'Workshop Webinar' },
    { label: 'Product Demo Webinar', value: 'Product Demo Webinar' },
    { label: 'Type custom webinar type...', value: '__custom__' },
];

const audienceOptions = [
    { label: 'Coaches and agency owners', value: 'Coaches and agency owners' },
    { label: 'Ecommerce founders', value: 'Ecommerce founders' },
    { label: 'SaaS business owners', value: 'SaaS business owners' },
    { label: 'Freelancers and consultants', value: 'Freelancers and consultants' },
    { label: 'Type custom audience...', value: '__custom__' },
];

const toneOptions = [
    { label: 'Authoritative and persuasive', value: 'authoritative and persuasive' },
    { label: 'Friendly and conversational', value: 'friendly and conversational' },
    { label: 'Educational and practical', value: 'educational and practical' },
    { label: 'Urgent and action-driven', value: 'urgent and action-driven' },
    { label: 'Type custom tone...', value: '__custom__' },
];

const selectedAvatarOption = ref('__custom__');
const customAvatarId = ref('');
const selectedOpenAiVoiceOption = ref('');
const advancedSlideSettingsOpen = ref(false);
const selectedWebinarTypeOption = ref('Sales Webinar');
const customWebinarType = ref('');
const selectedAudienceOption = ref('__custom__');
const customAudience = ref('');
const selectedToneOption = ref('authoritative and persuasive');
const customTone = ref('');

const aiBrief = reactive({
    title: '',
    topic: '',
    webinar_type: 'Sales Webinar',
    audience: '',
    goal: '',
    tone: 'authoritative and persuasive',
    duration_minutes: 45,
    language: 'English',
    host_name: '',
    avatar_id: '',
    openai_voice: '',
    intro_duration_seconds: 45,
    aspect_ratio: '16:9' as '16:9' | '9:16' | '1:1',
    background_color: '#F8FAFC',
    slide_style: {
        font_size: 44,
        text_color: '#FFFFFF',
        outline_color: '#101820',
        accent_color: '#6366F1',
        overlay_color: '#0B1020',
        overlay_alpha: 0.22,
        background_color: '#0F172A',
        background_image_url: '',
        generate_images: false,
        image_style: 'realistic',
    },
});

const clampNumber = (value: unknown, min: number, max: number, fallback: number): number => {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        return fallback;
    }

    return Math.min(max, Math.max(min, parsed));
};

const clampAiBriefNumericFields = (): void => {
    aiBrief.duration_minutes = clampNumber(aiBrief.duration_minutes, 20, 120, 45);
    aiBrief.intro_duration_seconds = clampNumber(aiBrief.intro_duration_seconds, 20, 60, 45);
    aiBrief.slide_style.font_size = clampNumber(aiBrief.slide_style.font_size, 24, 72, 44);
    aiBrief.slide_style.overlay_alpha = clampNumber(aiBrief.slide_style.overlay_alpha, 0, 1, 0.22);
};

const aiCanGenerateScript = computed(() => {
    return aiBrief.title.trim() !== ''
        && aiBrief.topic.trim() !== ''
        && aiBrief.webinar_type.trim() !== ''
        && aiBrief.audience.trim() !== ''
        && aiBrief.goal.trim() !== ''
        && aiBrief.duration_minutes >= 20;
});

const aiCanGenerateVideo = computed(() => {
    return aiScript.value.trim().length >= 300
        && aiBrief.avatar_id.trim() !== ''
        && aiBrief.openai_voice.trim() !== '';
});

const filteredWebinars = computed<WebinarListItem[]>(() => {
    const search = filterSearch.value.trim().toLowerCase();

    return props.webinars.data.filter((webinar) => {
        const sourceMatch = filterSource.value === 'all' || webinar.video_source === filterSource.value;
        if (!sourceMatch) return false;

        const scheduleModeMatch = filterScheduleMode.value === 'all' || webinar.schedule_mode === filterScheduleMode.value;
        if (!scheduleModeMatch) return false;

        const statusValue = webinar.has_ended ? 'ended' : webinar.is_published ? 'published' : 'draft';
        const statusMatch = filterStatus.value === 'all' || statusValue === filterStatus.value;
        if (!statusMatch) return false;

        if (search === '') return true;

        return [
            webinar.title,
            webinar.host_name,
            webinar.uuid,
            webinar.scheduled_at_label || '',
            webinar.video_source,
        ]
            .join(' ')
            .toLowerCase()
            .includes(search);
    });
});

const activeFilterCount = computed(() => {
    let count = 0;
    if (filterSearch.value.trim() !== '') count += 1;
    if (filterSource.value !== 'all') count += 1;
    if (filterStatus.value !== 'all') count += 1;
    if (filterScheduleMode.value !== 'all') count += 1;
    return count;
});

const clearFilters = (): void => {
    filterSearch.value = '';
    filterSource.value = 'all';
    filterStatus.value = 'all';
    filterScheduleMode.value = 'all';
};

const allSelectedOnPage = computed<boolean>(() =>
    filteredWebinars.value.length > 0
    && filteredWebinars.value.every((webinar) => selectedWebinarIds.value.includes(webinar.id)),
);

const estimatedDurationSeconds = computed(() => {
    const words = aiScript.value.trim().split(/\s+/).filter((part) => part.length > 0).length;
    if (words === 0) {
        return null;
    }

    return Math.max(60, Math.round((words / 130) * 60));
});

const csrfToken = (): string => {
    const tokenTag = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
    if (tokenTag?.content) {
        return tokenTag.content;
    }

    const xsrfCookie = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith('XSRF-TOKEN='));

    if (!xsrfCookie) {
        return '';
    }

    return decodeURIComponent(xsrfCookie.substring('XSRF-TOKEN='.length));
};

const resetAiState = (): void => {
    aiStep.value = 'brief';
    aiScript.value = '';
    aiScriptModel.value = null;
    aiVideoStatus.value = 'idle';
    aiVideoId.value = null;
    aiVideoUrl.value = null;
    aiVideoProvider.value = null;
    aiWebinarId.value = null;
    aiVideoMessage.value = null;
    aiVideoPhase.value = 'idle';
    aiComposeStage.value = '';
    aiVideoProgressPercent.value = 0;
    aiIntroScript.value = '';
    aiRemainingScript.value = '';
    aiSlidePlan.value = [];
    advancedSlideSettingsOpen.value = false;
    aiLoadingScript.value = false;
    aiLoadingVideo.value = false;
    aiCreatingWebinar.value = false;
    aiVideoOverlayMessageIndex.value = 0;
    if (aiVideoOverlayTimer !== null) {
        window.clearInterval(aiVideoOverlayTimer);
        aiVideoOverlayTimer = null;
    }
    if (aiPollTimer !== null) {
        window.clearTimeout(aiPollTimer);
        aiPollTimer = null;
    }
    if (voicePreviewAudio) {
        voicePreviewAudio.pause();
        voicePreviewAudio.currentTime = 0;
        voicePreviewAudio = null;
    }
    voicePreviewLoadingVoiceId.value = null;
    voicePreviewPlayingVoiceId.value = null;
    for (const objectUrl of voicePreviewAudioCache.values()) {
        URL.revokeObjectURL(objectUrl);
    }
    voicePreviewAudioCache.clear();
};

const AI_SLIDE_STYLE_CACHE_KEY = 'webinar-ai:slide-style:v1';
const AI_VIDEO_RUNTIME_CACHE_KEY = 'webinar-ai:video-runtime:v1';
const AI_ACTIVE_VIDEO_GLOBAL_KEY = 'webinar-ai:active-video:v1';

type AiVideoRuntimeCache = {
    video_id: string;
    webinar_id: number | null;
};

type AiActiveVideoGlobalCache = {
    video_id: string;
    updated_at: string;
};

const globalActiveVideoId = ref<string | null>(null);

const loadGlobalActiveVideoId = (): void => {
    try {
        const raw = window.localStorage.getItem(AI_ACTIVE_VIDEO_GLOBAL_KEY);
        if (!raw) {
            globalActiveVideoId.value = null;
            return;
        }
        const parsed = JSON.parse(raw) as Partial<AiActiveVideoGlobalCache>;
        const videoId = String(parsed.video_id || '').trim();
        globalActiveVideoId.value = videoId || null;
    } catch {
        globalActiveVideoId.value = null;
    }
};

const saveGlobalActiveVideoId = (videoId: string): void => {
    try {
        const payload: AiActiveVideoGlobalCache = {
            video_id: videoId,
            updated_at: new Date().toISOString(),
        };
        window.localStorage.setItem(AI_ACTIVE_VIDEO_GLOBAL_KEY, JSON.stringify(payload));
        globalActiveVideoId.value = videoId;
    } catch {
        // ignore storage errors
    }
};

const clearGlobalActiveVideoId = (): void => {
    try {
        window.localStorage.removeItem(AI_ACTIVE_VIDEO_GLOBAL_KEY);
    } catch {
        // ignore storage errors
    } finally {
        globalActiveVideoId.value = null;
    }
};

const loadSlideStyleCache = (): void => {
    try {
        const raw = window.sessionStorage.getItem(AI_SLIDE_STYLE_CACHE_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw) as Partial<typeof aiBrief.slide_style>;
        aiBrief.slide_style = {
            ...aiBrief.slide_style,
            ...parsed,
        };
    } catch {
        // ignore cache errors
    }
};

const saveSlideStyleCache = (): void => {
    try {
        window.sessionStorage.setItem(AI_SLIDE_STYLE_CACHE_KEY, JSON.stringify(aiBrief.slide_style));
    } catch {
        // ignore cache errors
    }
};

const saveAiVideoRuntimeCache = (): void => {
    if (!aiVideoId.value) {
        return;
    }

    try {
        const payload: AiVideoRuntimeCache = {
            video_id: aiVideoId.value,
            webinar_id: aiWebinarId.value,
        };
        window.sessionStorage.setItem(AI_VIDEO_RUNTIME_CACHE_KEY, JSON.stringify(payload));
    } catch {
        // ignore cache errors
    }
};

const clearAiVideoRuntimeCache = (): void => {
    try {
        window.sessionStorage.removeItem(AI_VIDEO_RUNTIME_CACHE_KEY);
    } catch {
        // ignore cache errors
    }
};

const restoreAiVideoRuntimeCache = (): boolean => {
    try {
        const raw = window.sessionStorage.getItem(AI_VIDEO_RUNTIME_CACHE_KEY);
        if (!raw) {
            return false;
        }

        const parsed = JSON.parse(raw) as Partial<AiVideoRuntimeCache>;
        const videoId = String(parsed.video_id || '').trim();
        if (!videoId) {
            return false;
        }

        aiVideoId.value = videoId;
        aiWebinarId.value = typeof parsed.webinar_id === 'number' ? parsed.webinar_id : null;
        aiStep.value = 'video';
        aiVideoStatus.value = 'pending';
        aiVideoMessage.value = 'Resuming generation status after refresh. Long videos can take a while.';
        return true;
    } catch {
        return false;
    }
};

const restoreFromGlobalActiveVideo = (): boolean => {
    loadGlobalActiveVideoId();
    const videoId = String(globalActiveVideoId.value || '').trim();
    if (!videoId) return false;
    if (aiVideoId.value === videoId && ['requesting', 'pending', 'processing', 'completed'].includes(aiVideoStatus.value)) {
        return true;
    }

    aiVideoId.value = videoId;
    aiWebinarId.value = null;
    aiStep.value = 'video';
    aiVideoStatus.value = 'pending';
    aiVideoPhase.value = 'queued';
    aiVideoMessage.value = 'A video is already rendering in another tab. Resuming status...';
    return true;
};

const openAiModal = (): void => {
    allowAiModalClose.value = false;
    resetAiState();
    aiBrief.title = '';
    aiBrief.topic = '';
    aiBrief.webinar_type = 'Sales Webinar';
    aiBrief.audience = '';
    aiBrief.goal = '';
    aiBrief.tone = 'authoritative and persuasive';
    aiBrief.duration_minutes = 45;
    aiBrief.language = 'English';
    aiBrief.host_name = '';
    aiBrief.avatar_id = '';
    aiBrief.openai_voice = '';
    aiBrief.intro_duration_seconds = 45;
    aiBrief.aspect_ratio = '16:9';
    aiBrief.background_color = '#F8FAFC';
    aiBrief.slide_style = {
        font_size: 44,
        text_color: '#FFFFFF',
        outline_color: '#101820',
        accent_color: '#6366F1',
        overlay_color: '#0B1020',
        overlay_alpha: 0.22,
        background_color: '#0F172A',
        background_image_url: '',
        generate_images: false,
        image_style: 'realistic',
    };
    selectedAvatarOption.value = '__custom__';
    customAvatarId.value = '';
    selectedOpenAiVoiceOption.value = '';
    selectedWebinarTypeOption.value = 'Sales Webinar';
    customWebinarType.value = '';
    selectedAudienceOption.value = '__custom__';
    customAudience.value = '';
    selectedToneOption.value = 'authoritative and persuasive';
    customTone.value = '';
    aiModalOpen.value = true;
    loadSlideStyleCache();
    void loadAiOptions();
    if (restoreAiVideoRuntimeCache() || restoreFromGlobalActiveVideo()) {
        void pollVideoStatus();
    }
};

window.addEventListener('storage', (e) => {
    if (e.key !== AI_ACTIVE_VIDEO_GLOBAL_KEY) return;
    loadGlobalActiveVideoId();
});

loadGlobalActiveVideoId();

const aiHasActiveVideoElsewhere = computed((): boolean => {
    const active = String(globalActiveVideoId.value || '').trim();
    if (!active) return false;
    return !aiVideoId.value || aiVideoId.value !== active || ['requesting', 'pending', 'processing'].includes(aiVideoStatus.value) === false;
});

const aiActiveVideoHoverMessage = computed((): string => {
    const active = String(globalActiveVideoId.value || '').trim();
    if (!active) return '';
    return 'An AI video is currently rendering (possibly in another tab). Please wait for it to finish.';
});

const aiButtonBusyTexts = ['AI running...', 'Generating...', 'Please wait...'];
const aiButtonBusyIndex = ref(0);
let aiButtonBusyTimer: number | null = null;

watch(aiHasActiveVideoElsewhere, (blocked) => {
    if (!blocked) {
        aiButtonBusyIndex.value = 0;
        if (aiButtonBusyTimer !== null) {
            window.clearInterval(aiButtonBusyTimer);
            aiButtonBusyTimer = null;
        }
        return;
    }

    if (aiButtonBusyTimer !== null) {
        return;
    }

    aiButtonBusyTimer = window.setInterval(() => {
        aiButtonBusyIndex.value = (aiButtonBusyIndex.value + 1) % aiButtonBusyTexts.length;
    }, 3000);
}, { immediate: true });

onBeforeUnmount(() => {
    if (aiButtonBusyTimer !== null) {
        window.clearInterval(aiButtonBusyTimer);
        aiButtonBusyTimer = null;
    }
});

const createWithAiButtonText = computed((): string => {
    if (aiHasActiveVideoElsewhere.value) {
        return aiButtonBusyTexts[aiButtonBusyIndex.value] || 'AI running...';
    }
    return 'Create with AI';
});

const closeConfirmationMessage = computed((): string => {
    if (['requesting', 'pending', 'processing'].includes(aiVideoStatus.value)) {
        return 'Video is still processing. Are you sure you want to close this modal?';
    }

    return 'Are you sure you want to close?';
});

const closeAiModal = (): void => {
    allowAiModalClose.value = true;
    aiModalOpen.value = false;
};

const requestCloseAiModal = (): void => {
    const shouldClose = window.confirm(closeConfirmationMessage.value);
    if (!shouldClose) {
        return;
    }

    closeAiModal();
};

const onAiModalOpenChange = (nextOpen: boolean): void => {
    if (nextOpen) {
        aiModalOpen.value = true;
        return;
    }

    if (!allowAiModalClose.value) {
        aiModalOpen.value = true;
        return;
    }

    aiModalOpen.value = false;
    allowAiModalClose.value = false;
};

const parseErrorMessage = async (response: Response, fallback: string): Promise<string> => {
    try {
        const payload = await response.json() as { message?: string };
        return payload.message || fallback;
    } catch {
        return fallback;
    }
};

const loadAiOptions = async (): Promise<void> => {
    aiLoadingHeygenOptions.value = true;
    aiHeygenOptionsError.value = null;

    try {
        const response = await fetch('/admin/webinars/ai/options', {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            aiHeygenOptionsError.value = await parseErrorMessage(response, 'Failed to load AI options.');
            return;
        }

        const payload = await response.json() as {
            avatars?: Array<{ id: string; name: string; preview_url?: string | null; gender?: string | null }>;
            openai_voices?: Array<{ id: string; label?: string; gender?: string | null; style?: string | null }>;
            message?: string;
            stale?: boolean;
        };

        const avatars = Array.isArray(payload.avatars) ? payload.avatars : [];
        const openAiVoices = Array.isArray(payload.openai_voices) ? payload.openai_voices : [];

        avatarOptions.value = avatars.map((item) => ({
            id: item.id,
            name: item.name || item.id,
            preview_url: item.preview_url ?? null,
            gender: item.gender ?? null,
        }));

        openAiVoiceOptions.value = openAiVoices.map((item) => ({
            id: item.id,
            label: item.label || item.id,
            gender: item.gender ?? null,
            style: item.style ?? null,
        }));
        aiAvatarPage.value = 1;

        if (avatars.length > 0) {
            selectedAvatarOption.value = avatars[0].id;
            aiBrief.avatar_id = avatars[0].id;
        } else {
            selectedAvatarOption.value = '__custom__';
            aiBrief.avatar_id = '';
        }

        if (openAiVoices.length > 0) {
            selectedOpenAiVoiceOption.value = openAiVoices[0].id;
            aiBrief.openai_voice = openAiVoices[0].id;
        } else {
            selectedOpenAiVoiceOption.value = '';
            aiBrief.openai_voice = '';
        }

        if (payload.message) {
            aiHeygenOptionsError.value = payload.message;
        }
    } catch {
        aiHeygenOptionsError.value = 'Failed to load AI options.';
    } finally {
        aiLoadingHeygenOptions.value = false;
    }
};

const totalAvatarPages = computed(() => Math.max(1, Math.ceil(avatarOptions.value.length / aiOptionsPageSize)));

const paginatedAvatarOptions = computed(() => {
    const start = (aiAvatarPage.value - 1) * aiOptionsPageSize;
    return avatarOptions.value.slice(start, start + aiOptionsPageSize);
});


const generateScript = async (): Promise<void> => {
    if (!aiCanGenerateScript.value || aiLoadingScript.value) {
        return;
    }

    aiLoadingScript.value = true;

    try {
        const response = await fetch('/admin/webinars/ai/script', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                topic: aiBrief.topic,
                webinar_type: aiBrief.webinar_type,
                audience: aiBrief.audience,
                goal: aiBrief.goal,
                tone: aiBrief.tone,
                duration_minutes: aiBrief.duration_minutes,
                language: aiBrief.language,
                host_name: aiBrief.host_name.trim() || null,
            }),
        });

        if (!response.ok) {
            showToast(await parseErrorMessage(response, 'Failed to generate script.'), 'info');
            return;
        }

        const payload = await response.json() as {
            script: string;
            model?: string;
        };

        aiScript.value = payload.script || '';
        aiScriptModel.value = payload.model ?? null;
        aiStep.value = 'script';
        showToast('Script generated. Review and edit before rendering video.');
    } catch {
        showToast('Failed to generate script.', 'info');
    } finally {
        aiLoadingScript.value = false;
    }
};

const pollVideoStatus = async (): Promise<void> => {
    if (!aiVideoId.value) {
        return;
    }

    try {
        const response = await fetch(`/admin/webinars/ai/video/status?video_id=${encodeURIComponent(aiVideoId.value)}`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            aiStatusReadFailureCount.value += 1;
            aiVideoStatus.value = 'processing';
            aiVideoMessage.value = aiStatusReadFailureCount.value >= 4
                ? 'Status API is slow, but generation may still be running. Retrying...'
                : await parseErrorMessage(response, 'Temporary status read issue. Retrying...');
            aiPollTimer = window.setTimeout(() => {
                void pollVideoStatus();
            }, 10000);
            return;
        }

        const payload = await response.json() as {
            status: string;
            video_url?: string | null;
            cloudinary_uploaded?: boolean;
            composing_long_form?: boolean;
            compose_status?: string;
            compose_stage?: string;
            phase?: string;
            progress_percent?: number;
        };

        const normalized = String(payload.status || '').toLowerCase();
        const composeStatus = String(payload.compose_status || '').toLowerCase();
        const composeInProgress = composeStatus === 'processing';
        aiVideoPhase.value = String(payload.phase || '').toLowerCase() || normalized || 'processing';
        aiComposeStage.value = String(payload.compose_stage || '').toLowerCase();
        const incomingProgress = Math.max(0, Math.min(100, Number(payload.progress_percent ?? aiVideoProgressPercent.value)));
        aiVideoProgressPercent.value = Math.max(aiVideoProgressPercent.value, incomingProgress);

        if ((normalized === 'completed' || normalized === 'success') && composeInProgress) {
            aiVideoStatus.value = 'processing';
            aiVideoMessage.value = 'Intro is ready. Final compose is still running (slides + merge + upload)...';
            aiVideoProvider.value = null;
            aiVideoUrl.value = null;
            aiPollTimer = window.setTimeout(() => {
                void pollVideoStatus();
            }, 8000);
            return;
        }

        if (normalized === 'completed' || normalized === 'success') {
            aiVideoStatus.value = 'completed';
            aiVideoUrl.value = payload.video_url ?? null;
            aiVideoProvider.value = payload.cloudinary_uploaded ? 'cloudinary' : 'heygen';
            aiStatusReadFailureCount.value = 0;
            if (payload.composing_long_form && !aiVideoUrl.value) {
                aiVideoMessage.value = 'Composing full webinar video (intro + slides). This may take longer for long scripts...';
                aiVideoProgressPercent.value = Math.max(aiVideoProgressPercent.value, 82);
            } else {
                aiVideoMessage.value = aiVideoUrl.value
                    ? 'Video completed successfully.'
                    : 'Video is completed but URL is still unavailable. Check again shortly.';
                if (aiVideoUrl.value) {
                    aiVideoProgressPercent.value = 100;
                    aiVideoPhase.value = 'completed';
                    clearGlobalActiveVideoId();
                }
            }

            if (aiVideoUrl.value) {
                clearAiVideoRuntimeCache();
                void upsertAiWebinarDraft({
                    videoUrl: aiVideoUrl.value,
                    source: aiVideoProvider.value || 'heygen',
                    heygenVideoId: aiVideoId.value,
                    generationStatus: 'completed',
                });
            }
            if (!aiVideoUrl.value || payload.composing_long_form) {
                aiPollTimer = window.setTimeout(() => {
                    void pollVideoStatus();
                }, 8000);
            }
            return;
        }

        if (normalized === 'failed' || normalized === 'error') {
            aiVideoStatus.value = 'failed';
            aiVideoPhase.value = 'failed';
            aiVideoProgressPercent.value = 0;
            aiVideoMessage.value = 'Video rendering failed on provider.';
            clearAiVideoRuntimeCache();
            clearGlobalActiveVideoId();
            void upsertAiWebinarDraft({
                videoUrl: null,
                source: 'heygen_pending',
                heygenVideoId: aiVideoId.value,
                generationStatus: 'failed',
            });
            return;
        }

        aiVideoStatus.value = normalized === 'processing' ? 'processing' : 'pending';
        aiStatusReadFailureCount.value = 0;
        aiVideoMessage.value = 'Rendering in progress. Long scripts may take much longer. You can refresh and resume.';
        if (aiVideoStatus.value === 'pending') {
            aiVideoProgressPercent.value = Math.max(aiVideoProgressPercent.value, 12);
        } else {
            aiVideoProgressPercent.value = Math.max(aiVideoProgressPercent.value, 55);
        }

        aiPollTimer = window.setTimeout(() => {
            void pollVideoStatus();
        }, 8000);
    } catch {
        aiStatusReadFailureCount.value += 1;
        aiVideoStatus.value = 'processing';
        aiVideoMessage.value = aiStatusReadFailureCount.value >= 3
            ? 'Status check is delayed, but generation may still be running. Retrying automatically...'
            : 'Temporary status read issue. Retrying automatically...';
        aiPollTimer = window.setTimeout(() => {
            void pollVideoStatus();
        }, 10000);
    }
};

const generateVideo = async (): Promise<void> => {
    if (!aiCanGenerateVideo.value || aiLoadingVideo.value) {
        return;
    }

    if (aiVideoId.value && ['requesting', 'pending', 'processing'].includes(aiVideoStatus.value)) {
        showToast('A video is already rendering in this modal. Please wait for it to finish (or open a new tab) before starting another.', 'info');
        return;
    }

    if (aiPollTimer !== null) {
        window.clearTimeout(aiPollTimer);
        aiPollTimer = null;
    }

    aiLoadingVideo.value = true;
    aiVideoStatus.value = 'requesting';
    aiVideoPhase.value = 'queued';
    aiVideoProgressPercent.value = 6;
    aiVideoMessage.value = 'Submitting video generation request...';
    aiVideoUrl.value = null;
    aiVideoProvider.value = null;

    try {
        clearGlobalActiveVideoId();

        const draftPayload = await upsertAiWebinarDraft({
            videoUrl: null,
            source: 'heygen_pending',
            heygenVideoId: aiVideoId.value,
            generationStatus: 'requesting',
        });
        if (!draftPayload) {
            aiVideoStatus.value = 'failed';
            aiVideoMessage.value = 'Failed to create webinar draft before rendering.';
            return;
        }

        const response = await fetch('/admin/webinars/ai/video', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                title: aiBrief.title,
                script: aiScript.value,
                avatar_id: aiBrief.avatar_id,
                openai_voice: aiBrief.openai_voice,
                intro_duration_seconds: aiBrief.intro_duration_seconds,
                aspect_ratio: aiBrief.aspect_ratio,
                background_color: aiBrief.background_color,
                slide_style: aiBrief.slide_style,
            }),
        });

        if (!response.ok) {
            if (response.status === 409) {
                try {
                    const existing = await response.json() as { video_id?: string; message?: string };
                    const existingId = String(existing.video_id || '').trim();
                    if (existingId) {
                        aiVideoId.value = existingId;
                        saveGlobalActiveVideoId(existingId);
                        saveAiVideoRuntimeCache();
                        aiVideoStatus.value = 'pending';
                        aiVideoPhase.value = 'queued';
                        aiVideoProgressPercent.value = Math.max(aiVideoProgressPercent.value, 12);
                        aiVideoMessage.value = existing.message || 'A video is already rendering. Resuming status...';
                        void pollVideoStatus();
                        return;
                    }
                } catch {
                    // fall through to default error handling
                }
            }

            aiVideoStatus.value = 'failed';
            aiVideoMessage.value = await parseErrorMessage(response, 'Failed to start HeyGen generation.');
            clearGlobalActiveVideoId();
            return;
        }

        const payload = await response.json() as {
            video_id: string;
            intro_script?: string;
            remaining_script?: string;
            slide_plan?: SlidePlanItem[];
        };
        aiVideoId.value = payload.video_id;
        saveGlobalActiveVideoId(payload.video_id);
        saveAiVideoRuntimeCache();
        aiIntroScript.value = payload.intro_script ?? '';
        aiRemainingScript.value = payload.remaining_script ?? '';
        aiSlidePlan.value = Array.isArray(payload.slide_plan) ? payload.slide_plan : [];
        aiVideoStatus.value = 'pending';
        aiVideoPhase.value = 'queued';
        aiVideoProgressPercent.value = Math.max(aiVideoProgressPercent.value, 12);
        aiVideoMessage.value = 'Video request accepted. Polling status...';
        void upsertAiWebinarDraft({
            videoUrl: null,
            source: 'heygen_pending',
            heygenVideoId: aiVideoId.value,
            generationStatus: 'pending',
        });

        void pollVideoStatus();
    } catch {
        aiVideoStatus.value = 'failed';
        aiVideoMessage.value = 'Failed to start HeyGen generation.';
        clearGlobalActiveVideoId();
    } finally {
        aiLoadingVideo.value = false;
    }
};

const createWebinarFromAi = async (): Promise<void> => {
    if (aiCreatingWebinar.value) {
        return;
    }

    aiCreatingWebinar.value = true;

    try {
        const payload = await upsertAiWebinarDraft({
            videoUrl: aiVideoUrl.value,
            source: aiVideoProvider.value || (aiVideoUrl.value ? 'heygen' : 'heygen_pending'),
            heygenVideoId: aiVideoId.value,
            generationStatus: aiVideoUrl.value ? 'completed' : aiVideoStatus.value,
        });

        if (!payload) {
            showToast('Failed to create webinar draft from AI output.', 'info');
            return;
        }

        if (!aiVideoUrl.value) {
            showToast('Webinar draft saved. Video will be linked when rendering finishes.');
        } else {
            showToast('Webinar created from AI. Redirecting to editor...');
            window.sessionStorage.removeItem(AI_SLIDE_STYLE_CACHE_KEY);
            clearAiVideoRuntimeCache();
        }
        router.visit(payload.edit_url);
    } catch {
        showToast('Failed to create webinar draft from AI output.', 'info');
    } finally {
        aiCreatingWebinar.value = false;
    }
};

const upsertAiWebinarDraft = async ({
    videoUrl,
    source,
    heygenVideoId,
    generationStatus,
}: {
    videoUrl: string | null;
    source: string;
    heygenVideoId: string | null;
    generationStatus: string;
}): Promise<{ webinar_id: number; edit_url: string } | null> => {
    const response = await fetch('/admin/webinars/ai/create', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            webinar_id: aiWebinarId.value,
            title: aiBrief.title,
            host_name: aiBrief.host_name,
            description: aiBrief.topic,
            script: aiScript.value,
            video_url: videoUrl || undefined,
            video_duration_seconds: videoUrl ? estimatedDurationSeconds.value : undefined,
            source,
            avatar_id: aiBrief.avatar_id,
            voice_id: aiBrief.openai_voice || null,
            intro_script: aiIntroScript.value || undefined,
            remaining_script: aiRemainingScript.value || undefined,
            slide_plan: aiSlidePlan.value.length > 0 ? aiSlidePlan.value : undefined,
            intro_duration_seconds: aiBrief.intro_duration_seconds,
            webinar_type: aiBrief.webinar_type,
            audience: aiBrief.audience,
            goal: aiBrief.goal,
            heygen_video_id: heygenVideoId || undefined,
            video_generation_status: generationStatus,
        }),
    });

    if (!response.ok) {
        showToast(await parseErrorMessage(response, 'Failed to save webinar draft.'), 'info');
        return null;
    }

    const payload = await response.json() as { webinar_id: number; edit_url: string };
    aiWebinarId.value = payload.webinar_id;

    return payload;
};

watch(selectedAvatarOption, (value) => {
    if (value === '__custom__') {
        aiBrief.avatar_id = customAvatarId.value.trim();
        return;
    }

    aiBrief.avatar_id = value;
});

watch(customAvatarId, (value) => {
    if (selectedAvatarOption.value === '__custom__') {
        aiBrief.avatar_id = value.trim();
    }
});

watch(selectedOpenAiVoiceOption, (value) => {
    aiBrief.openai_voice = value.trim();
});

watch(() => aiBrief.slide_style, () => {
    saveSlideStyleCache();
}, { deep: true });

watch(selectedWebinarTypeOption, (value) => {
    if (value === '__custom__') {
        aiBrief.webinar_type = customWebinarType.value.trim();
        return;
    }

    aiBrief.webinar_type = value;
});

watch(customWebinarType, (value) => {
    if (selectedWebinarTypeOption.value === '__custom__') {
        aiBrief.webinar_type = value.trim();
    }
});

watch(selectedAudienceOption, (value) => {
    if (value === '__custom__') {
        aiBrief.audience = customAudience.value.trim();
        return;
    }

    aiBrief.audience = value;
});

watch(customAudience, (value) => {
    if (selectedAudienceOption.value === '__custom__') {
        aiBrief.audience = value.trim();
    }
});

watch(selectedToneOption, (value) => {
    if (value === '__custom__') {
        aiBrief.tone = customTone.value.trim();
        return;
    }

    aiBrief.tone = value;
});

watch(customTone, (value) => {
    if (selectedToneOption.value === '__custom__') {
        aiBrief.tone = value.trim();
    }
});

watch(() => aiBrief.duration_minutes, (value) => {
    const clamped = clampNumber(value, 20, 120, 45);
    if (value !== clamped) {
        aiBrief.duration_minutes = clamped;
    }
});

watch(() => aiBrief.intro_duration_seconds, (value) => {
    const clamped = clampNumber(value, 20, 60, 45);
    if (value !== clamped) {
        aiBrief.intro_duration_seconds = clamped;
    }
});

watch(() => aiBrief.slide_style.font_size, (value) => {
    const clamped = clampNumber(value, 24, 72, 44);
    if (value !== clamped) {
        aiBrief.slide_style.font_size = clamped;
    }
});

watch(() => aiBrief.slide_style.overlay_alpha, (value) => {
    const clamped = clampNumber(value, 0, 1, 0.22);
    if (value !== clamped) {
        aiBrief.slide_style.overlay_alpha = clamped;
    }
});

watch(aiModalOpen, (open) => {
    if (open) {
        return;
    }

    if (aiPollTimer !== null) {
        window.clearTimeout(aiPollTimer);
        aiPollTimer = null;
    }

    if (aiVideoOverlayTimer !== null) {
        window.clearInterval(aiVideoOverlayTimer);
        aiVideoOverlayTimer = null;
    }
});

const videoStatusClass = computed((): string => {
    if (aiVideoStatus.value === 'completed') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300';
    }

    if (aiVideoStatus.value === 'failed') {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300';
    }

    return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300';
});

const showVideoOverlay = computed((): boolean => {
    return ['requesting', 'pending', 'processing'].includes(aiVideoStatus.value);
});

const videoProgressLabel = computed((): string => {
    const phase = aiVideoPhase.value;
    if (phase === 'queued') return 'Queued';
    if (phase === 'rendering_intro') return 'Rendering HeyGen intro';
    if (phase === 'composing') {
        if (aiComposeStage.value === 'queued') return 'Compose queued';
        if (aiComposeStage.value === 'composing') return 'Preparing long-form compose';
        if (aiComposeStage.value === 'generating_images') return 'Generating slide images';
        if (aiComposeStage.value === 'rendering_slides') return 'Rendering slide video';
        if (aiComposeStage.value === 'merging') return 'Merging intro and slides';
        if (aiComposeStage.value === 'uploading') return 'Uploading final video';
        return 'Composing slides and merge';
    }
    if (phase === 'completed') return 'Completed';
    if (phase === 'failed') return 'Failed';
    return 'Processing';
});

const activeVideoOverlayMessage = computed((): string => {
    if (!showVideoOverlay.value) {
        return '';
    }

    return aiVideoOverlayMessages[aiVideoOverlayMessageIndex.value % aiVideoOverlayMessages.length];
});

watch(showVideoOverlay, (active) => {
    if (!active) {
        if (aiVideoOverlayTimer !== null) {
            window.clearInterval(aiVideoOverlayTimer);
            aiVideoOverlayTimer = null;
        }
        aiVideoOverlayMessageIndex.value = 0;
        return;
    }

    if (aiVideoOverlayTimer !== null) {
        window.clearInterval(aiVideoOverlayTimer);
    }

    aiVideoOverlayTimer = window.setInterval(() => {
        aiVideoOverlayMessageIndex.value = (aiVideoOverlayMessageIndex.value + 1) % aiVideoOverlayMessages.length;
    }, 5500);
});

const showToast = (message: string, type: 'success' | 'info' = 'success'): void => {
    toastMessage.value = message;
    toastType.value = type;
    window.setTimeout(() => {
        if (toastMessage.value === message) toastMessage.value = null;
    }, 3000);
};

const copyLink = async (link: string, label: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(link);
        showToast(`${label} copied.`);
    } catch {
        showToast(`Unable to copy ${label.toLowerCase()}.`, 'info');
    }
};

const deleteWebinar = (webinarId: number, title: string): void => {
    const ok = window.confirm(`Delete webinar "${title}" and all its data (attendees, chats, tracking)?`);
    if (!ok) return;
    router.delete(`/admin/webinars/${webinarId}`);
};

const playVoicePreview = async (voiceId: string): Promise<void> => {
    if (!voiceId || voicePreviewLoadingVoiceId.value) {
        return;
    }

    if (voicePreviewPlayingVoiceId.value === voiceId && voicePreviewAudio) {
        voicePreviewAudio.pause();
        voicePreviewAudio.currentTime = 0;
        voicePreviewPlayingVoiceId.value = null;
        return;
    }

    if (voicePreviewAudio) {
        voicePreviewAudio.pause();
        voicePreviewAudio.currentTime = 0;
        voicePreviewAudio = null;
    }

    try {
        voicePreviewLoadingVoiceId.value = voiceId;

        let objectUrl = voicePreviewAudioCache.get(voiceId) ?? null;
        if (!objectUrl) {
            const query = new URLSearchParams({ voice: voiceId });
            const response = await fetch(`/admin/webinars/ai/voice-preview?${query.toString()}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'audio/mpeg',
                },
            });

            if (!response.ok) {
                showToast('Failed to load voice preview.', 'info');
                return;
            }

            const audioBlob = await response.blob();
            objectUrl = URL.createObjectURL(audioBlob);
            voicePreviewAudioCache.set(voiceId, objectUrl);
        }

        voicePreviewAudio = new Audio(objectUrl);
        voicePreviewAudio.onended = () => {
            voicePreviewPlayingVoiceId.value = null;
        };
        voicePreviewPlayingVoiceId.value = voiceId;
        await voicePreviewAudio.play();
    } catch {
        showToast('Unable to play voice preview.', 'info');
        voicePreviewPlayingVoiceId.value = null;
    } finally {
        voicePreviewLoadingVoiceId.value = null;
    }
};

const toggleWebinarSelection = (webinarId: number, checked: boolean): void => {
    if (checked) {
        if (!selectedWebinarIds.value.includes(webinarId)) {
            selectedWebinarIds.value.push(webinarId);
        }
        return;
    }

    selectedWebinarIds.value = selectedWebinarIds.value.filter((id) => id !== webinarId);
};

const toggleSelectAllOnPage = (checked: boolean): void => {
    if (checked) {
        const currentSet = new Set(selectedWebinarIds.value);
        filteredWebinars.value.forEach((webinar) => currentSet.add(webinar.id));
        selectedWebinarIds.value = Array.from(currentSet);
        return;
    }

    const filteredIds = new Set(filteredWebinars.value.map((webinar) => webinar.id));
    selectedWebinarIds.value = selectedWebinarIds.value.filter((id) => !filteredIds.has(id));
};

const bulkDeleteWebinars = (): void => {
    if (selectedWebinarIds.value.length === 0) {
        showToast('Select at least one webinar to delete.', 'info');
        return;
    }

    const ok = window.confirm(
        `Delete ${selectedWebinarIds.value.length} selected webinar(s) and all related data?`,
    );
    if (!ok) return;

    router.post('/admin/webinars/delete-bulk', {
        webinar_ids: selectedWebinarIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedWebinarIds.value = [];
        },
    });
};

watch(
    () => props.webinars.data.map((webinar) => webinar.id),
    (currentIds) => {
        selectedWebinarIds.value = selectedWebinarIds.value.filter((id) => currentIds.includes(id));
    },
);

const videoSourceIcon = (source: string): string => {
    if (source === 'youtube') return 'solar:playback-speed-bold-duotone';
    if (source === 'vimeo') return 'solar:play-circle-bold-duotone';
    return 'solar:video-library-bold-duotone';
};
</script>

<template>
    <Head title="Webinars" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-5 p-4 pb-10 md:p-6">

            <!-- Toast -->
            <div
                v-if="toastMessage"
                class="rounded-lg border px-4 py-3 text-sm"
                :class="toastType === 'success'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                    : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300'"
            >
                {{ toastMessage }}
            </div>

            <!-- Page header -->
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Management</p>
                    <h1 class="mt-0.5 text-2xl font-bold tracking-tight text-foreground">Webinars</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Manage your webinar funnels, registration flows, and playback settings.
                    </p>
                </div>
                <div class="mt-3 flex items-center gap-2 sm:mt-0">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-9 gap-1.5 px-4 font-semibold"
                        :disabled="aiHasActiveVideoElsewhere"
                        :title="aiHasActiveVideoElsewhere ? aiActiveVideoHoverMessage : ''"
                        @click="openAiModal"
                    >
                        <Icon icon="solar:stars-bold-duotone" class="size-4" />
                        {{ createWithAiButtonText }}
                    </Button>
                    <Button as-child size="sm" class="h-9 gap-1.5 px-4 font-semibold shadow-sm">
                        <Link href="/admin/webinars/create">
                            <Icon icon="solar:add-circle-bold" class="size-4" />
                            New Webinar
                </Link>
                    </Button>
                </div>
            </div>

            <!-- Webinars table card -->
            <Card class="border border-border/60 shadow-sm">
                <CardHeader class="border-b border-border/50 pb-3 pt-4 px-5 flex-row items-center justify-between space-y-0">
                    <div>
                        <CardTitle class="text-sm font-semibold">All Webinars</CardTitle>
                        <CardDescription class="text-xs mt-0.5">
                            {{ filteredWebinars.length }} shown / {{ webinars.data.length }} total
                        </CardDescription>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="destructive"
                            size="sm"
                            class="h-7 gap-1.5 px-2.5 text-xs"
                            :disabled="selectedWebinarIds.length === 0"
                            @click="bulkDeleteWebinars"
                        >
                            <Icon icon="solar:trash-bin-2-linear" class="size-3.5" />
                            Delete Selected ({{ selectedWebinarIds.length }})
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-7 gap-1.5 px-2.5 text-xs border-border/60"
                            @click="filtersOpen = !filtersOpen"
                        >
                            <Icon icon="solar:filter-linear" class="size-3" />
                            Filter
                            <span
                                v-if="activeFilterCount > 0"
                                class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary/15 px-1 text-[10px] font-semibold text-primary"
                            >
                                {{ activeFilterCount }}
                            </span>
                        </Button>
                    </div>
                </CardHeader>

                <CardContent class="px-0 pb-0">
                    <div v-if="filtersOpen" class="border-b border-border/40 bg-muted/10 px-5 py-3">
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <input
                                v-model="filterSearch"
                                type="text"
                                placeholder="Search title, host, uuid..."
                                class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            />
                            <select v-model="filterSource" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                <option value="all">All Sources</option>
                                <option value="youtube">YouTube</option>
                                <option value="vimeo">Vimeo</option>
                                <option value="direct">Direct</option>
                            </select>
                            <select v-model="filterStatus" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                <option value="all">All Statuses</option>
                                <option value="published">Published</option>
                                <option value="draft">Draft</option>
                                <option value="ended">Ended</option>
                            </select>
                            <select v-model="filterScheduleMode" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                <option value="all">All Modes</option>
                                <option value="auto">Auto</option>
                                <option value="scheduled">Scheduled</option>
                            </select>
                        </div>
                        <div class="mt-2 flex justify-end">
                            <Button variant="ghost" size="sm" class="h-7 text-xs" @click="clearFilters">
                                Clear filters
                            </Button>
                        </div>
                    </div>
                    <!-- Empty state -->
                    <div
                        v-if="webinars.data.length === 0"
                        class="flex flex-col items-center justify-center px-6 py-16 text-center"
                    >
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-500 dark:bg-indigo-950/60 dark:text-indigo-400 mb-4">
                            <Icon icon="solar:monitor-camera-bold-duotone" class="size-7" />
                        </div>
                        <h3 class="text-base font-semibold text-foreground">No webinars yet</h3>
                        <p class="mt-1.5 max-w-sm text-sm text-muted-foreground">
                            Create your first webinar to start collecting registrations and tracking views.
                        </p>
                        <Button as-child size="sm" class="mt-5 gap-1.5 font-semibold shadow-sm">
                            <Link href="/admin/webinars/create">
                                <Icon icon="solar:add-circle-bold" class="size-4" />
                                Create your first webinar
                            </Link>
                        </Button>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <div
                            v-if="filteredWebinars.length === 0"
                            class="flex flex-col items-center justify-center px-6 py-12 text-center"
                        >
                            <h3 class="text-sm font-semibold text-foreground">No webinars match these filters</h3>
                            <p class="mt-1 text-xs text-muted-foreground">Try changing or clearing your filters.</p>
                            <Button variant="outline" size="sm" class="mt-3 h-7 text-xs" @click="clearFilters">
                                Reset filters
                            </Button>
                        </div>
                <table v-else class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border/50">
                                    <th class="px-3 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        <input
                                            type="checkbox"
                                            :checked="allSelectedOnPage"
                                            :aria-label="allSelectedOnPage ? 'Unselect all webinars on this page' : 'Select all webinars on this page'"
                                            class="h-4 w-4 rounded border-border"
                                            @change="toggleSelectAllOnPage(($event.target as HTMLInputElement).checked)"
                                        />
                                    </th>
                                    <th class="px-5 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Webinar
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Source
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Status
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Registrants
                                    </th>
                                    <th class="px-4 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Views
                                    </th>
                                    <th class="px-5 pb-2.5 pt-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Actions
                                    </th>
                        </tr>
                    </thead>
                    <tbody>
                                <tr
                                    v-for="webinar in filteredWebinars"
                                    :key="webinar.id"
                                    class="border-b border-border/30 last:border-0 hover:bg-muted/30 transition-colors"
                                >
                                    <td class="px-3 py-3.5 align-middle">
                                        <input
                                            type="checkbox"
                                            :checked="selectedWebinarIds.includes(webinar.id)"
                                            :aria-label="`Select webinar ${webinar.title}`"
                                            class="h-4 w-4 rounded border-border"
                                            @change="toggleWebinarSelection(webinar.id, ($event.target as HTMLInputElement).checked)"
                                        />
                                    </td>
                                    <!-- Title + meta -->
                                    <td class="px-5 py-3.5 align-middle">
                                        <div class="flex items-start gap-3">
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400 mt-0.5">
                                                <Icon :icon="videoSourceIcon(webinar.video_source)" class="size-4" />
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-semibold text-foreground leading-snug">{{ webinar.title }}</p>
                                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    <span
                                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                        :class="webinar.schedule_mode === 'auto'
                                                            ? 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-400'
                                                            : 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-400'"
                                                    >
                                                        <Icon
                                                            :icon="webinar.schedule_mode === 'auto' ? 'solar:infinity-bold' : 'solar:calendar-bold'"
                                                            class="mr-0.5 size-2.5"
                                                        />
                                        {{ webinar.schedule_mode === 'auto' ? 'Auto' : 'Scheduled' }}
                                    </span>
                                                    <span
                                                        v-if="webinar.schedule_mode === 'scheduled' && webinar.scheduled_at_label"
                                                        class="text-[11px] text-muted-foreground"
                                                    >
                                                        {{ webinar.scheduled_at_label }}
                                                    </span>
                                                    <span class="text-[10px] text-muted-foreground/60 font-mono hidden lg:inline">
                                                        {{ webinar.uuid }}
                                    </span>
                                                </div>
                                                <p class="mt-0.5 text-xs text-muted-foreground">
                                                    <Icon icon="solar:user-linear" class="inline size-3 mr-0.5" />
                                                    {{ webinar.host_name }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Source -->
                                    <td class="px-4 py-3.5 align-middle">
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground capitalize">
                                            <Icon :icon="videoSourceIcon(webinar.video_source)" class="size-3.5 text-muted-foreground/60" />
                                            {{ webinar.video_source }}
                                        </span>
                            </td>

                                    <!-- Status -->
                                    <td class="px-4 py-3.5 align-middle">
                                <span
                                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="
                                        webinar.has_ended
                                                    ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-400'
                                            : webinar.is_published
                                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400'
                                                        : 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400'
                                    "
                                >
                                            <span
                                                class="h-1 w-1 rounded-full"
                                                :class="webinar.has_ended ? 'bg-rose-500' : webinar.is_published ? 'bg-emerald-500' : 'bg-amber-500'"
                                            />
                                    {{ webinar.has_ended ? 'Ended' : webinar.is_published ? 'Published' : 'Draft' }}
                                </span>
                            </td>

                                    <!-- Registrants -->
                                    <td class="px-4 py-3.5 align-middle text-right">
                                        <div class="flex flex-col items-end">
                                            <span class="font-semibold tabular-nums text-foreground">{{ webinar.registrants_count.toLocaleString() }}</span>
                                        </div>
                                    </td>

                                    <!-- Views -->
                                    <td class="px-4 py-3.5 align-middle text-right">
                                        <span class="font-semibold tabular-nums text-foreground">{{ webinar.views_count.toLocaleString() }}</span>
                                    </td>

                                    <!-- Actions dropdown -->
                                    <td class="px-5 py-3.5 align-middle text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <Button as-child variant="ghost" size="sm" class="h-7 px-2.5 text-xs font-medium text-primary hover:text-primary/80">
                                                <Link :href="`/admin/webinars/${webinar.id}/edit`">
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
                                                    <DropdownMenuItem
                                                        class="cursor-pointer gap-2 text-xs"
                                        @click="copyLink(webinar.registration_link, 'Registration link')"
                                    >
                                                        <Icon icon="solar:copy-linear" class="size-3.5 text-muted-foreground" />
                                        Copy Registration Link
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        class="cursor-pointer gap-2 text-xs"
                                        @click="copyLink(webinar.room_link, 'Room link')"
                                    >
                                                        <Icon icon="solar:link-linear" class="size-3.5 text-muted-foreground" />
                                        Copy Join Link
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem as-child class="gap-2 text-xs">
                                                        <Link :href="webinar.chat_link" class="cursor-pointer">
                                                            <Icon icon="solar:chat-round-dots-linear" class="size-3.5 text-muted-foreground" />
                                                            Moderate Chat
                                    </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem as-child class="gap-2 text-xs">
                                    <Link
                                        :href="webinar.notify_link"
                                        method="post"
                                        as="button"
                                                            class="w-full cursor-pointer"
                                    >
                                                            <Icon icon="solar:bell-bing-linear" class="size-3.5 text-muted-foreground" />
                                                            Notify All Registrants
                                    </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        class="cursor-pointer gap-2 text-xs text-destructive focus:text-destructive focus:bg-destructive/10"
                                                        @click="deleteWebinar(webinar.id, webinar.title)"
                                                    >
                                                        <Icon icon="solar:trash-bin-2-linear" class="size-3.5" />
                                                        Delete Webinar
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
                </CardContent>
            </Card>

        </div>

        <Dialog :open="aiModalOpen" @update:open="onAiModalOpenChange">
            <DialogContent
                class="flex max-h-[90vh] flex-col overflow-hidden sm:max-w-5xl"
                :show-close-button="false"
                @interact-outside.prevent
                @escape-key-down.prevent
            >
                <div
                    v-if="showVideoOverlay"
                    class="absolute inset-0 z-20 flex items-center justify-center bg-background/65 backdrop-blur-sm"
                >
                    <div class="mx-4 w-full max-w-md rounded-xl border border-border bg-background p-5 text-center shadow-lg">
                        <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Icon icon="svg-spinners:3-dots-fade" class="size-8" />
                        </div>
                        <p class="text-sm font-semibold text-foreground">Rendering in progress</p>
                        <p class="mt-1 text-sm text-muted-foreground">{{ activeVideoOverlayMessage }}</p>
                        <div class="mt-3">
                            <div class="mb-1 flex items-center justify-between text-[11px] text-muted-foreground">
                                <span>{{ videoProgressLabel }}</span>
                                <span>{{ aiVideoProgressPercent }}%</span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-primary transition-all duration-500"
                                    :style="{ width: `${aiVideoProgressPercent}%` }"
                                />
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-muted-foreground">Long scripts can take much longer to generate. </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-md border border-border text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    @click="requestCloseAiModal"
                >
                    <Icon icon="solar:close-circle-linear" class="size-4" />
                    <span class="sr-only">Close</span>
                </button>
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Icon icon="solar:stars-bold-duotone" class="size-5 text-primary" />
                        Create Webinar with AI
                    </DialogTitle>
                    <DialogDescription>
                        Brief the webinar, generate a long script, render with HeyGen, then create a draft webinar automatically.
                    </DialogDescription>
                </DialogHeader>

                <div class="min-h-0 flex-1 overflow-y-auto pr-1">
                    <div class="flex items-center gap-2 text-xs font-semibold">
                        <span class="inline-flex h-6 items-center rounded-full px-2"
                            :class="aiStep === 'brief' ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground'"
                        >1. Brief</span>
                        <span class="h-px flex-1 bg-border" />
                        <span class="inline-flex h-6 items-center rounded-full px-2"
                            :class="aiStep === 'script' ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground'"
                        >2. Script</span>
                        <span class="h-px flex-1 bg-border" />
                        <span class="inline-flex h-6 items-center rounded-full px-2"
                            :class="aiStep === 'video' ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground'"
                        >3. Video and Create</span>
                    </div>

                    <div v-if="aiStep === 'brief'" class="grid gap-4 py-2 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Webinar Title</label>
                        <input v-model="aiBrief.title" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="High-Ticket Offer Masterclass" />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Host Name</label>
                        <input v-model="aiBrief.host_name" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="John Smith" />
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Topic</label>
                        <input v-model="aiBrief.topic" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="How to close premium coaching clients" />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Webinar Type</label>
                        <select v-model="selectedWebinarTypeOption" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                            <option v-for="option in webinarTypeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <input
                            v-if="selectedWebinarTypeOption === '__custom__'"
                            v-model="customWebinarType"
                            type="text"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            placeholder="Type your webinar type"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Audience</label>
                        <select v-model="selectedAudienceOption" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                            <option v-for="option in audienceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <input
                            v-if="selectedAudienceOption === '__custom__'"
                            v-model="customAudience"
                            type="text"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            placeholder="Type your audience"
                        />
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Goal</label>
                        <input v-model="aiBrief.goal" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="Sell strategy calls" />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Tone</label>
                        <select v-model="selectedToneOption" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                            <option v-for="option in toneOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <input
                            v-if="selectedToneOption === '__custom__'"
                            v-model="customTone"
                            type="text"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            placeholder="Type your tone"
                        />
                    </div>
                    </div>

                    <div v-else-if="aiStep === 'script'" class="space-y-3 py-2">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span>Review and edit the generated script before creating video.</span>
                        <span v-if="aiScriptModel">Model: {{ aiScriptModel }}</span>
                    </div>
                    <textarea
                        v-model="aiScript"
                        rows="16"
                        class="w-full rounded-md border bg-background px-3 py-2 text-sm leading-6"
                        placeholder="Generated script will appear here"
                    />
                    </div>

                    <div v-else class="space-y-3 py-2">
                    <div v-if="aiLoadingHeygenOptions" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-700">
                        Loading HeyGen avatars and OpenAI voices...
                    </div>
                    <div v-else-if="aiHeygenOptionsError" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ aiHeygenOptionsError }}</span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-7 border-amber-300 bg-amber-50 px-2 text-[11px] text-amber-700 hover:bg-amber-100"
                                :disabled="aiLoadingHeygenOptions"
                                @click="loadAiOptions"
                            >
                                <Icon v-if="aiLoadingHeygenOptions" icon="svg-spinners:3-dots-fade" class="mr-1 size-3.5" />
                                {{ aiLoadingHeygenOptions ? 'Retrying...' : 'Retry loading options' }}
                            </Button>
                        </div>
                    </div>
                    <div class="grid gap-3 rounded-lg border border-border/70 bg-muted/15 p-3 lg:grid-cols-2">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">HeyGen Avatar</label>
                                <div class="flex items-center gap-2 text-xs">
                                    <Button type="button" variant="outline" size="sm" class="h-7 px-2 text-[11px]" :disabled="aiAvatarPage <= 1" @click="aiAvatarPage--">Prev</Button>
                                    <span class="text-muted-foreground">Page {{ aiAvatarPage }} / {{ totalAvatarPages }}</span>
                                    <Button type="button" variant="outline" size="sm" class="h-7 px-2 text-[11px]" :disabled="aiAvatarPage >= totalAvatarPages" @click="aiAvatarPage++">Next</Button>
                                </div>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                <button
                                    v-for="avatar in paginatedAvatarOptions"
                                    :key="avatar.id"
                                    type="button"
                                    class="rounded-lg border bg-background p-2 text-left transition hover:border-primary/50"
                                    :class="selectedAvatarOption === avatar.id ? 'border-primary ring-1 ring-primary/40' : 'border-border/70'"
                                    @click="selectedAvatarOption = avatar.id"
                                >
                                    <img
                                        v-if="avatar.preview_url"
                                        :src="avatar.preview_url"
                                        :alt="avatar.name"
                                        class="mb-2 h-20 w-full rounded object-cover"
                                    />
                                    <div v-else class="mb-2 flex h-20 w-full items-center justify-center rounded bg-muted text-xs text-muted-foreground">No preview</div>
                                    <p class="line-clamp-2 text-xs font-semibold">{{ avatar.name }}</p>
                                    <span
                                        v-if="avatar.gender"
                                        class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                        :class="avatar.gender === 'female'
                                            ? 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-300'
                                            : 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'"
                                    >
                                        {{ avatar.gender }}
                                    </span>
                                </button>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button type="button" variant="outline" size="sm" class="h-8 text-xs" @click="selectedAvatarOption = '__custom__'">Use Custom Avatar ID</Button>
                                <input
                                    v-if="selectedAvatarOption === '__custom__'"
                                    v-model="customAvatarId"
                                    type="text"
                                    class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                    placeholder="avatar_xxx"
                                />
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">OpenAI Voice</label>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <button
                                    v-for="voice in openAiVoiceOptions"
                                    :key="voice.id"
                                    type="button"
                                    class="rounded-lg border bg-background p-2 text-left transition hover:border-primary/50"
                                    :class="selectedOpenAiVoiceOption === voice.id ? 'border-primary ring-1 ring-primary/40' : 'border-border/70'"
                                    @click="selectedOpenAiVoiceOption = voice.id"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-xs font-semibold text-foreground">{{ voice.label }}</p>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="h-7 px-2 text-[11px]"
                                            :disabled="voicePreviewLoadingVoiceId !== null && voicePreviewLoadingVoiceId !== voice.id"
                                            @click.stop="void playVoicePreview(voice.id)"
                                        >
                                            <Icon
                                                v-if="voicePreviewLoadingVoiceId === voice.id"
                                                icon="svg-spinners:3-dots-fade"
                                                class="mr-1 size-3.5"
                                            />
                                            <Icon
                                                v-else
                                                :icon="voicePreviewPlayingVoiceId === voice.id ? 'solar:stop-circle-linear' : 'solar:play-circle-linear'"
                                                class="mr-1 size-3.5"
                                            />
                                            {{ voicePreviewPlayingVoiceId === voice.id ? 'Stop' : 'Play' }}
                                        </Button>
                                    </div>
                                    <div class="mt-2 flex items-center gap-2 text-[10px]">
                                        <span
                                            class="inline-flex rounded-full px-2 py-0.5 font-semibold uppercase tracking-wide"
                                            :class="voice.gender === 'female'
                                                ? 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-300'
                                                : voice.gender === 'male'
                                                    ? 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'
                                                    : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-900/30 dark:text-zinc-300'"
                                        >
                                            {{ voice.gender || 'neutral' }}
                                        </span>
                                        <span class="text-muted-foreground">{{ voice.style || 'narration' }}</span>
                                    </div>
                                </button>
                            </div>
                            <p class="text-[11px] text-muted-foreground">
                                Choose a voice profile and click Play to preview before generating your video narration.
                            </p>
                        </div>
                        <div class="grid gap-3 lg:col-span-2 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Avatar Intro (seconds)</label>
                                <input
                                    v-model.number="aiBrief.intro_duration_seconds"
                                    type="number"
                                    min="20"
                                    max="60"
                                    class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                    @input="clampAiBriefNumericFields"
                                />
                                <p class="text-[11px] text-muted-foreground">
                                    First 20-60 seconds uses avatar lip-sync. Remaining script becomes slide plan.
                                </p>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Aspect Ratio</label>
                                <select v-model="aiBrief.aspect_ratio" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option value="16:9">16:9 (Webinar)</option>
                                    <option value="1:1">1:1</option>
                                    <option value="9:16">9:16</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Background Color</label>
                                <input v-model="aiBrief.background_color" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="#F8FAFC" />
                            </div>
                        </div>

                        <div class="lg:col-span-2 rounded-lg border border-border/70 bg-background p-3">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                @click="advancedSlideSettingsOpen = !advancedSlideSettingsOpen"
                            >
                                <span>Advanced Slide Settings</span>
                                <Icon :icon="advancedSlideSettingsOpen ? 'solar:alt-arrow-up-bold' : 'solar:alt-arrow-down-bold'" class="size-4" />
                            </button>
                            <div v-if="advancedSlideSettingsOpen" class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Font Size</label>
                                    <input
                                        v-model.number="aiBrief.slide_style.font_size"
                                        type="number"
                                        min="24"
                                        max="72"
                                        class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                        @input="clampAiBriefNumericFields"
                                    />
                                    <p class="text-[11px] text-muted-foreground">Allowed range: 24-72. Values outside this range auto-adjust.</p>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Text Color</label>
                                    <input v-model="aiBrief.slide_style.text_color" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="#FFFFFF" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Accent Color</label>
                                    <input v-model="aiBrief.slide_style.accent_color" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="#6366F1" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Overlay Color</label>
                                    <input v-model="aiBrief.slide_style.overlay_color" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="#0B1020" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Overlay Opacity (0-1)</label>
                                    <input
                                        v-model.number="aiBrief.slide_style.overlay_alpha"
                                        type="number"
                                        step="0.05"
                                        min="0"
                                        max="1"
                                        class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                        @input="clampAiBriefNumericFields"
                                    />
                                    <p class="text-[11px] text-muted-foreground">Use decimals from 0.00 to 1.00. Values auto-clamp if exceeded.</p>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Slide Background Color</label>
                                    <input v-model="aiBrief.slide_style.background_color" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="#0F172A" />
                                </div>
                                <div class="space-y-1.5 sm:col-span-2">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Background Image URL (optional)</label>
                                    <input v-model="aiBrief.slide_style.background_image_url" type="url" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="https://..." />
                                </div>
                                <div class="space-y-1.5 sm:col-span-2">
                                    <label class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        <input v-model="aiBrief.slide_style.generate_images" type="checkbox" class="rounded border-border" />
                                        Generate slide images with GPT Image
                                    </label>
                                </div>
                                <div v-if="aiBrief.slide_style.generate_images" class="space-y-1.5 sm:col-span-2">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Image Style</label>
                                    <select v-model="aiBrief.slide_style.image_style" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                        <option value="realistic">Realistic</option>
                                        <option value="illustration">Illustration</option>
                                        <option value="minimal">Minimal</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-2 rounded-lg border bg-muted/20 p-3 text-xs sm:grid-cols-4">
                        <div>
                            <p class="text-muted-foreground">Title</p>
                            <p class="font-semibold text-foreground">{{ aiBrief.title || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-muted-foreground">Avatar</p>
                            <p class="font-semibold text-foreground">{{ aiBrief.avatar_id || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-muted-foreground">Estimated Duration</p>
                            <p class="font-semibold text-foreground">{{ estimatedDurationSeconds ? Math.round(estimatedDurationSeconds / 60) + ' min' : '-' }}</p>
                        </div>
                        <div>
                            <p class="text-muted-foreground">Slides Planned</p>
                            <p class="font-semibold text-foreground">{{ aiSlidePlan.length || 0 }}</p>
                        </div>
                    </div>

                    <div v-if="aiSlidePlan.length > 0" class="space-y-2 rounded-lg border border-border/70 bg-background p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Auto Slide Plan Preview</p>
                        <div class="max-h-44 space-y-2 overflow-auto pr-1">
                            <div v-for="(slide, index) in aiSlidePlan.slice(0, 5)" :key="`${slide.title}-${index}`" class="rounded-md border border-border/60 bg-muted/20 p-2">
                                <p class="text-xs font-semibold text-foreground">{{ index + 1 }}. {{ slide.title }}</p>
                                <p class="mt-1 text-[11px] text-muted-foreground">{{ slide.bullets.slice(0, 2).join(' | ') }}</p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="aiVideoStatus !== 'idle'"
                        class="rounded-md border px-3 py-2 text-sm"
                        :class="videoStatusClass"
                    >
                        <p class="font-semibold capitalize">Status: {{ aiVideoStatus }}</p>
                        <p class="mt-1 text-xs font-medium">Stage: {{ videoProgressLabel }} ({{ aiVideoProgressPercent }}%)</p>
                        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-background/70">
                            <div
                                class="h-full rounded-full bg-current transition-all duration-500"
                                :style="{ width: `${aiVideoProgressPercent}%` }"
                            />
                        </div>
                        <p class="mt-1">{{ aiVideoMessage }}</p>
                    </div>

                    <div v-if="aiVideoUrl" class="space-y-1 rounded-md border border-border bg-background p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Generated Video URL</p>
                        <a :href="aiVideoUrl" target="_blank" rel="noopener" class="break-all text-sm font-medium text-primary underline">
                            {{ aiVideoUrl }}
                        </a>
                        <!-- <p class="text-xs text-muted-foreground">Provider: {{ aiVideoProvider || 'heygen' }}</p> -->
                    </div>
                    </div>
                </div>

                <DialogFooter class="flex flex-wrap justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="ghost" @click="requestCloseAiModal">Cancel</Button>
                        <Button
                            v-if="aiStep !== 'brief'"
                            type="button"
                            variant="outline"
                            @click="aiStep = aiStep === 'video' ? 'script' : 'brief'"
                        >
                            Back
                        </Button>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            v-if="aiStep === 'brief'"
                            type="button"
                            :disabled="!aiCanGenerateScript || aiLoadingScript"
                            @click="generateScript"
                        >
                            <Icon v-if="aiLoadingScript" icon="svg-spinners:3-dots-fade" class="mr-1 size-4" />
                            Generate Script
                        </Button>
                        <Button
                            v-if="aiStep === 'brief'"
                            type="button"
                            variant="outline"
                            @click="aiStep = 'script'"
                        >
                            Skip to Script (Temporary)
                        </Button>

                        <template v-else-if="aiStep === 'script'">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="aiLoadingScript"
                                @click="generateScript"
                            >
                                <Icon v-if="aiLoadingScript" icon="svg-spinners:3-dots-fade" class="mr-1 size-4" />
                                Regenerate
                            </Button>
                            <Button
                                type="button"
                                :disabled="aiScript.trim().length < 300"
                                @click="aiStep = 'video'"
                            >
                                Continue to Video
                            </Button>
                        </template>

                        <template v-else>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="!aiCanGenerateVideo || aiLoadingVideo"
                                @click="generateVideo"
                            >
                                <Icon v-if="aiLoadingVideo" icon="svg-spinners:3-dots-fade" class="mr-1 size-4" />
                                Generate Video
                            </Button>
                            <Button
                                type="button"
                                :disabled="aiCreatingWebinar || (aiWebinarId === null && !aiCanGenerateVideo)"
                                @click="createWebinarFromAi"
                            >
                                <Icon v-if="aiCreatingWebinar" icon="svg-spinners:3-dots-fade" class="mr-1 size-4" />
                                {{ aiVideoUrl ? 'Create Webinar' : (aiWebinarId ? 'Open Webinar Draft' : 'Save Webinar Draft') }}
                            </Button>
                        </template>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
