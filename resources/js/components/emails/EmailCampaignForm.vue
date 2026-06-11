<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import RichTextEditor from '@/components/webinars/RichTextEditor.vue';

type CampaignPayload = {
    id?: number;
    title_prefix: string;
    title: string;
    sender_name: string;
    body: string;
    cta_label: string;
    cta_url: string;
    settings: {
        send_on_import: boolean;
    };
};

const props = defineProps<{
    mode: 'create' | 'edit';
    method: 'post' | 'put';
    actionUrl: string;
    initialValues: CampaignPayload;
    attendees: {
        subscribed_total: number;
        subscribed: Array<{
            id: number;
            name: string | null;
            email: string;
            imported_at: string | null;
            send_count: number;
            click_count: number;
            last_clicked_at: string | null;
            unsubscribe_url?: string;
        }>;
        unsubscribed_total: number;
        unsubscribed: Array<{
            id: number;
            name: string | null;
            email: string;
            unsubscribed_at?: string | null;
            delete_url?: string;
        }>;
    };
    attendeeImportUrl: string | null;
    attendeeActionUrls: {
        bulk_unsubscribe_url: string;
        bulk_delete_url: string;
    } | null;
    sendUrl: string | null;
}>();

const steps = ['Basics', 'Attendees'];

const stepMeta: Array<{ icon: string; color: string; description: string }> = [
    { icon: 'solar:document-text-bold-duotone', color: 'text-indigo-500', description: 'Configure email content and CTA' },
    { icon: 'solar:users-group-rounded-bold-duotone', color: 'text-teal-500', description: 'Import and manage campaign recipients' },
];

const stepHeaderBg = [
    'bg-indigo-100 dark:bg-indigo-950/40',
    'bg-teal-100 dark:bg-teal-950/40',
];

const page = usePage();
const activeStep = ref(0);
const toastMessage = ref<string | null>(null);
const toastType = ref<'warning' | 'success'>('warning');

const form = useForm<CampaignPayload>({
    ...props.initialValues,
    settings: {
        send_on_import: Boolean(props.initialValues.settings?.send_on_import ?? true),
    },
});

const attendeeCsvForm = useForm<{ file: File | null }>({
    file: null,
});

const sendForm = useForm({});

const attendeePanel = ref<'subscribed' | 'unsubscribed'>('subscribed');
const selectedSubscribedIds = ref<number[]>([]);
const selectedUnsubscribedIds = ref<number[]>([]);
const confirmToastMessage = ref<string | null>(null);
const confirmAction = ref<(() => void) | null>(null);

const bulkUnsubscribeForm = useForm<{ attendee_ids: number[] }>({
    attendee_ids: [],
});

const bulkDeleteForm = useForm<{ attendee_ids: number[] }>({
    attendee_ids: [],
});

const submitLabel = computed(() =>
    props.mode === 'create' ? 'Create Campaign' : 'Save Changes',
);

const markRequired = (label: string): string => `${label} *`;

type BasicsSnapshot = {
    title_prefix: string;
    title: string;
    sender_name: string;
    body: string;
    cta_label: string;
    cta_url: string;
};

const snapshotBasics = (values: CampaignPayload): BasicsSnapshot => ({
    title_prefix: values.title_prefix.trim(),
    title: values.title.trim(),
    sender_name: values.sender_name.trim(),
    body: values.body.trim(),
    cta_label: values.cta_label.trim(),
    cta_url: values.cta_url.trim(),
});

const savedBasics = ref<BasicsSnapshot>(snapshotBasics(props.initialValues));

const getPlainTextFromHtml = (value: string): string =>
    value
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

const isValidHttpUrl = (value: string): boolean => {
    if (value.trim() === '') {
        return false;
    }

    try {
        const parsed = new URL(value);

        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
        return false;
    }
};

const missingBasicsInForm = computed((): string[] => {
    const missing: string[] = [];

    if (form.title.trim() === '') {
        missing.push('Email Title');
    }

    if (form.sender_name.trim() === '') {
        missing.push('Sender Name');
    }

    if (form.cta_label.trim() === '') {
        missing.push('CTA Label');
    }

    if (!isValidHttpUrl(form.cta_url)) {
        missing.push('CTA Link');
    }

    if (getPlainTextFromHtml(form.body) === '') {
        missing.push('Email Body');
    }

    return missing;
});

const hasUnsavedBasics = computed(
    () => JSON.stringify(snapshotBasics(form)) !== JSON.stringify(savedBasics.value),
);

const basicsReadyForActions = computed(
    () => missingBasicsInForm.value.length === 0 && !hasUnsavedBasics.value && props.mode === 'edit',
);

const basicsBlockingMessage = computed((): string | null => {
    if (props.mode === 'create') {
        return 'Create and save the campaign in Basics before importing attendees.';
    }

    if (missingBasicsInForm.value.length > 0) {
        return `Complete required basics fields: ${missingBasicsInForm.value.join(', ')}.`;
    }

    if (hasUnsavedBasics.value) {
        return 'You have unsaved basics changes. Click Save Changes before importing or sending.';
    }

    return null;
});

const showToast = (message: string, type: 'warning' | 'success' = 'warning'): void => {
    toastMessage.value = message;
    toastType.value = type;
    window.setTimeout(() => {
        if (toastMessage.value === message) {
            toastMessage.value = null;
        }
    }, 5000);
};

const guardBasicsBeforeAction = (actionLabel: string): boolean => {
    if (basicsBlockingMessage.value) {
        showToast(`${actionLabel} blocked. ${basicsBlockingMessage.value}`);
        activeStep.value = 0;

        return false;
    }

    return true;
};

watch(
    () => (page.props.errors as Record<string, string | undefined>)?.basics,
    (message) => {
        if (message) {
            showToast(message);
            activeStep.value = 0;
        }
    },
);

onMounted(() => {
    const basicsError = (page.props.errors as Record<string, string | undefined>)?.basics;

    if (basicsError) {
        showToast(basicsError);
        activeStep.value = 0;
    }
});

const previousStep = (): void => {
    if (activeStep.value > 0) {
        activeStep.value -= 1;
    }
};

const nextStep = (): void => {
    if (activeStep.value < steps.length - 1) {
        activeStep.value += 1;
    }
};

const submit = (): void => {
    if (missingBasicsInForm.value.length > 0) {
        showToast(`Cannot save yet. Complete: ${missingBasicsInForm.value.join(', ')}.`);

        return;
    }

    const onSuccess = (): void => {
        savedBasics.value = snapshotBasics(form);
        showToast('Campaign basics saved successfully.', 'success');
    };

    if (props.method === 'put') {
        form.put(props.actionUrl, {
            preserveScroll: true,
            onSuccess,
        });

        return;
    }

    form.post(props.actionUrl, {
        preserveScroll: true,
        onSuccess,
    });
};

const onAttendeeCsvSelected = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    attendeeCsvForm.file = target.files?.[0] ?? null;
};

const importAttendeesCsv = (): void => {
    if (!props.attendeeImportUrl || !attendeeCsvForm.file) {
        if (!attendeeCsvForm.file) {
            showToast('Select an import file first.');
        }

        return;
    }

    if (!guardBasicsBeforeAction('CSV import')) {
        return;
    }

    attendeeCsvForm.post(props.attendeeImportUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            attendeeCsvForm.reset('file');
        },
    });
};

const sendCampaignToAll = (): void => {
    if (!props.sendUrl) {
        return;
    }

    if (!guardBasicsBeforeAction('Send to all attendees')) {
        return;
    }

    if (props.attendees.subscribed_total === 0) {
        showToast('Import attendees first before sending.');

        return;
    }

    sendForm.post(props.sendUrl, {
        preserveScroll: true,
    });
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
        showToast('Select at least one subscribed attendee first.');

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

const deleteCampaign = (): void => {
    if (props.mode !== 'edit' || !props.initialValues.id) {
        return;
    }

    router.delete(`/admin/emails/${props.initialValues.id}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="space-y-6">
        <div
            v-if="toastMessage"
            class="flex items-start gap-3 rounded-xl border px-4 py-3 text-sm"
            :class="toastType === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/30 dark:text-emerald-300'
                : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-300'"
        >
            <Icon
                :icon="toastType === 'success' ? 'solar:check-circle-bold-duotone' : 'solar:danger-triangle-bold-duotone'"
                class="mt-0.5 size-4 shrink-0"
                :class="toastType === 'success' ? 'text-emerald-500' : 'text-amber-500'"
            />
            <span>{{ toastMessage }}</span>
        </div>

        <!-- ── Step Indicator (matches webinar wizard) ── -->
        <div class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
            <div class="flex overflow-x-auto scrollbar-none">
                <button
                    v-for="(step, index) in steps"
                    :key="step"
                    type="button"
                    class="group relative flex min-w-0 flex-1 flex-col items-center gap-2 px-6 py-4 text-center transition-colors"
                    :class="[
                        index === activeStep ? 'bg-primary/5' : 'hover:bg-muted/40',
                    ]"
                    @click="activeStep = index"
                >
                    <div
                        v-if="index > 0"
                        class="absolute left-0 top-[29px] h-px w-1/2 -translate-y-1/2"
                        :class="index <= activeStep ? 'bg-primary/40' : 'bg-border'"
                    />
                    <div
                        v-if="index < steps.length - 1"
                        class="absolute right-0 top-[29px] h-px w-1/2 -translate-y-1/2"
                        :class="index < activeStep ? 'bg-primary/40' : 'bg-border'"
                    />
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
                        <Icon
                            v-else
                            :icon="stepMeta[index]?.icon ?? 'solar:document-text-bold-duotone'"
                            class="size-4"
                            :class="index === activeStep ? 'text-primary-foreground' : stepMeta[index]?.color"
                        />
                    </div>
                    <span
                        class="text-[11px] font-semibold leading-tight tracking-wide"
                        :class="index === activeStep ? 'text-primary' : index < activeStep ? 'text-foreground' : 'text-muted-foreground'"
                    >
                        {{ step }}
                    </span>
                </button>
            </div>
            <div class="flex items-center gap-3 border-t border-border/50 bg-muted/20 px-5 py-3">
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
                    <p class="text-xs font-medium text-muted-foreground">Step {{ activeStep + 1 }} of {{ steps.length }}</p>
                    <p class="text-sm font-semibold leading-tight text-foreground">{{ steps[activeStep] }}</p>
                </div>
                <div class="ml-auto flex items-center gap-1 text-xs text-muted-foreground">
                    <Icon icon="solar:check-circle-bold" class="size-3.5 text-primary" />
                    {{ activeStep + 1 }} / {{ steps.length }} completed
                </div>
            </div>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <!-- Basics -->
            <div v-if="activeStep === 0" class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:document-text-bold-duotone" class="size-5 text-indigo-500" />
                    <h3 class="text-base font-semibold text-foreground">Basics</h3>
                    <span class="text-xs text-muted-foreground">{{ stepMeta[0].description }}</span>
                </div>
                <div class="grid gap-5 p-5">
                    <div class="grid gap-2 md:grid-cols-2 md:gap-4">
                        <div class="grid gap-2">
                            <Label for="title_prefix">Title prefix</Label>
                            <Input
                                id="title_prefix"
                                v-model="form.title_prefix"
                                placeholder="[Campaign]"
                            />
                            <InputError :message="form.errors.title_prefix" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="title">{{ markRequired('Email Title') }}</Label>
                            <Input id="title" v-model="form.title" required placeholder="Main headline for this email" />
                            <InputError :message="form.errors.title" />
                        </div>
                    </div>

                    <div class="grid gap-2 md:grid-cols-2 md:gap-4">
                        <div class="grid gap-2">
                            <Label for="sender_name">{{ markRequired('Sender Name') }}</Label>
                            <Input id="sender_name" v-model="form.sender_name" required placeholder="Your name or brand" />
                            <InputError :message="form.errors.sender_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="cta_label">{{ markRequired('CTA Label') }}</Label>
                            <Input id="cta_label" v-model="form.cta_label" required placeholder="Open Link" />
                            <InputError :message="form.errors.cta_label" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="cta_url">{{ markRequired('CTA Link') }}</Label>
                        <Input id="cta_url" v-model="form.cta_url" required placeholder="https://your-offer-link.com" />
                        <p class="text-xs text-muted-foreground">
                            All clicks are tracked per recipient before redirecting to this link.
                        </p>
                        <InputError :message="form.errors.cta_url" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="body">Email Body</Label>
                        <div class="email-body-editor">
                            <RichTextEditor
                                id="body"
                                v-model="form.body"
                                placeholder="Write the email body content here. Use paragraphs and spacing for better readability."
                            />
                        </div>
                        <InputError :message="form.errors.body" />
                    </div>

                    <label class="flex items-center gap-3 rounded-md border p-3">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.settings.send_on_import"
                            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition"
                            :class="form.settings.send_on_import ? 'bg-primary' : 'bg-muted'"
                            @click="form.settings.send_on_import = !form.settings.send_on_import"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                                :class="form.settings.send_on_import ? 'translate-x-4' : 'translate-x-1'"
                            />
                        </button>
                        <span class="text-sm">
                            <span class="block font-medium">Send on import</span>
                            <span class="text-muted-foreground">Automatically queue emails when a CSV file is uploaded.</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Attendees -->
            <div v-if="activeStep === 1" class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
                <div class="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-5 py-4">
                    <Icon icon="solar:users-group-rounded-bold-duotone" class="size-5 text-teal-500" />
                    <h3 class="text-base font-semibold text-foreground">Attendees</h3>
                    <span class="text-xs text-muted-foreground">{{ stepMeta[1].description }}</span>
                </div>
                <div class="grid gap-5 p-5">
                    <p class="text-sm text-muted-foreground">
                        Upload CSV/XLSX/XLS files to import attendee emails and send this campaign.
                    </p>

                    <div v-if="!attendeeImportUrl" class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                        Create the campaign first, then return to this tab to upload attendees.
                    </div>

                    <template v-else>
                        <div
                            v-if="basicsBlockingMessage"
                            class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-300"
                        >
                            <Icon icon="solar:danger-triangle-bold-duotone" class="mt-0.5 size-4 shrink-0 text-amber-500" />
                            <div>
                                <p class="font-medium">Save basics before importing or sending</p>
                                <p class="mt-1">{{ basicsBlockingMessage }}</p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="mt-3 h-7 gap-1.5 border-amber-300 bg-white text-xs text-amber-900 hover:bg-amber-100 dark:bg-transparent dark:text-amber-200"
                                    @click="activeStep = 0"
                                >
                                    <Icon icon="solar:document-text-bold-duotone" class="size-3.5" />
                                    Go to Basics
                                </Button>
                            </div>
                        </div>

                        <div
                            v-else-if="basicsReadyForActions"
                            class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/30 dark:text-emerald-300"
                        >
                            <Icon icon="solar:check-circle-bold-duotone" class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                            <span>Campaign basics are saved and ready. You can import attendees and send emails.</span>
                        </div>

                        <div class="grid gap-3 rounded-md border p-4">
                            <Label for="attendee_csv">Import File *</Label>
                            <Input id="attendee_csv" type="file" accept=".csv,.txt,.xlsx,.xls" @change="onAttendeeCsvSelected" />
                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    :disabled="attendeeCsvForm.processing || !attendeeCsvForm.file || !basicsReadyForActions"
                                    class="gap-1.5"
                                    @click="importAttendeesCsv"
                                >
                                    <Icon icon="solar:upload-bold-duotone" class="size-4" />
                                    {{ attendeeCsvForm.processing ? 'Uploading...' : 'Upload and Import' }}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="gap-1.5"
                                    :disabled="sendForm.processing || attendees.subscribed_total === 0 || !sendUrl || !basicsReadyForActions"
                                    @click="sendCampaignToAll"
                                >
                                    <Icon icon="solar:letter-bold-duotone" class="size-4" />
                                    {{ sendForm.processing ? 'Queueing...' : 'Send to All Attendees' }}
                                </Button>
                            </div>
                            <InputError :message="attendeeCsvForm.errors.file" />
                            <InputError :message="(page.props.errors as Record<string, string>)?.basics" />
                        </div>

                        <div
                            v-if="confirmToastMessage"
                            class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-800/50 dark:bg-rose-950/30"
                        >
                            <p class="text-sm font-medium text-rose-800 dark:text-rose-300">{{ confirmToastMessage }}</p>
                            <div class="mt-3 flex gap-2">
                                <Button type="button" size="sm" variant="destructive" @click="continueConfirmToast">
                                    Confirm
                                </Button>
                                <Button type="button" size="sm" variant="outline" @click="cancelConfirmToast">
                                    Cancel
                                </Button>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-md border px-3 py-1 text-xs"
                                :class="attendeePanel === 'subscribed' ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background'"
                                @click="attendeePanel = 'subscribed'"
                            >
                                Subscribed ({{ attendees.subscribed_total }})
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
                                <Button
                                    v-if="attendeeActionUrls"
                                    type="button"
                                    variant="outline"
                                    class="h-8 px-2 text-xs"
                                    @click="toggleAllSubscribed"
                                >
                                    {{
                                        selectedSubscribedIds.length === attendees.subscribed.length && attendees.subscribed.length > 0
                                            ? 'Uncheck All (shown)'
                                            : 'Check All (shown)'
                                    }}
                                </Button>
                                <Button
                                    v-if="attendeeActionUrls"
                                    type="button"
                                    variant="outline"
                                    class="h-8 px-2 text-xs"
                                    @click="moveBulkToUnsubscribed"
                                >
                                    Move Selected to Unsubscribed
                                </Button>
                                <span class="ml-auto text-xs text-muted-foreground">
                                    Showing latest {{ attendees.subscribed.length }} of {{ attendees.subscribed_total }}
                                </span>
                            </div>
                            <div class="max-h-[420px] overflow-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-muted/40 text-left">
                                        <tr>
                                            <th v-if="attendeeActionUrls" class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Select</th>
                                            <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Name</th>
                                            <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Email</th>
                                            <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Imported</th>
                                            <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Sent</th>
                                            <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Clicks</th>
                                            <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Last Click</th>
                                            <th v-if="attendeeActionUrls" class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="attendee in attendees.subscribed"
                                            :key="attendee.id"
                                            class="border-t border-border/30 transition-colors hover:bg-muted/30"
                                        >
                                            <td v-if="attendeeActionUrls" class="px-3 py-2.5">
                                                <input
                                                    :checked="selectedSubscribedIds.includes(attendee.id)"
                                                    type="checkbox"
                                                    class="h-4 w-4"
                                                    @change="toggleSubscribed(attendee.id)"
                                                />
                                            </td>
                                            <td class="px-3 py-2.5">{{ attendee.name || '-' }}</td>
                                            <td class="px-3 py-2.5 font-medium">{{ attendee.email }}</td>
                                            <td class="px-3 py-2.5 text-xs text-muted-foreground">{{ attendee.imported_at || '-' }}</td>
                                            <td class="px-3 py-2.5 tabular-nums">{{ attendee.send_count }}</td>
                                            <td class="px-3 py-2.5 tabular-nums">
                                                <span
                                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                                    :class="attendee.click_count > 0
                                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400'
                                                        : 'bg-muted text-muted-foreground'"
                                                >
                                                    {{ attendee.click_count }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2.5 text-xs text-muted-foreground">{{ attendee.last_clicked_at || '-' }}</td>
                                            <td v-if="attendeeActionUrls" class="px-3 py-2.5">
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
                                            <td :colspan="attendeeActionUrls ? 8 : 6" class="px-3 py-10 text-center text-muted-foreground">
                                                <Icon icon="solar:users-group-rounded-bold-duotone" class="mx-auto mb-2 size-8 opacity-40" />
                                                <p>No subscribed attendees yet.</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div v-else class="overflow-hidden rounded-md border">
                            <div class="flex flex-wrap items-center gap-2 border-b bg-muted/20 px-3 py-2">
                                <Button
                                    v-if="attendeeActionUrls"
                                    type="button"
                                    variant="outline"
                                    class="h-8 px-2 text-xs"
                                    @click="toggleAllUnsubscribed"
                                >
                                    {{
                                        selectedUnsubscribedIds.length === attendees.unsubscribed.length && attendees.unsubscribed.length > 0
                                            ? 'Uncheck All (shown)'
                                            : 'Check All (shown)'
                                    }}
                                </Button>
                                <Button
                                    v-if="attendeeActionUrls"
                                    type="button"
                                    variant="outline"
                                    class="h-8 px-2 text-xs"
                                    @click="deleteBulkUnsubscribed"
                                >
                                    Delete Selected
                                </Button>
                                <span class="ml-auto text-xs text-muted-foreground">
                                    Showing latest {{ attendees.unsubscribed.length }} of {{ attendees.unsubscribed_total }}
                                </span>
                            </div>
                            <div class="max-h-[420px] overflow-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-muted/40 text-left">
                                        <tr>
                                            <th v-if="attendeeActionUrls" class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Select</th>
                                            <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Name</th>
                                            <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Email</th>
                                            <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Unsubscribed At</th>
                                            <th v-if="attendeeActionUrls" class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="attendee in attendees.unsubscribed"
                                            :key="attendee.id"
                                            class="border-t border-border/30 transition-colors hover:bg-muted/30"
                                        >
                                            <td v-if="attendeeActionUrls" class="px-3 py-2.5">
                                                <input
                                                    :checked="selectedUnsubscribedIds.includes(attendee.id)"
                                                    type="checkbox"
                                                    class="h-4 w-4"
                                                    @change="toggleUnsubscribed(attendee.id)"
                                                />
                                            </td>
                                            <td class="px-3 py-2.5">{{ attendee.name || '-' }}</td>
                                            <td class="px-3 py-2.5 font-medium">{{ attendee.email }}</td>
                                            <td class="px-3 py-2.5 text-xs text-muted-foreground">{{ attendee.unsubscribed_at || '-' }}</td>
                                            <td v-if="attendeeActionUrls" class="px-3 py-2.5">
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
                                            <td :colspan="attendeeActionUrls ? 5 : 3" class="px-3 py-10 text-center text-muted-foreground">
                                                <Icon icon="solar:letter-unread-bold-duotone" class="mx-auto mb-2 size-8 opacity-40" />
                                                <p>No unsubscribe logs yet.</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
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
                        {{ form.processing ? 'Saving...' : submitLabel }}
                    </Button>
                    <Button
                        v-if="mode === 'edit'"
                        type="button"
                        variant="destructive"
                        class="gap-1.5"
                        :disabled="form.processing"
                        @click="deleteCampaign"
                    >
                        <Icon icon="solar:trash-bin-2-linear" class="size-4" />
                        Delete
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>

<style scoped>
.email-body-editor :deep(.ql-container.ql-snow) {
    min-height: 220px;
}

.email-body-editor :deep(.ql-editor) {
    min-height: 220px;
}
</style>
