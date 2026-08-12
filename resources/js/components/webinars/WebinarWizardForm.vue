<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import RichTextEditor from '@/components/webinars/RichTextEditor.vue';

type WebinarFormData = {
    title_prefix: string;
    title: string;
    schedule_mode: 'auto' | 'scheduled';
    host_name: string;
    description: string;
    scheduled_at: string;
    scheduled_timezone: string;
    video_source: 'youtube' | 'vimeo' | 'direct';
    video_url: string;
    video_duration_seconds: number | null;
    thumbnail_path: string;
    uuid: string | null;
    min_viewers: number;
    max_viewers: number;
    is_published: boolean;
    email_settings: {
        send_confirmation: boolean;
        send_reminder: boolean;
        send_follow_up: boolean;
    };
    playback_settings: {
        show_fake_viewers: boolean;
        redirect_enabled: boolean;
        redirect_url: string;
        exit_popup_enabled: boolean;
        exit_popup_heading: string;
        exit_popup_body: string;
        exit_popup_cta_text: string;
        exit_popup_cta_url: string;
    };
    ai_settings: {
        enabled: boolean;
        auto_reply_enabled: boolean;
        assistant_name: string;
    };
    registration_settings: {
        buttons: Array<{
            label: string;
            enabled: boolean;
            is_primary: boolean;
            urgency_mode: 'none' | 'minutes' | 'live';
            urgency_minutes: number | null;
            position?: number;
        }>;
    };
    offers: Array<{
        id?: number;
        title: string;
        description: string;
        trigger_second: number;
        button_text: string;
        button_url: string;
        display_mode: 'chat' | 'popup' | 'pinned';
    }>;
};

const props = defineProps<{
    mode: 'create' | 'edit';
    actionUrl: string;
    method: 'post' | 'put';
    initialValues: WebinarFormData;
    attendees: {
        subscribed_total: number;
        subscribed: Array<{ id: number; name: string; email: string; registered_at?: string | null; unsubscribe_url?: string }>;
        unsubscribed_total: number;
        unsubscribed: Array<{ id: number; name: string; email: string; unsubscribed_at?: string | null; delete_url?: string }>;
    };
    attendeeImportUrl: string | null;
    attendeeActionUrls: {
        bulk_unsubscribe_url: string;
        bulk_delete_url: string;
        apollo_preview_url: string;
        apollo_fetch_url: string;
    } | null;
    apolloMaxFetch: number;
    aiSourceUrls: {
        index: string | null;
        url: string | null;
        transcript: string | null;
        video_transcript_generate: string | null;
        file: string | null;
        bulk_delete: string | null;
    };
    aiSources: Array<{
        id: number;
        type: string;
        title: string | null;
        source_url: string | null;
        status: string;
        error_message: string | null;
        processed_at: string | null;
        chunk_count: number;
        chunks_url: string;
        delete_url: string;
    }>;
    timezoneOptions: string[];
}>();

const steps = [
    'Basics',
    'Video',
    'Registration',
    'Attendees',
    'Chat and Automation',
    'Offers',
    'AI Assistant',
    'Reminder and Notification',
    'Publish and Tracking',
];

const activeStep = ref(0);

const form = useForm<WebinarFormData>({
    ...props.initialValues,
});

const submitLabel = computed(() =>
    props.mode === 'create' ? 'Create Webinar' : 'Save Changes',
);

const durationHours = ref(Math.floor((props.initialValues.video_duration_seconds ?? 0) / 3600));
const durationMinutes = ref(Math.floor(((props.initialValues.video_duration_seconds ?? 0) % 3600) / 60));
const durationSecondsField = ref((props.initialValues.video_duration_seconds ?? 0) % 60);

const syncDurationToForm = (): void => {
    const h = Math.max(0, durationHours.value || 0);
    const m = Math.max(0, durationMinutes.value || 0);
    const s = Math.max(0, durationSecondsField.value || 0);
    const total = h * 3600 + m * 60 + s;
    form.video_duration_seconds = total > 0 ? total : null;
};

const publicRegisterLink = computed(() =>
    form.uuid ? `/register/${form.uuid}` : '/register/{webinar-uuid}',
);

const publicRoomExample = computed(() =>
    form.uuid ? `/webinar/live/${form.uuid}` : '/webinar/live/{webinar-uuid}',
);

const toastMessage = ref<string | null>(null);
const confirmToastMessage = ref<string | null>(null);
const confirmAction = ref<null | (() => void)>(null);
const confirmSection = ref<'ai' | 'attendees' | null>(null);

const markRequired = (label: string): string => `${label} *`;
const EXIT_POPUP_BODY_MAX_CHARS = 200;

const getPlainTextFromHtml = (value: string): string =>
    value
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

const exitPopupBodyTextCount = computed(() =>
    getPlainTextFromHtml(form.playback_settings.exit_popup_body || '').length,
);

const requiredChecks: Array<{ key: keyof WebinarFormData; label: string; step: number }> = [
    { key: 'title', label: 'Webinar Title', step: 0 },
    { key: 'host_name', label: 'Host Name', step: 0 },
    { key: 'scheduled_at', label: 'Webinar Date and Time', step: 0 },
    { key: 'scheduled_timezone', label: 'Time Zone', step: 0 },
    { key: 'video_source', label: 'Video Source', step: 1 },
    { key: 'video_url', label: 'Video URL', step: 1 },
    { key: 'min_viewers', label: 'Min Viewers', step: 8 },
    { key: 'max_viewers', label: 'Max Viewers', step: 8 },
];

const isRequiredMissing = (key: keyof WebinarFormData): boolean => {
    if (form.schedule_mode === 'auto' && (key === 'scheduled_at' || key === 'scheduled_timezone')) {
        return false;
    }

    const value = form[key] as unknown;

    if (typeof value === 'string') {
        return value.trim().length === 0;
    }

    if (typeof value === 'number') {
        return Number.isNaN(value);
    }

    return value === null || value === undefined;
};

const attendeePanel = ref<'subscribed' | 'unsubscribed'>('subscribed');
const selectedSubscribedIds = ref<number[]>([]);
const selectedUnsubscribedIds = ref<number[]>([]);

const attendeeCsvForm = useForm<{
    file: File | null;
}>({
    file: null,
});

const bulkUnsubscribeForm = useForm<{ attendee_ids: number[] }>({
    attendee_ids: [],
});

const bulkDeleteForm = useForm<{ attendee_ids: number[] }>({
    attendee_ids: [],
});

const apolloModalOpen = ref(false);
const apolloJobTitleOptions = [
    { label: 'Founder / CEO', value: 'Founder' },
    { label: 'Marketing Manager', value: 'Marketing Manager' },
    { label: 'Agency Owner', value: 'Agency Owner' },
    { label: 'Sales Director', value: 'Sales Director' },
    { label: 'Custom...', value: '__custom__' },
];
const apolloIndustryOptions = [
    { label: 'Marketing and Advertising', value: 'Marketing and Advertising' },
    { label: 'Real Estate', value: 'Real Estate' },
    { label: 'E-Learning', value: 'E-Learning' },
    { label: 'Health and Wellness', value: 'Health and Wellness' },
    { label: 'Custom...', value: '__custom__' },
];
const apolloLocationOptions = [
    { label: 'United States', value: 'United States' },
    { label: 'United Kingdom', value: 'United Kingdom' },
    { label: 'Canada', value: 'Canada' },
    { label: 'Australia', value: 'Australia' },
    { label: 'Custom...', value: '__custom__' },
];
const apolloCompanySizeOptions = [
    { label: '1-10', value: '1,10' },
    { label: '11-50', value: '11,50' },
    { label: '51-200', value: '51,200' },
    { label: '201-500', value: '201,500' },
    { label: 'Custom...', value: '__custom__' },
];

const selectedApolloJobTitle = ref('Founder');
const customApolloJobTitle = ref('');
const selectedApolloIndustry = ref('Marketing and Advertising');
const customApolloIndustry = ref('');
const selectedApolloLocation = ref('United States');
const customApolloLocation = ref('');
const selectedApolloCompanySize = ref('11,50');
const customApolloCompanySize = ref('');

const apolloFetchForm = useForm<{
    count: number;
    job_title: string;
    industry: string;
    location: string;
    company_size: string;
    keyword: string;
    _token: string;
}>({
    count: Math.min(props.apolloMaxFetch, 100),
    job_title: 'Founder',
    industry: 'Marketing and Advertising',
    location: 'United States',
    company_size: '11,50',
    keyword: '',
    _token: '',
});

const apolloFetchErrorMessage = computed(() =>
    (apolloFetchForm.errors as Record<string, string | undefined>).apollo ?? ''
);
const apolloPreviewLoading = ref(false);
const apolloPreviewRows = ref<Array<{ email: string; name: string }>>([]);
const apolloPreviewMessage = ref('');
const apolloPreviewHasRun = ref(false);
const apolloRequiredFiltersComplete = computed(() => {
    return [
        apolloFetchForm.job_title,
        apolloFetchForm.industry,
        apolloFetchForm.location,
        apolloFetchForm.company_size,
    ].every((value) => String(value ?? '').trim() !== '');
});
const apolloEstimatedFinalCount = computed(() =>
    Math.min(Math.max(1, Number(apolloFetchForm.count || 1)), props.apolloMaxFetch),
);

const aiUrlForm = useForm<{
    title: string;
    url: string;
}>({
    title: '',
    url: '',
});

const aiTranscriptForm = useForm<{
    title: string;
    transcript: string;
}>({
    title: 'Video Transcript',
    transcript: '',
});

const aiFileForm = useForm<{
    title: string;
    file: File | null;
}>({
    title: '',
    file: null,
});

const aiSourceMode = ref<'url' | 'transcript' | 'file'>('url');
const aiVideoTranscriptModalOpen = ref(false);
const aiVideoTranscriptGenerating = ref(false);
const aiVideoTranscriptUrlInput = ref('');
const aiUrlValidationError = ref('');
const offersUrlValidationErrors = ref<Record<number, string>>({});
const AI_SOURCE_LIMIT = 3;
const aiSourcesList = ref(props.aiSources ?? []);
const aiSourcesMeta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 8,
    total: aiSourcesList.value.length,
});
const aiSourcesLoading = ref(false);
const selectedAiSourceIds = ref<number[]>([]);
const aiSourceCount = computed(() => Math.max(aiSourcesMeta.value.total || 0, aiSourcesList.value.length));
const aiSourceLimitReached = computed(() => aiSourceCount.value >= AI_SOURCE_LIMIT);
const aiSourceSlotsRemaining = computed(() => Math.max(0, AI_SOURCE_LIMIT - aiSourceCount.value));

const previewOpen = ref(false);
const previewSource = ref<null | {
    id: number;
    title: string | null;
    type: string;
    chunks_url: string;
}>(null);
const previewChunks = ref<Array<{ id: number; chunk_index: number; content: string }>>([]);
const previewChunksMeta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 12,
    total: 0,
});
const previewChunksLoading = ref(false);

const csrfToken = (): string => {
    const tokenTag = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;

    return tokenTag?.content ?? '';
};

const xsrfCookieToken = (): string => {
    const encoded = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    if (!encoded) {
        return '';
    }

    try {
        return decodeURIComponent(encoded);
    } catch {
        return encoded;
    }
};

const refreshCsrfToken = async (): Promise<string> => {
    const tokenResponse = await fetch('/csrf-token', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!tokenResponse.ok) {
        return '';
    }

    try {
        const payload = await tokenResponse.json() as { token?: string };
        const refreshedToken = String(payload.token || '');

        if (refreshedToken) {
            const tokenTag = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;

            if (tokenTag) {
                tokenTag.content = refreshedToken;
            }
        }

        return refreshedToken;
    } catch {
        return '';
    }
};

const fetchWithCsrfRetry = async (url: string, init: RequestInit): Promise<Response> => {
    const makeRequest = (tokenOverride?: string): Promise<Response> => {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(init.headers as Record<string, string> | undefined),
            'X-CSRF-TOKEN': tokenOverride || csrfToken(),
        };

        const cookieToken = xsrfCookieToken();

        if (cookieToken) {
            headers['X-XSRF-TOKEN'] = cookieToken;
        }

        return fetch(url, {
            ...init,
            credentials: 'same-origin',
            headers,
        });
    };

    let response = await makeRequest();

    if (response.status !== 419) {
        return response;
    }

    const refreshedToken = await refreshCsrfToken();
    response = await makeRequest(refreshedToken || undefined);

    return response;
};

const extractErrorMessage = async (response: Response, fallback: string): Promise<string> => {
    try {
        const payload = await response.json() as { message?: string };

        return payload.message || fallback;
    } catch {
        return fallback;
    }
};

const loadAiSources = async (page = 1): Promise<void> => {
    if (!props.aiSourceUrls.index) {
        return;
    }

    aiSourcesLoading.value = true;

    try {
        const response = await fetch(`${props.aiSourceUrls.index}?page=${page}&per_page=8`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json() as {
            data?: Array<{
                id: number;
                type: string;
                title: string | null;
                source_url: string | null;
                status: string;
                error_message: string | null;
                processed_at: string | null;
                chunk_count: number;
                chunks_url: string;
                delete_url: string;
            }>;
            meta?: {
                current_page: number;
                last_page: number;
                per_page: number;
                total: number;
            };
        };

        aiSourcesList.value = payload.data ?? [];
        selectedAiSourceIds.value = selectedAiSourceIds.value.filter((id) =>
            aiSourcesList.value.some((source) => source.id === id),
        );
        aiSourcesMeta.value = payload.meta ?? aiSourcesMeta.value;
    } finally {
        aiSourcesLoading.value = false;
    }
};

const toggleAiSource = (id: number): void => {
    if (selectedAiSourceIds.value.includes(id)) {
        selectedAiSourceIds.value = selectedAiSourceIds.value.filter((item) => item !== id);

        return;
    }

    selectedAiSourceIds.value = [...selectedAiSourceIds.value, id];
};

const toggleAllAiSourcesOnPage = (): void => {
    const currentIds = aiSourcesList.value.map((source) => source.id);

    if (currentIds.length > 0 && currentIds.every((id) => selectedAiSourceIds.value.includes(id))) {
        selectedAiSourceIds.value = selectedAiSourceIds.value.filter((id) => !currentIds.includes(id));

        return;
    }

    const set = new Set(selectedAiSourceIds.value);
    currentIds.forEach((id) => set.add(id));
    selectedAiSourceIds.value = Array.from(set);
};

const refreshAiSourcesAfterDelete = async (): Promise<void> => {
    const requestedPage = aiSourcesMeta.value.current_page;
    await loadAiSources(requestedPage);

    if (aiSourcesList.value.length === 0 && requestedPage > 1) {
        await loadAiSources(requestedPage - 1);
    }
};

const deleteAiSource = (source: {
    id: number;
    title: string | null;
    delete_url: string;
}): void => {
    showConfirmToast(`Delete source "${source.title || 'Untitled Source'}"? This cannot be undone.`, () => {
        void (async () => {
            try {
                const response = await fetch(source.delete_url, {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                });

                if (!response.ok) {
                    showToast(await extractErrorMessage(response, 'Failed to delete source.'));

                    return;
                }

                selectedAiSourceIds.value = selectedAiSourceIds.value.filter((id) => id !== source.id);

                if (previewSource.value?.id === source.id) {
                    previewOpen.value = false;
                    previewSource.value = null;
                    previewChunks.value = [];
                }

                await refreshAiSourcesAfterDelete();
                showToast('Source deleted.');
            } catch {
                showToast('Failed to delete source.');
            }
        })();
    }, 'ai');
};

const bulkDeleteAiSources = (): void => {
    if (!props.aiSourceUrls.bulk_delete) {
        showToast('Bulk delete endpoint is not configured.');

        return;
    }

    if (selectedAiSourceIds.value.length === 0) {
        showToast('Select at least one source first.');

        return;
    }

    const idsToDelete = [...selectedAiSourceIds.value];

    showConfirmToast(`Delete ${idsToDelete.length} selected source(s)? This cannot be undone.`, () => {
        void (async () => {
            try {
                const response = await fetch(props.aiSourceUrls.bulk_delete!, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ source_ids: idsToDelete }),
                });

                if (!response.ok) {
                    showToast(await extractErrorMessage(response, 'Failed to bulk delete sources.'));

                    return;
                }

                if (previewSource.value && idsToDelete.includes(previewSource.value.id)) {
                    previewOpen.value = false;
                    previewSource.value = null;
                    previewChunks.value = [];
                }

                selectedAiSourceIds.value = [];
                await refreshAiSourcesAfterDelete();
                showToast('Selected sources deleted.');
            } catch {
                showToast('Failed to bulk delete sources.');
            }
        })();
    }, 'ai');
};

const openSourcePreview = async (source: {
    id: number;
    title: string | null;
    type: string;
    chunks_url: string;
}): Promise<void> => {
    previewSource.value = source;
    previewOpen.value = true;
    await loadPreviewChunks(1);
};

const loadPreviewChunks = async (page = 1): Promise<void> => {
    if (!previewSource.value) {
        return;
    }

    previewChunksLoading.value = true;

    try {
        const response = await fetch(`${previewSource.value.chunks_url}?page=${page}&per_page=12`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json() as {
            data?: Array<{ id: number; chunk_index: number; content: string }>;
            meta?: {
                current_page: number;
                last_page: number;
                per_page: number;
                total: number;
            };
        };

        previewChunks.value = payload.data ?? [];
        previewChunksMeta.value = payload.meta ?? previewChunksMeta.value;
    } finally {
        previewChunksLoading.value = false;
    }
};

watch(
    () => activeStep.value,
    (step) => {
        if (step === 6 && aiSourcesList.value.length === 0 && props.aiSourceUrls.index) {
            void loadAiSources(1);
        }
    },
);

const submitAiUrlSource = (): void => {
    if (aiSourceLimitReached.value) {
        showToast(`Source limit reached (${AI_SOURCE_LIMIT}). Delete one source to add another.`);

        return;
    }

    if (!props.aiSourceUrls.url) {
        showToast('AI URL source endpoint is not configured.');

        return;
    }

    const candidateUrl = aiUrlForm.url.trim();

    if (!isValidHttpUrl(candidateUrl)) {
        aiUrlValidationError.value = 'Please enter a valid URL, including http:// or https://';
        showToast('Please enter a valid website URL before adding source.');

        return;
    }

    aiUrlValidationError.value = '';
    aiUrlForm.url = candidateUrl;

    aiUrlForm.post(props.aiSourceUrls.url, {
        preserveScroll: true,
        onSuccess: () => {
            aiUrlForm.reset();
            aiUrlValidationError.value = '';
            showToast('Website source queued for ingestion.');
            void loadAiSources(1);
        },
        onError: () => {
            const sourceLimit = (aiUrlForm.errors as Record<string, string | undefined>).source_limit;

            if (sourceLimit) {
                showToast(sourceLimit);
            }
        },
    });
};

const submitAiTranscriptSource = (): void => {
    if (aiSourceLimitReached.value) {
        showToast(`Source limit reached (${AI_SOURCE_LIMIT}). Delete one source to add another.`);

        return;
    }

    if (!props.aiSourceUrls.transcript) {
        showToast('AI transcript endpoint is not configured.');

        return;
    }

    aiTranscriptForm.post(props.aiSourceUrls.transcript, {
        preserveScroll: true,
        onSuccess: () => {
            aiTranscriptForm.transcript = '';
            showToast('Transcript source queued for ingestion.');
            void loadAiSources(1);
        },
        onError: () => {
            const sourceLimit = (aiTranscriptForm.errors as Record<string, string | undefined>).source_limit;

            if (sourceLimit) {
                showToast(sourceLimit);
            }
        },
    });
};

const submitVideoTranscriptGeneration = async (videoUrlInput?: string): Promise<void> => {
    if (aiSourceLimitReached.value) {
        showToast(`Source limit reached (${AI_SOURCE_LIMIT}). Delete one source to add another.`);

        return;
    }

    if (!props.aiSourceUrls.video_transcript_generate) {
        showToast('AI video transcript endpoint is not configured.');

        return;
    }

    const explicitUrl = (videoUrlInput ?? '').trim();
    const existingUrl = form.video_url.trim();
    const resolvedUrl = explicitUrl || existingUrl;

    if (!resolvedUrl) {
        aiVideoTranscriptUrlInput.value = '';
        aiVideoTranscriptModalOpen.value = true;

        return;
    }

    aiVideoTranscriptGenerating.value = true;

    try {
        const response = await fetch(props.aiSourceUrls.video_transcript_generate, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                title: aiTranscriptForm.title || 'Video Transcript',
                video_url: explicitUrl || undefined,
            }),
        });

        if (!response.ok) {
            showToast(await extractErrorMessage(response, 'Failed to queue transcript generation.'));

            return;
        }

        const payload = await response.json() as {
            message?: string;
            video_url?: string;
        };

        if (payload.video_url && payload.video_url !== form.video_url) {
            form.video_url = payload.video_url;
        }

        aiVideoTranscriptModalOpen.value = false;
        aiVideoTranscriptUrlInput.value = '';
        showToast(payload.message || 'Video transcript generation queued.');
        await loadAiSources(1);
    } catch {
        showToast('Failed to queue transcript generation.');
    } finally {
        aiVideoTranscriptGenerating.value = false;
    }
};

const confirmVideoTranscriptGenerationFromModal = (): void => {
    void submitVideoTranscriptGeneration(aiVideoTranscriptUrlInput.value);
};

const onAiFileSelected = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    aiFileForm.file = target.files?.[0] ?? null;
};

const submitAiFileSource = (): void => {
    if (aiSourceLimitReached.value) {
        showToast(`Source limit reached (${AI_SOURCE_LIMIT}). Delete one source to add another.`);

        return;
    }

    if (!props.aiSourceUrls.file) {
        showToast('AI file source endpoint is not configured.');

        return;
    }

    if (!aiFileForm.file) {
        showToast('Select a file before uploading.');

        return;
    }

    aiFileForm.post(props.aiSourceUrls.file, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            aiFileForm.reset();
            showToast('File source queued for ingestion.');
            void loadAiSources(1);
        },
        onError: () => {
            const sourceLimit = (aiFileForm.errors as Record<string, string | undefined>).source_limit;

            if (sourceLimit) {
                showToast(sourceLimit);
            }
        },
    });
};

const onAttendeeCsvSelected = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    attendeeCsvForm.file = file;
};

const importAttendeesCsv = (): void => {
    if (!props.attendeeImportUrl || !attendeeCsvForm.file) {
        showToast('Select an import file first.');

        return;
    }

    attendeeCsvForm.post(props.attendeeImportUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            attendeeCsvForm.reset();
            showToast('Attendees imported successfully.');
        },
    });
};

const openApolloModal = (): void => {
    if (!props.attendeeActionUrls?.apollo_fetch_url) {
        showToast('Create webinar first before using Apollo fetch.');

        return;
    }

    apolloFetchForm.clearErrors();
    apolloFetchForm.count = Math.min(props.apolloMaxFetch, 100);
    apolloFetchForm.job_title = 'Founder';
    apolloFetchForm.industry = 'Marketing and Advertising';
    apolloFetchForm.location = 'United States';
    apolloFetchForm.company_size = '11,50';
    apolloFetchForm.keyword = '';

    selectedApolloJobTitle.value = 'Founder';
    customApolloJobTitle.value = '';
    selectedApolloIndustry.value = 'Marketing and Advertising';
    customApolloIndustry.value = '';
    selectedApolloLocation.value = 'United States';
    customApolloLocation.value = '';
    selectedApolloCompanySize.value = '11,50';
    customApolloCompanySize.value = '';

    apolloPreviewRows.value = [];
    apolloPreviewMessage.value = '';
    apolloPreviewHasRun.value = false;

    apolloModalOpen.value = true;
};

const previewApolloFetch = async (): Promise<void> => {
    if (!props.attendeeActionUrls?.apollo_preview_url) {
        showToast('Apollo preview endpoint is not configured.');

        return;
    }

    if (!apolloRequiredFiltersComplete.value) {
        showToast('Fill all required Apollo filters before previewing.');

        return;
    }

    if (apolloFetchForm.count > props.apolloMaxFetch) {
        apolloFetchForm.count = props.apolloMaxFetch;
    }

    apolloPreviewLoading.value = true;
    apolloPreviewMessage.value = '';
    apolloPreviewRows.value = [];

    try {
        const response = await fetchWithCsrfRetry(props.attendeeActionUrls.apollo_preview_url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                count: apolloFetchForm.count,
                job_title: apolloFetchForm.job_title,
                industry: apolloFetchForm.industry,
                location: apolloFetchForm.location,
                company_size: apolloFetchForm.company_size,
                keyword: apolloFetchForm.keyword,
            }),
        });

        if (!response.ok) {
            const message = await extractErrorMessage(response, 'Apollo preview failed.');
            apolloPreviewMessage.value = message;
            showToast(message);

            return;
        }

        const payload = await response.json() as {
            sample?: Array<{ email: string; name: string }>;
            sample_count?: number;
            preview_limit?: number;
        };

        apolloPreviewRows.value = payload.sample ?? [];
        apolloPreviewMessage.value = apolloPreviewRows.value.length > 0
            ? `Preview ready: ${apolloPreviewRows.value.length} lead(s) sampled.`
            : 'No leads matched this filter set.';
        apolloPreviewHasRun.value = true;
    } catch {
        apolloPreviewMessage.value = 'Apollo preview failed.';
        showToast('Apollo preview failed.');
    } finally {
        apolloPreviewLoading.value = false;
    }
};

const submitApolloFetch = async (): Promise<void> => {
    if (!props.attendeeActionUrls?.apollo_fetch_url) {
        showToast('Apollo fetch endpoint is not configured.');

        return;
    }

    if (!apolloRequiredFiltersComplete.value) {
        showToast('Fill all required Apollo filters before fetching.');

        return;
    }

    if (apolloFetchForm.count > props.apolloMaxFetch) {
        apolloFetchForm.count = props.apolloMaxFetch;
    }

    const refreshedToken = await refreshCsrfToken();
    apolloFetchForm._token = refreshedToken || csrfToken();

    apolloFetchForm.post(props.attendeeActionUrls.apollo_fetch_url, {
        preserveScroll: true,
        onSuccess: () => {
            apolloModalOpen.value = false;
            showToast('Apollo import queued. Leads and emails will process in background workers.');
        },
        onError: (errors) => {
            if (errors.apollo) {
                showToast(errors.apollo);
            }
        },
    });
};

watch(selectedApolloJobTitle, (value) => {
    apolloFetchForm.job_title = value === '__custom__' ? customApolloJobTitle.value.trim() : value;
});

watch(customApolloJobTitle, (value) => {
    if (selectedApolloJobTitle.value === '__custom__') {
        apolloFetchForm.job_title = value.trim();
    }
});

watch(selectedApolloIndustry, (value) => {
    apolloFetchForm.industry = value === '__custom__' ? customApolloIndustry.value.trim() : value;
});

watch(customApolloIndustry, (value) => {
    if (selectedApolloIndustry.value === '__custom__') {
        apolloFetchForm.industry = value.trim();
    }
});

watch(selectedApolloLocation, (value) => {
    apolloFetchForm.location = value === '__custom__' ? customApolloLocation.value.trim() : value;
});

watch(customApolloLocation, (value) => {
    if (selectedApolloLocation.value === '__custom__') {
        apolloFetchForm.location = value.trim();
    }
});

watch(selectedApolloCompanySize, (value) => {
    apolloFetchForm.company_size = value === '__custom__' ? customApolloCompanySize.value.trim() : value;
});

watch(customApolloCompanySize, (value) => {
    if (selectedApolloCompanySize.value === '__custom__') {
        apolloFetchForm.company_size = value.trim();
    }
});

watch(
    () => [
        apolloFetchForm.count,
        apolloFetchForm.job_title,
        apolloFetchForm.industry,
        apolloFetchForm.location,
        apolloFetchForm.company_size,
        apolloFetchForm.keyword,
    ],
    () => {
        apolloPreviewHasRun.value = false;
    },
);

const missingRequiredByStep = (step: number): string[] => {
    return requiredChecks
        .filter((item) => item.step === step)
        .filter((item) => isRequiredMissing(item.key))
        .map((item) => item.label);
};

const missingRequiredAll = (): Array<{ step: number; label: string }> => {
    return requiredChecks
        .filter((item) => isRequiredMissing(item.key))
        .map((item) => ({ step: item.step, label: item.label }));
};

const showToast = (message: string): void => {
    toastMessage.value = message;
    window.setTimeout(() => {
        if (toastMessage.value === message) {
            toastMessage.value = null;
        }
    }, 4000);
};

const showConfirmToast = (message: string, action: () => void, section: 'ai' | 'attendees' | null = null): void => {
    confirmToastMessage.value = message;
    confirmAction.value = action;
    confirmSection.value = section;
};

const cancelConfirmToast = (): void => {
    confirmToastMessage.value = null;
    confirmAction.value = null;
    confirmSection.value = null;
};

const continueConfirmToast = (): void => {
    if (confirmAction.value) {
        const action = confirmAction.value;
        cancelConfirmToast();
        action();
    }
};

const toggleSubscribed = (id: number): void => {
    if (selectedSubscribedIds.value.includes(id)) {
        selectedSubscribedIds.value = selectedSubscribedIds.value.filter((item) => item !== id);

        return;
    }

    selectedSubscribedIds.value = [...selectedSubscribedIds.value, id];
};

const toggleUnsubscribed = (id: number): void => {
    if (selectedUnsubscribedIds.value.includes(id)) {
        selectedUnsubscribedIds.value = selectedUnsubscribedIds.value.filter((item) => item !== id);

        return;
    }

    selectedUnsubscribedIds.value = [...selectedUnsubscribedIds.value, id];
};

const toggleAllSubscribed = (): void => {
    if (selectedSubscribedIds.value.length === props.attendees.subscribed.length) {
        selectedSubscribedIds.value = [];

        return;
    }

    selectedSubscribedIds.value = props.attendees.subscribed.map((attendee) => attendee.id);
};

const toggleAllUnsubscribed = (): void => {
    if (selectedUnsubscribedIds.value.length === props.attendees.unsubscribed.length) {
        selectedUnsubscribedIds.value = [];

        return;
    }

    selectedUnsubscribedIds.value = props.attendees.unsubscribed.map((attendee) => attendee.id);
};

const moveSingleToUnsubscribed = (url?: string): void => {
    if (!url) {
        return;
    }

    showConfirmToast('Move this attendee to unsubscribed list?', () => {
        router.post(url, {}, { preserveScroll: true });
    }, 'attendees');
};

const deleteSingleUnsubscribed = (url?: string): void => {
    if (!url) {
        return;
    }

    showConfirmToast('Delete this unsubscribed attendee email permanently?', () => {
        router.delete(url, { preserveScroll: true });
    }, 'attendees');
};

const moveBulkToUnsubscribed = (): void => {
    if (!props.attendeeActionUrls?.bulk_unsubscribe_url) {
        return;
    }

    if (selectedSubscribedIds.value.length === 0) {
        showToast('Select at least one registered attendee first.');

        return;
    }

    showConfirmToast(`Move ${selectedSubscribedIds.value.length} attendee(s) to unsubscribed list?`, () => {
        bulkUnsubscribeForm.attendee_ids = [...selectedSubscribedIds.value];
        bulkUnsubscribeForm.post(props.attendeeActionUrls!.bulk_unsubscribe_url, {
            preserveScroll: true,
            onSuccess: () => {
                selectedSubscribedIds.value = [];
            },
        });
    }, 'attendees');
};

const deleteBulkUnsubscribed = (): void => {
    if (!props.attendeeActionUrls?.bulk_delete_url) {
        return;
    }

    if (selectedUnsubscribedIds.value.length === 0) {
        showToast('Select at least one unsubscribed attendee first.');

        return;
    }

    showConfirmToast(`Delete ${selectedUnsubscribedIds.value.length} unsubscribed attendee(s)? This cannot be undone.`, () => {
        bulkDeleteForm.attendee_ids = [...selectedUnsubscribedIds.value];
        bulkDeleteForm.post(props.attendeeActionUrls!.bulk_delete_url, {
            preserveScroll: true,
            onSuccess: () => {
                selectedUnsubscribedIds.value = [];
            },
        });
    }, 'attendees');
};

const exportAttendeesCsv = (
    list: Array<{ name: string; email: string; registered_at?: string | null; unsubscribed_at?: string | null }>,
    fileName: string,
): void => {
    if (list.length === 0) {
        showToast('No attendees available to export.');

        return;
    }

    const header = 'name,email,date';
    const rows = list.map((attendee) => {
        const date = attendee.registered_at ?? attendee.unsubscribed_at ?? '';

        return `"${attendee.name.replace(/"/g, '""')}","${attendee.email.replace(/"/g, '""')}","${String(date).replace(/"/g, '""')}"`;
    });

    const csv = [header, ...rows].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
};

const videoUrlPlaceholder = computed((): string => {
    if (form.video_source === 'youtube') {
        return 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    }

    if (form.video_source === 'vimeo') {
        return 'https://vimeo.com/123456789';
    }

    return 'https://cdn.example.com/webinars/my-video.mp4';
});

const setPrimaryRegistrationButton = (index: number): void => {
    form.registration_settings.buttons = form.registration_settings.buttons.map((button, idx) => ({
        ...button,
        is_primary: idx === index,
        enabled: idx === index ? true : button.enabled,
    }));
};

const toggleRegistrationButtonEnabled = (index: number): void => {
    const current = form.registration_settings.buttons[index];

    if (!current) {
        return;
    }

    current.enabled = !current.enabled;

    const enabledIndices = form.registration_settings.buttons
        .map((button, idx) => ({ idx, enabled: button.enabled }))
        .filter((item) => item.enabled)
        .map((item) => item.idx);

    if (enabledIndices.length === 0) {
        current.enabled = true;
        showToast('At least one registration button must stay enabled.');

        return;
    }

    if (current.is_primary && !current.enabled) {
        form.registration_settings.buttons[enabledIndices[0]].is_primary = true;
        current.is_primary = false;
    }
};

const nextStep = (): void => {
    const missingFields = missingRequiredByStep(activeStep.value);

    if (missingFields.length > 0) {
        showToast(`Please fill required fields: ${missingFields.join(', ')}`);

        return;
    }

    if (activeStep.value < steps.length - 1) {
        activeStep.value += 1;
    }
};

const previousStep = (): void => {
    if (activeStep.value > 0) {
        activeStep.value -= 1;
    }
};

const addOffer = (): void => {
    form.offers.push({
        title: '',
        description: '',
        trigger_second: 600,
        button_text: 'Claim Offer',
        button_url: '',
        display_mode: 'chat',
    });
};

const removeOffer = (index: number): void => {
    form.offers.splice(index, 1);
};

const submit = (): void => {
    const missingFields = missingRequiredAll();

    if (missingFields.length > 0) {
        activeStep.value = missingFields[0].step;
        showToast(`Required fields still missing: ${missingFields.map((item) => item.label).join(', ')}`);

        return;
    }

    if (form.playback_settings.exit_popup_enabled) {
        const heading = form.playback_settings.exit_popup_heading.trim();
        const bodyPlain = getPlainTextFromHtml(form.playback_settings.exit_popup_body || '');
        const bodyRaw = (form.playback_settings.exit_popup_body || '').replace(/<[^>]*>/g, '').trim();
        const ctaText = form.playback_settings.exit_popup_cta_text.trim();
        const ctaUrl = form.playback_settings.exit_popup_cta_url.trim();

        if (!heading || (!bodyPlain && !bodyRaw) || !ctaText || !ctaUrl) {
            activeStep.value = 5;
            showToast('Fill all exit popup fields before saving.');

            return;
        }

        if (bodyPlain.length > EXIT_POPUP_BODY_MAX_CHARS) {
            activeStep.value = 5;
            showToast(`Exit popup message is too long. Keep it under ${EXIT_POPUP_BODY_MAX_CHARS} characters.`);

            return;
        }
    }

    const offerUrlErrors: Record<number, string> = {};

    for (let index = 0; index < form.offers.length; index += 1) {
        const offer = form.offers[index];
        const candidateUrl = offer.button_url.trim();

        if (!isValidHttpUrl(candidateUrl)) {
            offerUrlErrors[index] = 'Enter a valid URL with http:// or https://';
            continue;
        }

        offer.button_url = candidateUrl;
    }

    offersUrlValidationErrors.value = offerUrlErrors;

    if (Object.keys(offerUrlErrors).length > 0) {
        activeStep.value = 5;
        showToast('Some offer URLs are invalid. Please fix them before saving.');

        return;
    }

    if (props.method === 'put') {
        form.put(props.actionUrl, { preserveScroll: true });

        return;
    }

    form.post(props.actionUrl, { preserveScroll: true });
};

const isValidHttpUrl = (value: string): boolean => {
    if (value === '') {
        return false;
    }

    try {
        const parsed = new URL(value);

        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
        return false;
    }
};

const stepHeaderBg: string[] = [
    'bg-indigo-100 dark:bg-indigo-950/40',
    'bg-sky-100 dark:bg-sky-950/40',
    'bg-emerald-100 dark:bg-emerald-950/40',
    'bg-teal-100 dark:bg-teal-950/40',
    'bg-violet-100 dark:bg-violet-950/40',
    'bg-orange-100 dark:bg-orange-950/40',
    'bg-violet-100 dark:bg-violet-950/40',
    'bg-rose-100 dark:bg-rose-950/40',
    'bg-amber-100 dark:bg-amber-950/40',
];

const stepMeta: Array<{ icon: string; color: string }> = [
    { icon: 'solar:document-text-bold-duotone', color: 'text-indigo-500' },
    { icon: 'solar:play-stream-bold-duotone', color: 'text-sky-500' },
    { icon: 'solar:user-check-rounded-bold-duotone', color: 'text-emerald-500' },
    { icon: 'solar:users-group-rounded-bold-duotone', color: 'text-teal-500' },
    { icon: 'solar:chat-round-dots-bold-duotone', color: 'text-violet-500' },
    { icon: 'solar:tag-price-bold-duotone', color: 'text-orange-500' },
    { icon: 'solar:stars-bold-duotone', color: 'text-violet-500' },
    { icon: 'solar:bell-bing-bold-duotone', color: 'text-rose-500' },
    { icon: 'solar:chart-2-bold-duotone', color: 'text-amber-500' },
];
</script>

<template>
    <div class="space-y-6">
        <!-- Info toast -->
        <div
            v-if="toastMessage"
            class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-300"
        >
            <Icon icon="solar:info-circle-bold-duotone" class="mt-0.5 size-4 shrink-0 text-amber-500" />
            <span>{{ toastMessage }}</span>
        </div>

        <!-- Confirm toast (fallback for steps without inline confirm) -->
        <div
            v-if="confirmToastMessage && !confirmSection"
            class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-800/50 dark:bg-rose-950/30"
        >
            <div class="flex items-start gap-3">
                <Icon icon="solar:danger-bold-duotone" class="mt-0.5 size-4 shrink-0 text-rose-500" />
                <div class="flex-1">
                    <p class="text-sm text-rose-800 dark:text-rose-300">{{ confirmToastMessage }}</p>
                    <div class="mt-3 flex items-center gap-2">
                <button
                    type="button"
                            class="inline-flex h-7 items-center rounded-lg bg-rose-600 px-3 text-xs font-semibold text-white hover:bg-rose-700"
                    @click="continueConfirmToast"
                >
                            Confirm
                </button>
                <button
                    type="button"
                            class="inline-flex h-7 items-center rounded-lg border border-rose-200 bg-white px-3 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:bg-transparent dark:text-rose-300"
                    @click="cancelConfirmToast"
                >
                    Cancel
                </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Enterprise Step Indicator ── -->
        <div class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
            <!-- Step strip -->
            <div class="flex overflow-x-auto scrollbar-none">
                <button
                    v-for="(step, index) in steps"
                    :key="step"
                    type="button"
                    class="group relative flex min-w-0 flex-1 flex-col items-center gap-2 px-3 py-4 text-center transition-colors"
                    :class="[
                        index === activeStep
                            ? 'bg-primary/5'
                            : 'hover:bg-muted/40',
                        index < activeStep ? 'cursor-pointer' : '',
                    ]"
                    @click="activeStep = index"
                >
                    <!-- connecting line left -->
                    <div
                        v-if="index > 0"
                        class="absolute left-0 top-[29px] h-px w-1/2 -translate-y-1/2"
                        :class="index <= activeStep ? 'bg-primary/40' : 'bg-border'"
                    />
                    <!-- connecting line right -->
                    <div
                        v-if="index < steps.length - 1"
                        class="absolute right-0 top-[29px] h-px w-1/2 -translate-y-1/2"
                        :class="index < activeStep ? 'bg-primary/40' : 'bg-border'"
                    />
                    <!-- step circle -->
                    <div
                        class="relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 transition-all"
                        :class="[
                            index === activeStep
                                ? 'border-primary bg-primary text-primary-foreground shadow-md shadow-primary/20'
                                : index < activeStep
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border bg-background text-muted-foreground',
                        ]"
                    >
                        <Icon
                            v-if="index < activeStep"
                            icon="solar:check-circle-bold"
                            class="size-5 text-primary"
                        />
                        <template v-else>
                            <span
                                v-if="missingRequiredByStep(index).length > 0 && index > activeStep"
                                class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-background bg-rose-500"
                            />
                            <Icon
                                :icon="stepMeta[index]?.icon ?? 'solar:document-text-bold-duotone'"
                                class="size-4"
                                :class="index === activeStep ? 'text-primary-foreground' : stepMeta[index]?.color"
                            />
                        </template>
                    </div>
                    <!-- step label -->
                    <span
                        class="hidden text-[10px] font-semibold leading-tight tracking-wide xl:block"
                        :class="index === activeStep ? 'text-primary' : index < activeStep ? 'text-foreground' : 'text-muted-foreground'"
                    >
                        {{ step }}
                    </span>
                    <span
                        class="xl:hidden text-[10px] font-semibold text-muted-foreground"
                        :class="index === activeStep ? '!text-primary' : ''"
                    >
                        {{ index + 1 }}
                    </span>
                </button>
            </div>
            <!-- active step header below strip -->
            <div class="border-t border-border/50 bg-muted/20 px-5 py-3 flex items-center gap-3">
                <div
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                    :class="stepHeaderBg[activeStep]"
                >
                    <Icon
                        :icon="stepMeta[activeStep]?.icon ?? 'solar:document-text-bold-duotone'"
                        class="size-4"
                        :class="stepMeta[activeStep]?.color"
                    />
                </div>
                <div>
                    <p class="text-xs text-muted-foreground font-medium">Step {{ activeStep + 1 }} of {{ steps.length }}</p>
                    <p class="text-sm font-semibold text-foreground leading-tight">{{ steps[activeStep] }}</p>
                </div>
                <div class="ml-auto flex items-center gap-1 text-xs text-muted-foreground">
                    <Icon icon="solar:check-circle-bold" class="size-3.5 text-primary" />
                    {{ activeStep + 1 }} / {{ steps.length }} completed
                </div>
            </div>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <div v-if="activeStep === 0" class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:document-text-bold-duotone" class="size-5 text-indigo-500" />
                    <h3 class="text-base font-semibold text-foreground">Basics</h3>
                    <span class="text-xs text-muted-foreground">Configure the core webinar details</span>
                </div>
                <div class="grid gap-5 p-5">
                <div class="grid gap-2 md:grid-cols-2 md:gap-4">
                    <div class="grid gap-2">
                        <Label for="title_prefix">Title prefix</Label>
                        <Input
                            id="title_prefix"
                            v-model="form.title_prefix"
                            placeholder="[Confirmation]"
                        />
                        <InputError :message="form.errors.title_prefix" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="title">{{ markRequired('Webinar Title') }}</Label>
                        <Input id="title" v-model="form.title" required />
                        <InputError :message="form.errors.title" />
                    </div>
                </div>
                <div class="grid gap-2 md:grid-cols-2 md:gap-4">
                    <div class="grid gap-2">
                        <Label for="host_name">{{ markRequired('Host Name') }}</Label>
                        <Input id="host_name" v-model="form.host_name" required placeholder="John Smith or Domain Profits Team" />
                        <p class="text-xs text-muted-foreground">Use a person or brand name only — not an email address or URL.</p>
                        <InputError :message="form.errors.host_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="thumbnail_path">Thumbnail Path / URL</Label>
                        <Input id="thumbnail_path" v-model="form.thumbnail_path" placeholder="/storage/webinars/thumb.jpg" />
                        <InputError :message="form.errors.thumbnail_path" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="description">Description</Label>
                    <RichTextEditor
                        id="description"
                        v-model="form.description"
                        placeholder="Use paragraphs and spacing for better email readability."
                    />
                    <InputError :message="form.errors.description" />
                </div>
                <div class="grid gap-2">
                    <Label>Webinar Access Mode</Label>
                    <div class="grid gap-2 md:grid-cols-2">
                        <label
                            class="flex cursor-pointer items-start gap-3 rounded-md border p-3 transition"
                            :class="form.schedule_mode === 'auto' ? 'border-primary bg-primary/5' : 'border-border'"
                        >
                            <input
                                v-model="form.schedule_mode"
                                type="radio"
                                value="auto"
                                class="mt-0.5"
                            >
                            <span class="text-sm">
                                <span class="block font-medium">Auto (always available)</span>
                                <span class="text-muted-foreground">Visitors can register and join at any time.</span>
                            </span>
                        </label>
                        <label
                            class="flex cursor-pointer items-start gap-3 rounded-md border p-3 transition"
                            :class="form.schedule_mode === 'scheduled' ? 'border-primary bg-primary/5' : 'border-border'"
                        >
                            <input
                                v-model="form.schedule_mode"
                                type="radio"
                                value="scheduled"
                                class="mt-0.5"
                            >
                            <span class="text-sm">
                                <span class="block font-medium">Scheduled</span>
                                <span class="text-muted-foreground">Uses date/time and ends 1h 30m after start.</span>
                            </span>
                        </label>
                    </div>
                </div>
                <div v-if="form.schedule_mode === 'scheduled'" class="grid gap-2 md:grid-cols-2 md:gap-4">
                    <div class="grid gap-2">
                        <Label for="scheduled_at">{{ markRequired('Webinar Date and Time') }}</Label>
                        <Input id="scheduled_at" v-model="form.scheduled_at" type="datetime-local" required />
                        <InputError :message="form.errors.scheduled_at" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="scheduled_timezone">{{ markRequired('Time Zone') }}</Label>
                        <select
                            id="scheduled_timezone"
                            v-model="form.scheduled_timezone"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option v-for="timezone in timezoneOptions" :key="timezone" :value="timezone">
                                {{ timezone }}
                            </option>
                        </select>
                        <InputError :message="form.errors.scheduled_timezone" />
                    </div>
                    </div>
                </div>
            </div>

            <div v-if="activeStep === 1" class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:play-stream-bold-duotone" class="size-5 text-sky-500" />
                    <h3 class="text-base font-semibold text-foreground">Video</h3>
                    <span class="text-xs text-muted-foreground">Set your video source and duration</span>
                </div>
                <div class="grid gap-5 p-5">
                <div class="grid gap-2">
                    <Label for="video_source">{{ markRequired('Video Source') }}</Label>
                    <select
                        id="video_source"
                        v-model="form.video_source"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option value="youtube">YouTube</option>
                        <option value="vimeo">Vimeo</option>
                        <option value="direct">Direct URL</option>
                    </select>
                    <InputError :message="form.errors.video_source" />
                </div>
                <div class="grid gap-2">
                    <Label for="video_url">{{ markRequired('Video URL') }}</Label>
                    <Input id="video_url" v-model="form.video_url" :placeholder="videoUrlPlaceholder" required />
                    <p class="text-xs text-muted-foreground">
                        Example format: <span class="font-mono">{{ videoUrlPlaceholder }}</span>
                    </p>
                    <InputError :message="form.errors.video_url" />
                </div>
                <div class="grid gap-2">
                    <Label>Video Duration</Label>
                    <div class="flex items-end gap-2">
                        <div class="grid gap-1">
                            <Label for="duration_hours" class="text-xs text-muted-foreground">Hours</Label>
                            <Input
                                id="duration_hours"
                                type="number"
                                min="0"
                                max="23"
                                class="w-20"
                                :model-value="durationHours"
                                @update:model-value="(v) => { durationHours = Number(v) || 0; syncDurationToForm(); }"
                            />
                        </div>
                        <span class="pb-2 text-muted-foreground">:</span>
                        <div class="grid gap-1">
                            <Label for="duration_minutes" class="text-xs text-muted-foreground">Minutes</Label>
                            <Input
                                id="duration_minutes"
                                type="number"
                                min="0"
                                max="59"
                                class="w-20"
                                :model-value="durationMinutes"
                                @update:model-value="(v) => { durationMinutes = Number(v) || 0; syncDurationToForm(); }"
                            />
                        </div>
                        <span class="pb-2 text-muted-foreground">:</span>
                        <div class="grid gap-1">
                            <Label for="duration_seconds" class="text-xs text-muted-foreground">Seconds</Label>
                            <Input
                                id="duration_seconds"
                                type="number"
                                min="0"
                                max="59"
                                class="w-20"
                                :model-value="durationSecondsField"
                                @update:model-value="(v) => { durationSecondsField = Number(v) || 0; syncDurationToForm(); }"
                            />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Total: {{ form.video_duration_seconds ?? 0 }} seconds — this controls when the webinar ends.
                    </p>
                    <InputError :message="form.errors.video_duration_seconds" />
                </div>
                </div>
            </div>

            <div v-if="activeStep === 2" class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:user-check-rounded-bold-duotone" class="size-5 text-emerald-500" />
                    <h3 class="text-base font-semibold text-foreground">Registration</h3>
                    <span class="text-xs text-muted-foreground">Customize your registration page and CTAs</span>
                </div>
                <div class="grid gap-5 p-5">
                <p class="text-sm text-muted-foreground">
                    Public registration link: <span class="font-mono">{{ publicRegisterLink }}</span>
                </p>
                <p class="text-sm text-muted-foreground">
                    Webinar room link pattern: <span class="font-mono">{{ publicRoomExample }}</span>
                </p>
                <p class="text-sm text-muted-foreground">
                    Webinar UUID: <span class="font-mono">{{ form.uuid ?? 'Generated after create' }}</span>
                </p>
                <p class="text-sm text-muted-foreground">
                    Required registrant fields are name and email.
                </p>

                <div class="space-y-3 rounded-md border p-3">
                    <p class="text-sm font-semibold">Join Buttons (Psychology CTA)</p>
                    <p class="text-xs text-muted-foreground">
                        Configure up to 3 submit buttons. They all join the same webinar, but can show urgency labels.
                    </p>

                    <div
                        v-for="(button, index) in form.registration_settings.buttons"
                        :key="`reg-btn-${index}`"
                        class="grid gap-3 rounded-md border p-3"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-medium">Button {{ index + 1 }}</p>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="button.enabled"
                                class="inline-flex items-center gap-2 text-xs"
                                @click="toggleRegistrationButtonEnabled(index)"
                            >
                                <span class="text-muted-foreground">Enabled</span>
                                <span
                                    class="relative inline-flex h-5 w-9 items-center rounded-full transition"
                                    :class="button.enabled ? 'bg-primary' : 'bg-muted'"
                                >
                                    <span
                                        class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                                        :class="button.enabled ? 'translate-x-4' : 'translate-x-1'"
                                    />
                                </span>
                            </button>
                        </div>

                        <div class="grid gap-2">
                            <Label>Button Label *</Label>
                            <Input v-model="button.label" placeholder="Join Webinar" />
                        </div>

                        <div class="grid gap-2 md:grid-cols-3 md:gap-3">
                            <div class="grid gap-2">
                                <Label>Urgency Type</Label>
                                <select
                                    v-model="button.urgency_mode"
                                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                >
                                    <option value="none">None</option>
                                    <option value="minutes">Blinking Minutes</option>
                                    <option value="live">Blinking LIVE</option>
                                </select>
                            </div>
                            <div class="grid gap-2">
                                <Label>Minutes (if selected)</Label>
                                <Input
                                    type="number"
                                    min="1"
                                    :disabled="button.urgency_mode !== 'minutes'"
                                    :model-value="button.urgency_minutes ?? ''"
                                    @update:model-value="(value) => {
                                        button.urgency_minutes = value === '' ? null : Number(value);
                                    }"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label>Primary Button</Label>
                                <button
                                    type="button"
                                    class="h-9 rounded-md border px-3 text-sm"
                                    :class="button.is_primary ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background'"
                                    @click="setPrimaryRegistrationButton(index)"
                                >
                                    {{ button.is_primary ? 'Primary' : 'Set Primary' }}
                                </button>
                            </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="activeStep === 3" class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:users-group-rounded-bold-duotone" class="size-5 text-teal-500" />
                    <h3 class="text-base font-semibold text-foreground">Attendees</h3>
                    <span class="text-xs text-muted-foreground">Import and manage registered attendees</span>
                </div>
                <div class="grid gap-5 p-5">
                <p class="text-sm text-muted-foreground">
                    Upload CSV/XLSX/XLS files to register attendees and send invitation emails.
                </p>

                <div v-if="!attendeeImportUrl" class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                    Create the webinar first, then return to this tab to upload attendees.
                </div>

                <div v-else class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-dashed bg-muted/20 p-3">
                        <p class="text-xs text-muted-foreground">
                            Fetch targeted contacts from AI and auto-register them to this webinar.
                        </p>
                        <Button type="button" variant="outline" class="h-8 text-xs" @click="openApolloModal">
                            <Icon icon="solar:database-bold-duotone" class="mr-1 size-4" />
                            Fetch Emails via AI
                        </Button>
                    </div>

                    <div class="grid gap-3 rounded-md border p-3">
                        <Label for="attendee_csv">Import File *</Label>
                        <Input id="attendee_csv" type="file" accept=".csv,.txt,.xlsx,.xls" @change="onAttendeeCsvSelected" />
                        <Button type="button"  :disabled="attendeeCsvForm.processing" @click="importAttendeesCsv">
                            Upload and Register
                        </Button>
                    </div>

                    <!-- Inline confirm banner for attendee actions -->
                    <div
                        v-if="confirmToastMessage && confirmSection === 'attendees'"
                        class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-800/50 dark:bg-rose-950/30"
                    >
                        <Icon icon="solar:danger-bold-duotone" class="mt-0.5 size-4 shrink-0 text-rose-500" />
                        <div class="flex-1">
                            <p class="text-sm font-medium text-rose-800 dark:text-rose-300">{{ confirmToastMessage }}</p>
                            <div class="mt-2 flex items-center gap-2">
                                <button
                                    type="button"
                                    class="inline-flex h-7 items-center rounded-lg bg-rose-600 px-3 text-xs font-semibold text-white hover:bg-rose-700"
                                    @click="continueConfirmToast"
                                >
                                    Confirm
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex h-7 items-center rounded-lg border border-rose-200 bg-white px-3 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:bg-transparent dark:text-rose-300"
                                    @click="cancelConfirmToast"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-1 text-xs"
                            :class="attendeePanel === 'subscribed' ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background'"
                            @click="attendeePanel = 'subscribed'"
                        >
                            Registered ({{ attendees.subscribed_total }})
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-1 text-xs"
                            :class="attendeePanel === 'unsubscribed' ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background'"
                            @click="attendeePanel = 'unsubscribed'"
                        >
                            Unsubscribed ({{ attendees.unsubscribed_total }})
                        </button>
                    </div>

                    <div v-if="attendeePanel === 'subscribed'" class="overflow-hidden rounded-md border">
                        <div class="flex flex-wrap items-center gap-2 border-b bg-muted/20 px-3 py-2">
                            <Button type="button" variant="outline" class="h-8 px-2 text-xs" @click="toggleAllSubscribed">
                                {{
                                    selectedSubscribedIds.length === attendees.subscribed.length && attendees.subscribed.length > 0
                                        ? 'Uncheck All (shown)'
                                        : 'Check All (shown)'
                                }}
                            </Button>
                            <Button type="button" variant="outline" class="h-8 px-2 text-xs" @click="moveBulkToUnsubscribed">
                                Move Selected to Unsubscribed
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                class="h-8 px-2 text-xs"
                                @click="exportAttendeesCsv(attendees.subscribed, 'registered-attendees.csv')"
                            >
                                Export Emails (shown)
                            </Button>
                        </div>
                        <table class="w-full text-sm">
                            <thead class="bg-muted/40 text-left">
                                <tr>
                                    <th class="px-3 py-2">Select</th>
                                    <th class="px-3 py-2">Name</th>
                                    <th class="px-3 py-2">Email</th>
                                    <th class="px-3 py-2">Registered At</th>
                                    <th class="px-3 py-2">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="attendee in attendees.subscribed" :key="attendee.id" class="border-t">
                                    <td class="px-3 py-2">
                                        <input
                                            :checked="selectedSubscribedIds.includes(attendee.id)"
                                            type="checkbox"
                                            class="h-4 w-4"
                                            @change="toggleSubscribed(attendee.id)"
                                        />
                                    </td>
                                    <td class="px-3 py-2">{{ attendee.name }}</td>
                                    <td class="px-3 py-2">{{ attendee.email }}</td>
                                    <td class="px-3 py-2 text-xs text-muted-foreground">{{ attendee.registered_at ?? '-' }}</td>
                                    <td class="px-3 py-2">
                                        <button
                                            v-if="attendee.unsubscribe_url"
                                            type="button"
                                            class="text-xs text-primary underline underline-offset-4"
                                            @click="moveSingleToUnsubscribed(attendee.unsubscribe_url)"
                                        >
                                            Move to Unsubscribed
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="attendees.subscribed.length === 0">
                                    <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">No registered attendees yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="overflow-hidden rounded-md border">
                        <div class="flex flex-wrap items-center gap-2 border-b bg-muted/20 px-3 py-2">
                            <Button type="button" variant="outline" class="h-8 px-2 text-xs" @click="toggleAllUnsubscribed">
                                {{
                                    selectedUnsubscribedIds.length === attendees.unsubscribed.length && attendees.unsubscribed.length > 0
                                        ? 'Uncheck All (shown)'
                                        : 'Check All (shown)'
                                }}
                            </Button>
                            <Button type="button" variant="outline" class="h-8 px-2 text-xs" @click="deleteBulkUnsubscribed">
                                Delete Selected
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                class="h-8 px-2 text-xs"
                                @click="exportAttendeesCsv(attendees.unsubscribed, 'unsubscribed-attendees.csv')"
                            >
                                Export Emails (shown)
                            </Button>
                        </div>
                        <table class="w-full text-sm">
                            <thead class="bg-muted/40 text-left">
                                <tr>
                                    <th class="px-3 py-2">Select</th>
                                    <th class="px-3 py-2">Name</th>
                                    <th class="px-3 py-2">Email</th>
                                    <th class="px-3 py-2">Unsubscribed At</th>
                                    <th class="px-3 py-2">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="attendee in attendees.unsubscribed" :key="attendee.id" class="border-t">
                                    <td class="px-3 py-2">
                                        <input
                                            :checked="selectedUnsubscribedIds.includes(attendee.id)"
                                            type="checkbox"
                                            class="h-4 w-4"
                                            @change="toggleUnsubscribed(attendee.id)"
                                        />
                                    </td>
                                    <td class="px-3 py-2">{{ attendee.name }}</td>
                                    <td class="px-3 py-2">{{ attendee.email }}</td>
                                    <td class="px-3 py-2 text-xs text-muted-foreground">{{ attendee.unsubscribed_at ?? '-' }}</td>
                                    <td class="px-3 py-2">
                                        <button
                                            v-if="attendee.delete_url"
                                            type="button"
                                            class="text-xs text-destructive underline underline-offset-4"
                                            @click="deleteSingleUnsubscribed(attendee.delete_url)"
                                        >
                                            Delete Email
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="attendees.unsubscribed.length === 0">
                                    <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">No unsubscribe logs yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
            </div>

            <Dialog :open="apolloModalOpen" @update:open="apolloModalOpen = $event">
                <DialogContent class="sm:max-w-2xl" @interact-outside.prevent @escape-key-down.prevent>
                    <DialogHeader>
                        <DialogTitle>Fetch Emails for This Webinar</DialogTitle>
                        <DialogDescription>
                            Select filters. Matching contacts will be auto-registered and invitation emails queued.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-2 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label>Max leads to fetch (limit {{ apolloMaxFetch }})</Label>
                            <Input v-model.number="apolloFetchForm.count" type="number" min="1" :max="apolloMaxFetch" />
                        </div>

                        <div class="space-y-1.5">
                            <Label>Keyword (optional)</Label>
                            <Input v-model="apolloFetchForm.keyword" type="text" placeholder="webinar, coaching, automation" />
                        </div>

                        <div class="space-y-1.5">
                            <Label>Job Title *</Label>
                            <select v-model="selectedApolloJobTitle" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                <option v-for="option in apolloJobTitleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <Input v-if="selectedApolloJobTitle === '__custom__'" v-model="customApolloJobTitle" type="text" placeholder="Custom job title" />
                        </div>

                        <div class="space-y-1.5">
                            <Label>Industry *</Label>
                            <select v-model="selectedApolloIndustry" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                <option v-for="option in apolloIndustryOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <Input v-if="selectedApolloIndustry === '__custom__'" v-model="customApolloIndustry" type="text" placeholder="Custom industry" />
                        </div>

                        <div class="space-y-1.5">
                            <Label>Location *</Label>
                            <select v-model="selectedApolloLocation" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                <option v-for="option in apolloLocationOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <Input v-if="selectedApolloLocation === '__custom__'" v-model="customApolloLocation" type="text" placeholder="Custom location" />
                        </div>

                        <div class="space-y-1.5">
                            <Label>Company Size *</Label>
                            <select v-model="selectedApolloCompanySize" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                <option v-for="option in apolloCompanySizeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <Input v-if="selectedApolloCompanySize === '__custom__'" v-model="customApolloCompanySize" type="text" placeholder="Custom size range" />
                        </div>
                    </div>

                    <InputError :message="apolloFetchErrorMessage" />

                    <div class="rounded-md border border-border/70 bg-muted/20 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-muted-foreground">Preview sampled leads before importing.</p>
                            <Button type="button" variant="outline" class="h-8 text-xs" :disabled="apolloPreviewLoading || !apolloRequiredFiltersComplete" @click="void previewApolloFetch()">
                                <Icon v-if="apolloPreviewLoading" icon="svg-spinners:3-dots-fade" class="mr-1 size-4" />
                                Preview Leads
                            </Button>
                        </div>

                        <p v-if="apolloPreviewMessage" class="mt-2 text-xs text-muted-foreground">
                            {{ apolloPreviewMessage }}
                        </p>
                        <p v-if="!apolloPreviewHasRun" class="mt-1 text-xs text-amber-600">
                            Preview is optional. You can still Fetch and Register directly.
                        </p>

                        <div v-if="apolloPreviewRows.length > 0" class="mt-3 overflow-hidden rounded-md border">
                            <table class="w-full text-xs">
                                <thead class="bg-muted/40 text-left">
                                    <tr>
                                        <th class="px-3 py-2">Name</th>
                                        <th class="px-3 py-2">Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, index) in apolloPreviewRows" :key="`apollo-preview-${index}`" class="border-t">
                                        <td class="px-3 py-2">{{ row.name }}</td>
                                        <td class="px-3 py-2">{{ row.email }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <DialogFooter>
                        <p class="mr-auto text-xs text-muted-foreground">
                            Estimated final fetch count: {{ apolloEstimatedFinalCount }}
                        </p>
                        <Button type="button" variant="ghost" @click="apolloModalOpen = false">Cancel</Button>
                        <Button type="button" :disabled="apolloFetchForm.processing || !apolloRequiredFiltersComplete" @click="submitApolloFetch">
                            <Icon v-if="apolloFetchForm.processing" icon="svg-spinners:3-dots-fade" class="mr-1 size-4" />
                            Fetch and Register
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <div v-if="activeStep === 4" class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:chat-round-dots-bold-duotone" class="size-5 text-violet-500" />
                    <h3 class="text-base font-semibold text-foreground">Chat and Automation</h3>
                    <span class="text-xs text-muted-foreground">Configure scheduled messages and viewer settings</span>
                </div>
                <div class="grid gap-5 p-5">
                <p class="text-sm text-muted-foreground">
                    Configure scheduled chat prompts and host responses in the upcoming admin chat module.
                </p>
                <div class="flex items-center gap-3 rounded-md border p-3">
                    <button
                        id="show_fake_viewers"
                        type="button"
                        role="switch"
                        :aria-checked="form.playback_settings.show_fake_viewers"
                        class="relative inline-flex h-5 w-9 items-center rounded-full transition"
                        :class="form.playback_settings.show_fake_viewers ? 'bg-primary' : 'bg-muted'"
                        @click="form.playback_settings.show_fake_viewers = !form.playback_settings.show_fake_viewers"
                    >
                        <span
                            class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                            :class="form.playback_settings.show_fake_viewers ? 'translate-x-4' : 'translate-x-1'"
                        />
                    </button>
                    <Label for="show_fake_viewers">Enable fake live viewer count</Label>
                </div>
                </div>
            </div>

            <div v-if="activeStep === 5" class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:tag-price-bold-duotone" class="size-5 text-orange-500" />
                    <h3 class="text-base font-semibold text-foreground">Offers</h3>
                    <span class="text-xs text-muted-foreground">Set up timed offers and redirect CTAs</span>
                </div>
                <div class="grid gap-5 p-5">
                <p class="text-sm text-muted-foreground">
                    Configure timed offers. Example: set delay to 600 seconds and the offer is sent at 10:00 in attendee chat.
                </p>
                <div class="grid gap-3 rounded-md border p-3">
                    <div class="flex items-center gap-3">
                        <button
                            id="redirect_enabled"
                            type="button"
                            role="switch"
                            :aria-checked="form.playback_settings.redirect_enabled"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition"
                            :class="form.playback_settings.redirect_enabled ? 'bg-primary' : 'bg-muted'"
                            @click="form.playback_settings.redirect_enabled = !form.playback_settings.redirect_enabled"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                                :class="form.playback_settings.redirect_enabled ? 'translate-x-4' : 'translate-x-1'"
                            />
                        </button>
                        <Label for="redirect_enabled">Enable redirect after webinar video ends</Label>
                    </div>
                    <div v-if="form.playback_settings.redirect_enabled" class="grid gap-2">
                        <Label for="redirect_url">{{ markRequired('Redirect URL') }}</Label>
                        <Input
                            id="redirect_url"
                            v-model="form.playback_settings.redirect_url"
                            placeholder="https://your-offer-page.com"
                            type="url"
                        />
                        <p class="text-xs text-muted-foreground">
                            If enabled, attendees are redirected to this URL immediately after the webinar ends.
                        </p>
                        <InputError :message="form.errors['playback_settings.redirect_url']" />
                    </div>
                </div>

                <!-- Exit Intent Popup -->
                <div class="grid gap-3 rounded-md border p-3">
                    <div class="flex items-start gap-3">
                        <button
                            id="exit_popup_enabled"
                            type="button"
                            role="switch"
                            :aria-checked="form.playback_settings.exit_popup_enabled"
                            class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full transition"
                            :class="form.playback_settings.exit_popup_enabled ? 'bg-primary' : 'bg-muted'"
                            @click="form.playback_settings.exit_popup_enabled = !form.playback_settings.exit_popup_enabled"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                                :class="form.playback_settings.exit_popup_enabled ? 'translate-x-4' : 'translate-x-1'"
                            />
                        </button>
                        <div>
                            <Label for="exit_popup_enabled" class="text-sm font-medium leading-none">Enable exit-intent popup</Label>
                            <p class="mt-1 text-xs text-muted-foreground">Show a modal with a CTA when a viewer tries to leave the webinar page.</p>
                        </div>
                    </div>

                    <div v-if="form.playback_settings.exit_popup_enabled" class="mt-1 grid gap-4 border-t border-border/50 pt-4">
                        <div class="grid gap-2">
                            <Label for="exit_popup_heading">Popup Heading</Label>
                            <Input
                                id="exit_popup_heading"
                                v-model="form.playback_settings.exit_popup_heading"
                                placeholder="Wait — don't miss out!"
                                maxlength="100"
                            />
                            <p class="text-xs text-muted-foreground">The bold headline shown at the top of the exit popup.</p>
                            <InputError :message="form.errors['playback_settings.exit_popup_heading']" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="exit_popup_body">Popup Message</Label>
                            <RichTextEditor
                                v-model="form.playback_settings.exit_popup_body"
                                placeholder="You're about to miss the best part. Grab this offer before it's gone…"
                                :max-plain-text-length="EXIT_POPUP_BODY_MAX_CHARS"
                            />
                            <div class="flex items-center justify-between text-xs text-muted-foreground">
                                <p>Supporting text shown below the heading.</p>
                                <p :class="exitPopupBodyTextCount > EXIT_POPUP_BODY_MAX_CHARS ? 'text-destructive font-semibold' : ''">
                                    {{ exitPopupBodyTextCount }} / {{ EXIT_POPUP_BODY_MAX_CHARS }}
                                </p>
                            </div>
                            <InputError :message="form.errors['playback_settings.exit_popup_body']" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="exit_popup_cta_text">Button Label *</Label>
                            <Input
                                id="exit_popup_cta_text"
                                v-model="form.playback_settings.exit_popup_cta_text"
                                placeholder="Get the Offer"
                                maxlength="50"
                            />
                            <InputError :message="form.errors['playback_settings.exit_popup_cta_text']" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="exit_popup_cta_url">{{ markRequired('Button URL') }}</Label>
                            <Input
                                id="exit_popup_cta_url"
                                v-model="form.playback_settings.exit_popup_cta_url"
                                placeholder="https://your-offer-page.com"
                                type="url"
                            />
                            <InputError :message="form.errors['playback_settings.exit_popup_cta_url']" />
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div
                        v-for="(offer, index) in form.offers"
                        :key="`offer-${index}`"
                        class="grid gap-3 rounded-md border p-3"
                    >
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold">Offer {{ index + 1 }}</p>
                            <button
                                type="button"
                                class="text-xs text-destructive underline underline-offset-4"
                                @click="removeOffer(index)"
                            >
                                Remove
                            </button>
                        </div>

                        <div class="grid gap-2">
                            <Label>Offer Title *</Label>
                            <Input v-model="offer.title" placeholder="Buy My Course" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Description</Label>
                            <textarea
                                v-model="offer.description"
                                rows="2"
                                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                            />
                        </div>
                        <div class="grid gap-2 md:grid-cols-3 md:gap-3">
                            <div class="grid gap-2">
                                <Label>Delay Seconds *</Label>
                                <Input v-model.number="offer.trigger_second" type="number" min="1" />
                            </div>
                            <div class="grid gap-2">
                                <Label>Button Text</Label>
                                <Input v-model="offer.button_text" placeholder="Claim Offer" />
                            </div>
                            <div class="grid gap-2">
                                <Label>Display</Label>
                                <select
                                    v-model="offer.display_mode"
                                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                >
                                    <option value="chat">Chat Message</option>
                                    <option value="popup">Popup</option>
                                    <option value="pinned">Pinned</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label>Button URL *</Label>
                            <Input v-model="offer.button_url" placeholder="https://checkout.com" />
                            <p v-if="offersUrlValidationErrors[index]" class="text-xs text-destructive">
                                {{ offersUrlValidationErrors[index] }}
                            </p>
                        </div>
                    </div>

                    <Button type="button" variant="outline" @click="addOffer">
                        Add Offer
                    </Button>
                </div>
                </div>
            </div>

            <!-- ── Step 6: AI Assistant ── -->
            <div v-if="activeStep === 6" class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
                <!-- Header -->
                <div class="flex items-center gap-3 border-b border-border/50 bg-gradient-to-r from-violet-50/60 to-violet-50/40 px-5 py-4 dark:from-violet-950/20 dark:to-violet-950/10">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-violet-100 dark:bg-violet-950/50">
                        <Icon icon="solar:stars-bold-duotone" class="size-5 text-violet-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-foreground leading-tight">AI Chat Assistant</h3>
                        <p class="text-xs text-muted-foreground">Train a private knowledge base so an AI can answer attendee questions in real time.</p>
                    </div>
                    <!-- Enable toggle in header -->
                    <div class="ml-auto flex items-center gap-2.5">
                        <span class="hidden text-xs font-medium text-muted-foreground sm:block">
                            {{ form.ai_settings.enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                        <button
                            id="ai_enabled"
                            type="button"
                            role="switch"
                            :aria-checked="form.ai_settings.enabled"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            :class="form.ai_settings.enabled ? 'bg-violet-500' : 'bg-muted'"
                            @click="form.ai_settings.enabled = !form.ai_settings.enabled"
                        >
                            <span
                                class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform"
                                :class="form.ai_settings.enabled ? 'translate-x-5' : 'translate-x-0.5'"
                            />
                        </button>
                    </div>
                </div>

                <div class="grid gap-6 p-5">

                    <!-- Disabled state notice -->
                    <div v-if="!form.ai_settings.enabled" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border/60 bg-muted/20 py-10 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                            <Icon icon="solar:stars-bold-duotone" class="size-6 text-muted-foreground" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-foreground">AI Assistant is off</p>
                            <p class="mt-1 text-xs text-muted-foreground">Enable it above to configure your knowledge base and let the AI answer attendee questions.</p>
                        </div>
                    </div>

                    <template v-if="form.ai_settings.enabled">

                        <!-- ── Assistant settings row ── -->
                        <div class="grid gap-4 rounded-xl border border-violet-100 bg-violet-50/40 p-4 dark:border-violet-900/30 dark:bg-violet-950/10 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="ai_assistant_name" class="text-xs font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-400">Assistant Name</Label>
                                <Input
                                    id="ai_assistant_name"
                                    v-model="form.ai_settings.assistant_name"
                                    placeholder="Webinar AI Helper"
                                    maxlength="80"
                                    class="bg-white dark:bg-background"
                                />
                                <p class="text-[11px] text-muted-foreground">How the assistant identifies itself in chat.</p>
                                <InputError :message="form.errors['ai_settings.assistant_name']" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label class="text-xs font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-400">Auto-Reply</Label>
                                <button
                                    id="ai_auto_reply"
                                    type="button"
                                    role="switch"
                                    :aria-checked="form.ai_settings.auto_reply_enabled"
                                    class="flex w-full cursor-pointer items-center gap-3 rounded-lg border bg-white px-4 py-3 text-left transition hover:bg-muted/30 dark:bg-background"
                                    :class="form.ai_settings.auto_reply_enabled ? 'border-violet-300 dark:border-violet-700' : 'border-border'"
                                    @click="form.ai_settings.auto_reply_enabled = !form.ai_settings.auto_reply_enabled"
                                >
                                    <span
                                        class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                        :class="form.ai_settings.auto_reply_enabled ? 'bg-violet-500' : 'bg-muted'"
                                    >
                                        <span
                                            class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                            :class="form.ai_settings.auto_reply_enabled ? 'translate-x-4' : 'translate-x-0.5'"
                                        />
                                    </span>
                                    <span class="text-sm text-foreground">Answer attendee questions automatically</span>
                                </button>
                                <p class="text-[11px] text-muted-foreground">When on, the AI replies to attendees without host action.</p>
                            </div>
                        </div>

                        <!-- ── Knowledge base capacity bar ── -->
                        <div class="grid gap-2">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Knowledge Base Capacity</p>
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-[11px] font-bold"
                                    :class="aiSourceLimitReached ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'"
                                >
                                    {{ aiSourceCount }} / {{ AI_SOURCE_LIMIT }} sources
                                </span>
                            </div>
                            <!-- Track with slot pips -->
                            <div class="flex items-center gap-1.5">
                                <div
                                    v-for="slot in AI_SOURCE_LIMIT"
                                    :key="`slot-${slot}`"
                                    class="h-2.5 flex-1 rounded-full transition-colors"
                                    :class="slot <= aiSourceCount
                                        ? (aiSourceLimitReached ? 'bg-rose-500' : 'bg-violet-500')
                                        : 'bg-muted'"
                                />
                            </div>
                            <p class="text-[11px]" :class="aiSourceLimitReached ? 'text-rose-600' : 'text-muted-foreground'">
                                <span v-if="aiSourceLimitReached">
                                    <Icon icon="solar:danger-bold-duotone" class="mr-1 inline-block size-3.5 align-text-bottom" />
                                    Limit reached — delete a source below to free up a slot and add new content.
                                </span>
                                <span v-else>
                                    {{ aiSourceSlotsRemaining }} slot{{ aiSourceSlotsRemaining === 1 ? '' : 's' }} remaining. A focused knowledge base gives more accurate answers.
                                </span>
                            </p>
                        </div>

                        <!-- ── Add source section ── -->
                        <div
                            class="rounded-xl border transition-colors"
                            :class="aiSourceLimitReached ? 'border-border/40 bg-muted/20 opacity-60 pointer-events-none' : 'border-border bg-card'"
                        >
                            <div class="border-b border-border/50 px-4 py-3">
                                <p class="text-sm font-semibold text-foreground">Add a Knowledge Source</p>
                                <p class="text-xs text-muted-foreground">Choose how you want to feed content to the AI.</p>
                            </div>

                            <!-- Source type selector cards -->
                            <div class="grid grid-cols-3 gap-0 divide-x divide-border/50">
                                <button
                                    type="button"
                                    class="flex flex-col items-center gap-2 px-3 py-5 text-center transition"
                                    :class="aiSourceMode === 'url'
                                        ? 'bg-violet-50 dark:bg-violet-950/20'
                                        : 'hover:bg-muted/40'"
                                    @click="aiSourceMode = 'url'"
                                >
                                    <span
                                        class="flex h-10 w-10 items-center justify-center rounded-xl transition"
                                        :class="aiSourceMode === 'url' ? 'bg-violet-100 dark:bg-violet-900/40' : 'bg-muted'"
                                    >
                                        <Icon icon="solar:global-bold-duotone" class="size-5" :class="aiSourceMode === 'url' ? 'text-violet-600' : 'text-muted-foreground'" />
                                    </span>
                                    <span class="text-xs font-semibold leading-tight" :class="aiSourceMode === 'url' ? 'text-violet-700 dark:text-violet-300' : 'text-foreground'">Website URL</span>
                                    <span class="hidden text-[10px] leading-tight text-muted-foreground sm:block">Scrape page content</span>
                                    <span v-if="aiSourceMode === 'url'" class="h-0.5 w-6 rounded-full bg-violet-500" />
                                </button>
                                <button
                                    type="button"
                                    class="flex flex-col items-center gap-2 px-3 py-5 text-center transition"
                                    :class="aiSourceMode === 'transcript'
                                        ? 'bg-violet-50 dark:bg-violet-950/20'
                                        : 'hover:bg-muted/40'"
                                    @click="aiSourceMode = 'transcript'"
                                >
                                    <span
                                        class="flex h-10 w-10 items-center justify-center rounded-xl transition"
                                        :class="aiSourceMode === 'transcript' ? 'bg-violet-100 dark:bg-violet-900/40' : 'bg-muted'"
                                    >
                                        <Icon icon="solar:subtitles-bold-duotone" class="size-5" :class="aiSourceMode === 'transcript' ? 'text-violet-600' : 'text-muted-foreground'" />
                                    </span>
                                    <span class="text-xs font-semibold leading-tight" :class="aiSourceMode === 'transcript' ? 'text-violet-700 dark:text-violet-300' : 'text-foreground'">Transcript</span>
                                    <span class="hidden text-[10px] leading-tight text-muted-foreground sm:block">Paste video text</span>
                                    <span v-if="aiSourceMode === 'transcript'" class="h-0.5 w-6 rounded-full bg-violet-500" />
                                </button>
                                <button
                                    type="button"
                                    class="flex flex-col items-center gap-2 px-3 py-5 text-center transition"
                                    :class="aiSourceMode === 'file'
                                        ? 'bg-violet-50 dark:bg-violet-950/20'
                                        : 'hover:bg-muted/40'"
                                    @click="aiSourceMode = 'file'"
                                >
                                    <span
                                        class="flex h-10 w-10 items-center justify-center rounded-xl transition"
                                        :class="aiSourceMode === 'file' ? 'bg-violet-100 dark:bg-violet-900/40' : 'bg-muted'"
                                    >
                                        <Icon icon="solar:file-bold-duotone" class="size-5" :class="aiSourceMode === 'file' ? 'text-violet-600' : 'text-muted-foreground'" />
                                    </span>
                                    <span class="text-xs font-semibold leading-tight" :class="aiSourceMode === 'file' ? 'text-violet-700 dark:text-violet-300' : 'text-foreground'">Upload File</span>
                                    <span class="hidden text-[10px] leading-tight text-muted-foreground sm:block">PDF, DOCX, CSV…</span>
                                    <span v-if="aiSourceMode === 'file'" class="h-0.5 w-6 rounded-full bg-violet-500" />
                                </button>
                            </div>

                            <!-- URL form -->
                            <div v-if="aiSourceMode === 'url'" class="grid gap-4 border-t border-border/50 p-4">
                                <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                    <div class="grid gap-1.5">
                                        <Label class="text-xs">Label (optional)</Label>
                                        <Input v-model="aiUrlForm.title" placeholder="e.g. Offer Page" />
                                    </div>
                                    <div class="grid gap-1.5">
                                        <Label class="text-xs">{{ markRequired('Page URL') }}</Label>
                                        <Input v-model="aiUrlForm.url" type="url" placeholder="https://example.com/page" />
                                        <p v-if="aiUrlValidationError" class="text-xs text-destructive">
                                            {{ aiUrlValidationError }}
                                        </p>
                                    </div>
                                    <Button type="button" class="bg-violet-600 text-white hover:bg-violet-700" @click="submitAiUrlSource">
                                        <Icon icon="solar:add-circle-bold-duotone" class="mr-1.5 size-4" />
                                        Add URL
                                    </Button>
                                </div>
                                <p class="text-[11px] text-muted-foreground">The page will be scraped and its readable text extracted for AI training.</p>
                            </div>

                            <!-- Transcript form -->
                            <div v-if="aiSourceMode === 'transcript'" class="grid gap-4 border-t border-border/50 p-4">
                                <div class="grid gap-1.5">
                                    <Label class="text-xs">Label (optional)</Label>
                                    <Input v-model="aiTranscriptForm.title" placeholder="e.g. Main Webinar Transcript" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label class="text-xs">{{ markRequired('Transcript text') }}</Label>
                                    <textarea
                                        v-model="aiTranscriptForm.transcript"
                                        rows="6"
                                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm leading-relaxed transition focus:outline-none focus:ring-2 focus:ring-violet-400"
                                        placeholder="Paste your full video transcript here…"
                                    />
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <Button type="button" class="bg-violet-600 text-white hover:bg-violet-700" @click="submitAiTranscriptSource">
                                        <Icon icon="solar:add-circle-bold-duotone" class="mr-1.5 size-4" />
                                        Add Transcript
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :disabled="aiVideoTranscriptGenerating"
                                        @click="void submitVideoTranscriptGeneration()"
                                    >
                                        <Icon
                                            :icon="aiVideoTranscriptGenerating ? 'svg-spinners:3-dots-fade' : 'solar:clapperboard-text-bold-duotone'"
                                            class="mr-1.5 size-4"
                                        />
                                        Generate from Video URL
                                    </Button>
                                </div>
                                <p class="text-[11px] text-muted-foreground">
                                    Uses the Video step URL, extracts audio with FFmpeg, splits into chunks, transcribes, then trains AI automatically.
                                </p>
                            </div>

                            <Dialog :open="aiVideoTranscriptModalOpen" @update:open="aiVideoTranscriptModalOpen = $event">
                                <DialogContent class="sm:max-w-lg">
                                    <DialogHeader>
                                        <DialogTitle>Video URL Required</DialogTitle>
                                        <DialogDescription>
                                            Add the webinar video URL to generate transcript automatically. This URL will also be saved in the Video step.
                                        </DialogDescription>
                                    </DialogHeader>

                                    <div class="grid gap-2 py-1">
                                        <Label for="ai_video_transcript_url">Video URL</Label>
                                        <Input
                                            id="ai_video_transcript_url"
                                            v-model="aiVideoTranscriptUrlInput"
                                            placeholder="https://example.com/video.mp4"
                                            type="url"
                                        />
                                    </div>

                                    <DialogFooter>
                                        <Button type="button" variant="ghost" :disabled="aiVideoTranscriptGenerating" @click="aiVideoTranscriptModalOpen = false">
                                            Cancel
                                        </Button>
                                        <Button type="button" :disabled="aiVideoTranscriptGenerating" @click="confirmVideoTranscriptGenerationFromModal">
                                            <Icon
                                                :icon="aiVideoTranscriptGenerating ? 'svg-spinners:3-dots-fade' : 'solar:subtitles-bold-duotone'"
                                                class="mr-1.5 size-4"
                                            />
                                            Generate Transcript
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>

                            <div v-if="aiSourceMode === 'file'" class="grid gap-4 border-t border-border/50 p-4">
                                <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                                    <div class="grid gap-1.5">
                                        <Label class="text-xs">Label (optional)</Label>
                                        <Input v-model="aiFileForm.title" placeholder="e.g. FAQ Document" />
                                    </div>
                                    <div class="grid gap-1.5">
                                        <Label class="text-xs">{{ markRequired('File') }}</Label>
                                        <label class="flex h-9 cursor-pointer items-center gap-2 rounded-md border border-dashed border-border bg-muted/30 px-3 text-sm text-muted-foreground transition hover:bg-muted/50">
                                            <Icon icon="solar:upload-minimalistic-bold-duotone" class="size-4 shrink-0" />
                                            <span class="truncate max-w-[140px]">{{ aiFileForm.file ? aiFileForm.file.name : 'Choose file…' }}</span>
                                            <input
                                                type="file"
                                                accept=".pdf,.txt,.md,.csv,.xlsx,.xls,.docx"
                                                class="sr-only"
                                                @change="onAiFileSelected"
                                            />
                                        </label>
                                    </div>
                                    <Button type="button" class="bg-violet-600 text-white hover:bg-violet-700" @click="submitAiFileSource">
                                        <Icon icon="solar:add-circle-bold-duotone" class="mr-1.5 size-4" />
                                        Upload
                                    </Button>
                                </div>
                                <p class="text-[11px] text-muted-foreground">Supported: PDF, DOCX, TXT, MD, CSV, XLSX, XLS — max 20 MB</p>
                            </div>
                        </div>

                        <!-- Inline confirm banner for AI actions -->
                        <div
                            v-if="confirmToastMessage && confirmSection === 'ai'"
                            class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-800/50 dark:bg-rose-950/30"
                        >
                            <Icon icon="solar:danger-bold-duotone" class="mt-0.5 size-4 shrink-0 text-rose-500" />
                            <div class="flex-1">
                                <p class="text-sm font-medium text-rose-800 dark:text-rose-300">{{ confirmToastMessage }}</p>
                                <div class="mt-2 flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex h-7 items-center rounded-lg bg-rose-600 px-3 text-xs font-semibold text-white hover:bg-rose-700"
                                        @click="continueConfirmToast"
                                    >
                                        Confirm
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-7 items-center rounded-lg border border-rose-200 bg-white px-3 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:bg-transparent dark:text-rose-300"
                                        @click="cancelConfirmToast"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- ── Indexed sources ── -->
                        <div class="grid gap-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-foreground">Indexed Sources</p>
                                <div class="flex items-center gap-2">
                                    <button
                                        v-if="aiSourcesList.length > 1"
                                        type="button"
                                        class="text-[11px] text-muted-foreground underline underline-offset-2 hover:text-foreground"
                                        @click="toggleAllAiSourcesOnPage"
                                    >
                                        {{ aiSourcesList.every((s) => selectedAiSourceIds.includes(s.id)) ? 'Uncheck all' : 'Select all' }}
                                    </button>
                                    <button
                                        v-if="selectedAiSourceIds.length > 0"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-medium text-rose-700 transition hover:bg-rose-100"
                                        @click="bulkDeleteAiSources"
                                    >
                                        <Icon icon="solar:trash-bin-minimalistic-bold-duotone" class="size-3.5" />
                                        Delete {{ selectedAiSourceIds.length }} selected
                                    </button>
                                </div>
                            </div>

                            <!-- Loading -->
                            <div v-if="aiSourcesLoading" class="flex items-center justify-center gap-2 rounded-xl border border-dashed border-border/60 py-10 text-sm text-muted-foreground">
                                <Icon icon="solar:refresh-bold-duotone" class="size-4 animate-spin" />
                                Loading sources…
                            </div>

                            <!-- Empty state -->
                            <div v-else-if="aiSourcesList.length === 0" class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border/60 py-10 text-center">
                                <Icon icon="solar:database-bold-duotone" class="size-8 text-muted-foreground/40" />
                                <p class="text-sm font-medium text-muted-foreground">No sources indexed yet</p>
                                <p class="text-xs text-muted-foreground/70">Add a URL, transcript, or file above to start training the AI.</p>
                            </div>

                            <!-- Source cards grid (max 3) -->
                            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <div
                                    v-for="source in aiSourcesList"
                                    :key="`ai-source-${source.id}`"
                                    class="group relative flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-sm transition"
                                    :class="selectedAiSourceIds.includes(source.id)
                                        ? 'border-violet-300 ring-1 ring-violet-300 dark:border-violet-700 dark:ring-violet-700'
                                        : 'border-border hover:border-violet-200'"
                                >
                                    <!-- Checkbox top-right -->
                                    <input
                                        :checked="selectedAiSourceIds.includes(source.id)"
                                        type="checkbox"
                                        class="absolute right-3 top-3 h-4 w-4 cursor-pointer accent-violet-500"
                                        @change="toggleAiSource(source.id)"
                                    />

                                    <!-- Type icon + status -->
                                    <div class="flex items-start gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                            :class="source.type === 'url' ? 'bg-sky-100 dark:bg-sky-950/40' : source.type === 'video_transcript' ? 'bg-violet-100 dark:bg-violet-950/40' : 'bg-amber-100 dark:bg-amber-950/40'"
                                        >
                                            <Icon
                                                :icon="source.type === 'url' ? 'solar:global-bold-duotone' : source.type === 'video_transcript' ? 'solar:subtitles-bold-duotone' : 'solar:file-bold-duotone'"
                                                class="size-4.5"
                                                :class="source.type === 'url' ? 'text-sky-600' : source.type === 'video_transcript' ? 'text-violet-600' : 'text-amber-600'"
                                            />
                                        </span>
                                        <div class="min-w-0 flex-1 pr-5">
                                            <p class="truncate text-sm font-semibold text-foreground">{{ source.title || 'Untitled Source' }}</p>
                                            <p class="mt-0.5 truncate text-[11px] capitalize text-muted-foreground">
                                                {{ source.type === 'video_transcript' ? 'Transcript' : source.type }}
                                            </p>
                                        </div>
                                    </div>

                                    <!-- URL preview if applicable -->
                                    <p v-if="source.source_url" class="truncate rounded bg-muted/50 px-2 py-1 text-[11px] text-muted-foreground">
                                        {{ source.source_url }}
                                    </p>

                                    <!-- Status row -->
                                    <div class="flex items-center justify-between gap-2">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                            :class="{
                                                'bg-emerald-100 text-emerald-700': source.status === 'ready',
                                                'bg-rose-100 text-rose-700': source.status === 'failed',
                                                'bg-amber-100 text-amber-700': source.status === 'processing',
                                                'bg-muted text-muted-foreground': source.status === 'queued',
                                            }"
                                        >
                                            <Icon
                                                :icon="source.status === 'ready' ? 'solar:check-circle-bold' : source.status === 'failed' ? 'solar:close-circle-bold' : 'solar:clock-circle-bold'"
                                                class="size-3"
                                            />
                                            {{ source.status }}
                                        </span>
                                        <span class="text-[11px] text-muted-foreground">{{ source.chunk_count }} chunk{{ source.chunk_count === 1 ? '' : 's' }}</span>
                                    </div>

                                    <!-- Error message -->
                                    <p v-if="source.error_message" class="rounded bg-rose-50 px-2 py-1.5 text-[11px] text-rose-700 dark:bg-rose-950/30">
                                        <Icon icon="solar:danger-bold-duotone" class="mr-1 inline-block size-3" />
                                        {{ source.error_message }}
                                    </p>

                                    <!-- Actions -->
                                    <div class="mt-auto flex items-center gap-2 border-t border-border/50 pt-3">
                                        <button
                                            type="button"
                                            class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-[12px] font-medium text-foreground transition hover:bg-muted"
                                            @click="openSourcePreview(source)"
                                        >
                                            <Icon icon="solar:eye-bold-duotone" class="size-3.5 text-violet-500" />
                                            Preview
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-[12px] font-medium text-rose-700 transition hover:bg-rose-100 dark:bg-rose-950/20 dark:text-rose-400"
                                            @click="deleteAiSource(source)"
                                        >
                                            <Icon icon="solar:trash-bin-minimalistic-bold-duotone" class="size-3.5" />
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </template>
                </div>
            </div>

            <!-- ── Chunk preview modal ── -->
            <div
                v-if="previewOpen"
                class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-4 backdrop-blur-sm sm:items-center h-screen"
                @click.self="previewOpen = false"
            >
                <div class="flex w-full max-w-2xl flex-col rounded-2xl border border-border/60 bg-card shadow-2xl" style="max-height: 85vh;">
                    <!-- Modal header -->
                    <div class="flex shrink-0 items-center gap-3 border-b border-border/50 px-5 py-4">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-950/40">
                            <Icon icon="solar:eye-bold-duotone" class="size-4 text-violet-600" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-foreground">{{ previewSource?.title || 'Source Preview' }}</p>
                            <p class="text-[11px] text-muted-foreground">
                                {{ previewChunksMeta.total }} chunk{{ previewChunksMeta.total === 1 ? '' : 's' }} extracted
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-7 w-7 items-center justify-center rounded-lg border border-border text-muted-foreground transition hover:bg-muted"
                            @click="previewOpen = false"
                        >
                            <Icon icon="solar:close-bold"  class="size-4 text-black" />
                            <!-- <Icon icon="solar:close-bold" class="size-4" /> -->
                        </button>
                    </div>

                    <!-- Chunks list -->
                    <div class="flex-1 overflow-y-auto px-5 py-4">
                        <div v-if="previewChunksLoading" class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                            <Icon icon="solar:refresh-bold-duotone" class="size-4 animate-spin" />
                            Loading chunks…
                        </div>
                        <div v-else class="space-y-3">
                            <div
                                v-for="chunk in previewChunks"
                                :key="`chunk-${chunk.id}`"
                                class="rounded-lg border border-border/60 bg-muted/20 p-3"
                            >
                                <p class="mb-1.5 flex items-center gap-1 text-[11px] font-semibold text-violet-600">
                                    <Icon icon="solar:layers-minimalistic-bold-duotone" class="size-3.5" />
                                    Chunk {{ chunk.chunk_index + 1 }}
                                </p>
                                <p class="text-[12px] leading-relaxed text-foreground/80 whitespace-pre-wrap">{{ chunk.content }}</p>
                            </div>
                            <div v-if="previewChunks.length === 0" class="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground">
                                <Icon icon="solar:document-bold-duotone" class="size-8 opacity-30" />
                                No chunks found for this source yet.
                            </div>
                        </div>
                    </div>

                    <!-- Chunk pagination -->
                    <div v-if="previewChunksMeta.last_page > 1" class="flex shrink-0 items-center justify-between border-t border-border/50 px-5 py-3">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-foreground transition hover:bg-muted disabled:opacity-40"
                            :disabled="previewChunksMeta.current_page <= 1"
                            @click="void loadPreviewChunks(previewChunksMeta.current_page - 1)"
                        >
                            <Icon icon="solar:arrow-left-bold" class="size-3.5" />
                            Previous
                        </button>
                        <span class="text-[11px] text-muted-foreground">Page {{ previewChunksMeta.current_page }} of {{ previewChunksMeta.last_page }}</span>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-foreground transition hover:bg-muted disabled:opacity-40"
                            :disabled="previewChunksMeta.current_page >= previewChunksMeta.last_page"
                            @click="void loadPreviewChunks(previewChunksMeta.current_page + 1)"
                        >
                            Next
                            <Icon icon="solar:arrow-right-bold" class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="activeStep === 7" class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:bell-bing-bold-duotone" class="size-5 text-rose-500" />
                    <h3 class="text-base font-semibold text-foreground">Reminder and Notification</h3>
                    <span class="text-xs text-muted-foreground">Control confirmation, reminder, and follow-up emails</span>
                </div>
                <div class="grid gap-5 p-5">
                <p class="text-sm text-muted-foreground">
                    Confirmation sends on registration. Reminders and follow-ups run automatically for scheduled webinars.
                </p>
                <p class="text-xs text-muted-foreground">
                    Auto mode has no end time, so reminder/follow-up automation is skipped.
                </p>
                <div class="space-y-3">
                    <label class="flex items-center gap-3 rounded-md border p-3">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.email_settings.send_confirmation"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition"
                            :class="form.email_settings.send_confirmation ? 'bg-primary' : 'bg-muted'"
                            @click="form.email_settings.send_confirmation = !form.email_settings.send_confirmation"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                                :class="form.email_settings.send_confirmation ? 'translate-x-4' : 'translate-x-1'"
                            />
                        </button>
                        <span class="text-sm">Send registration confirmation</span>
                    </label>
                    <label class="flex items-center gap-3 rounded-md border p-3">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.email_settings.send_reminder"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition"
                            :class="form.email_settings.send_reminder ? 'bg-primary' : 'bg-muted'"
                            @click="form.email_settings.send_reminder = !form.email_settings.send_reminder"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                                :class="form.email_settings.send_reminder ? 'translate-x-4' : 'translate-x-1'"
                            />
                        </button>
                        <span class="text-sm">Send webinar reminder</span>
                    </label>
                    <label class="flex items-center gap-3 rounded-md border p-3">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.email_settings.send_follow_up"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition"
                            :class="form.email_settings.send_follow_up ? 'bg-primary' : 'bg-muted'"
                            @click="form.email_settings.send_follow_up = !form.email_settings.send_follow_up"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                                :class="form.email_settings.send_follow_up ? 'translate-x-4' : 'translate-x-1'"
                            />
                        </button>
                        <span class="text-sm">Send follow-up email</span>
                    </label>
                </div>
                </div>
            </div>

            <div v-if="activeStep === 8" class="rounded-2xl border border-border/60 bg-card shadow-sm overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:chart-2-bold-duotone" class="size-5 text-amber-500" />
                    <h3 class="text-base font-semibold text-foreground">Publish and Tracking</h3>
                    <span class="text-xs text-muted-foreground">Set viewer counts and publish your webinar</span>
                </div>
                <div class="grid gap-5 p-5">
                <div class="grid gap-2 md:grid-cols-2 md:gap-4">
                    <div class="grid gap-2">
                        <Label for="min_viewers">{{ markRequired('Min Viewers') }}</Label>
                        <Input id="min_viewers" v-model.number="form.min_viewers" type="number" min="0" required />
                        <InputError :message="form.errors.min_viewers" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="max_viewers">{{ markRequired('Max Viewers') }}</Label>
                        <Input id="max_viewers" v-model.number="form.max_viewers" type="number" min="0" required />
                        <InputError :message="form.errors.max_viewers" />
                    </div>
                </div>
                <label class="flex items-center gap-3 rounded-md border p-3">
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="form.is_published"
                        class="relative inline-flex h-5 w-9 items-center rounded-full transition"
                        :class="form.is_published ? 'bg-primary' : 'bg-muted'"
                        @click="form.is_published = !form.is_published"
                    >
                        <span
                            class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                            :class="form.is_published ? 'translate-x-4' : 'translate-x-1'"
                        />
                    </button>
                    <span class="text-sm">Publish webinar immediately</span>
                </label>
            </div>
            </div>

            <!-- Step navigation -->
            <div class="flex items-center justify-between gap-3 rounded-2xl border border-border/60 bg-card px-5 py-4 shadow-sm">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="activeStep === 0"
                    class="gap-1.5"
                    @click="previousStep"
                >
                    <Icon icon="solar:arrow-left-linear" class="size-4" />
                    Previous
                </Button>

                <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="hidden sm:inline">Step {{ activeStep + 1 }} of {{ steps.length }}</span>
                    <div class="flex gap-1 sm:ml-2">
                        <span
                            v-for="(_, i) in steps"
                            :key="i"
                            class="h-1.5 rounded-full transition-all"
                            :class="i === activeStep ? 'w-5 bg-primary' : i < activeStep ? 'w-1.5 bg-primary/40' : 'w-1.5 bg-border'"
                        />
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        v-if="activeStep < steps.length - 1"
                        type="button"
                        class="gap-1.5"
                        @click="nextStep"
                    >
                        Next Step
                        <Icon icon="solar:arrow-right-linear" class="size-4" />
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="gap-1.5"
                    >
                        <Icon icon="solar:check-circle-bold" class="size-4" />
                        {{ submitLabel }}
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
