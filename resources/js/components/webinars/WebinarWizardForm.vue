<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
        subscribed: Array<{ id: number; name: string; email: string; registered_at?: string | null; unsubscribe_url?: string }>;
        unsubscribed: Array<{ id: number; name: string; email: string; unsubscribed_at?: string | null; delete_url?: string }>;
    };
    attendeeImportUrl: string | null;
    attendeeActionUrls: {
        bulk_unsubscribe_url: string;
        bulk_delete_url: string;
    } | null;
    timezoneOptions: string[];
}>();

const steps = [
    'Basics',
    'Video',
    'Registration',
    'Attendees',
    'Chat and Automation',
    'Offers',
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

const markRequired = (label: string): string => `${label} *`;

const requiredChecks: Array<{ key: keyof WebinarFormData; label: string; step: number }> = [
    { key: 'title', label: 'Webinar Title', step: 0 },
    { key: 'host_name', label: 'Host Name', step: 0 },
    { key: 'scheduled_at', label: 'Webinar Date and Time', step: 0 },
    { key: 'scheduled_timezone', label: 'Time Zone', step: 0 },
    { key: 'video_source', label: 'Video Source', step: 1 },
    { key: 'video_url', label: 'Video URL', step: 1 },
    { key: 'min_viewers', label: 'Min Viewers', step: 7 },
    { key: 'max_viewers', label: 'Max Viewers', step: 7 },
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

const showConfirmToast = (message: string, action: () => void): void => {
    confirmToastMessage.value = message;
    confirmAction.value = action;
};

const cancelConfirmToast = (): void => {
    confirmToastMessage.value = null;
    confirmAction.value = null;
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
    });
};

const deleteSingleUnsubscribed = (url?: string): void => {
    if (!url) {
        return;
    }

    showConfirmToast('Delete this unsubscribed attendee email permanently?', () => {
        router.delete(url, { preserveScroll: true });
    });
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
    });
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
    });
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

    if (props.method === 'put') {
        form.put(props.actionUrl, { preserveScroll: true });
        return;
    }

    form.post(props.actionUrl, { preserveScroll: true });
};
</script>

<template>
    <div class="space-y-6">
        <div
            v-if="toastMessage"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            {{ toastMessage }}
        </div>

        <div
            v-if="confirmToastMessage"
            class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800"
        >
            <p>{{ confirmToastMessage }}</p>
            <div class="mt-2 flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-md border border-red-300 px-3 py-1 text-xs font-medium"
                    @click="continueConfirmToast"
                >
                    Continue
                </button>
                <button
                    type="button"
                    class="rounded-md border border-transparent px-3 py-1 text-xs"
                    @click="cancelConfirmToast"
                >
                    Cancel
                </button>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-card p-4">
            <p class="text-sm font-medium text-muted-foreground">
                Webinar Setup Steps
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    v-for="(step, index) in steps"
                    :key="step"
                    type="button"
                    class="rounded-md border px-3 py-1 text-xs transition"
                    :class="
                        index === activeStep
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-background text-foreground hover:bg-muted'
                    "
                    @click="activeStep = index"
                >
                    {{ index + 1 }}. {{ step }}
                </button>
            </div>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <div v-if="activeStep === 0" class="grid gap-4 rounded-lg border p-4">
                <h3 class="text-lg font-semibold">Basics</h3>
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
                        <Input id="host_name" v-model="form.host_name" required />
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
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="4"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
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

            <div v-if="activeStep === 1" class="grid gap-4 rounded-lg border p-4">
                <h3 class="text-lg font-semibold">Video</h3>
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

            <div v-if="activeStep === 2" class="grid gap-4 rounded-lg border p-4">
                <h3 class="text-lg font-semibold">Registration</h3>
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

            <div v-if="activeStep === 3" class="grid gap-4 rounded-lg border p-4">
                <h3 class="text-lg font-semibold">Attendees</h3>
                <p class="text-sm text-muted-foreground">
                    Upload CSV/XLSX/XLS files to register attendees and send invitation emails.
                </p>

                <div v-if="!attendeeImportUrl" class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                    Create the webinar first, then return to this tab to upload attendees.
                </div>

                <div v-else class="space-y-4">
                    <div class="grid gap-3 rounded-md border p-3">
                        <Label for="attendee_csv">Import File *</Label>
                        <Input id="attendee_csv" type="file" accept=".csv,.txt,.xlsx,.xls" @change="onAttendeeCsvSelected" />
                        <Button type="button"  :disabled="attendeeCsvForm.processing" @click="importAttendeesCsv">
                            Upload and Register
                        </Button>
                    </div>

                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-1 text-xs"
                            :class="attendeePanel === 'subscribed' ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background'"
                            @click="attendeePanel = 'subscribed'"
                        >
                            Registered ({{ attendees.subscribed.length }})
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-1 text-xs"
                            :class="attendeePanel === 'unsubscribed' ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background'"
                            @click="attendeePanel = 'unsubscribed'"
                        >
                            Unsubscribed ({{ attendees.unsubscribed.length }})
                        </button>
                    </div>

                    <div v-if="attendeePanel === 'subscribed'" class="overflow-hidden rounded-md border">
                        <div class="flex flex-wrap items-center gap-2 border-b bg-muted/20 px-3 py-2">
                            <Button type="button" variant="outline" class="h-8 px-2 text-xs" @click="toggleAllSubscribed">
                                {{ selectedSubscribedIds.length === attendees.subscribed.length && attendees.subscribed.length > 0 ? 'Uncheck All' : 'Check All' }}
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
                                Export Emails
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
                                {{ selectedUnsubscribedIds.length === attendees.unsubscribed.length && attendees.unsubscribed.length > 0 ? 'Uncheck All' : 'Check All' }}
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
                                Export Emails
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

            <div v-if="activeStep === 4" class="grid gap-4 rounded-lg border p-4">
                <h3 class="text-lg font-semibold">Chat and Automation</h3>
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

            <div v-if="activeStep === 5" class="grid gap-4 rounded-lg border p-4">
                <h3 class="text-lg font-semibold">Offers</h3>
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
                        </div>
                    </div>

                    <Button type="button" variant="outline" @click="addOffer">
                        Add Offer
                    </Button>
                </div>
            </div>

            <div v-if="activeStep === 6" class="grid gap-4 rounded-lg border p-4">
                <h3 class="text-lg font-semibold">Reminder and Notification</h3>
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

            <div v-if="activeStep === 7" class="grid gap-4 rounded-lg border p-4">
                <h3 class="text-lg font-semibold">Publish and Tracking</h3>
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

            <div class="flex items-center justify-between gap-3">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="activeStep === 0"
                    @click="previousStep"
                >
                    Previous
                </Button>

                <div class="flex items-center gap-3">
                    <Button
                        v-if="activeStep < steps.length - 1"
                        type="button"
                        @click="nextStep"
                    >
                        Next Step
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                    >
                        {{ submitLabel }}
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
