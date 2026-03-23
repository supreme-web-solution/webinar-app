<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type WebinarSummary = {
    id: number;
    title: string;
    host_name: string;
    description: string | null;
    thumbnail_path: string | null;
    uuid: string;
    registration_settings?: {
        buttons?: Array<{
            label: string;
            enabled: boolean;
            is_primary: boolean;
            urgency_mode: 'none' | 'minutes' | 'live';
            urgency_minutes: number | null;
            position?: number;
        }>;
    };
};

const props = defineProps<{
    webinar: WebinarSummary;
}>();

const form = useForm({
    name: '',
    email: '',
});

const submit = (): void => {
    form.post(`/register/${props.webinar.uuid}`, { preserveScroll: true });
};

const registrationButtons = computed(() => {
    const fallback = [
        {
            label: 'Join Webinar',
            enabled: true,
            is_primary: true,
            urgency_mode: 'none' as const,
            urgency_minutes: null,
            position: 0,
        },
    ];

    const source = props.webinar.registration_settings?.buttons;
    if (!Array.isArray(source) || source.length === 0) {
        return fallback;
    }

    const enabled = source
        .filter((button) => button.enabled)
        .sort((a, b) => Number(b.is_primary) - Number(a.is_primary));

    return enabled.length > 0 ? enabled : fallback;
});

const buttonUrgencyText = (button: { urgency_mode: 'none' | 'minutes' | 'live'; urgency_minutes: number | null }): string | null => {
    if (button.urgency_mode === 'live') {
        return 'LIVE';
    }

    if (button.urgency_mode === 'minutes' && button.urgency_minutes) {
        return `${button.urgency_minutes} min`;
    }

    return null;
};
</script>

<template>
    <Head :title="`Register: ${webinar.title}`" />

    <div class="mx-auto grid min-h-screen max-w-5xl items-center gap-6 p-6 md:grid-cols-2">
        <div class="space-y-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-primary">Webinar Impact</p>
            <h1 class="text-3xl font-bold">{{ webinar.title }}</h1>
            <p class="text-sm text-muted-foreground">Hosted by {{ webinar.host_name }}</p>
            <p class="text-sm text-muted-foreground">{{ webinar.description }}</p>
        </div>

        <form class="space-y-4 rounded-xl border bg-card p-6 shadow-sm" @submit.prevent="submit">
            <h2 class="text-xl font-semibold">Fill In Your Details To Join The Webinar</h2>
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" required />
                <InputError :message="form.errors.email" />
            </div>
            <div class="space-y-2">
                <button
                    v-for="(button, index) in registrationButtons"
                    :key="`join-btn-${index}`"
                    type="submit"
                    class="relative w-full overflow-visible rounded-md px-4 py-2 text-sm font-medium transition"
                    :class="index === 0 ? 'bg-primary text-primary-foreground' : 'border border-input bg-background text-foreground hover:bg-muted'"
                    :disabled="form.processing"
                >
                    <span
                        v-if="buttonUrgencyText(button)"
                        class="pointer-events-none absolute -top-2 -right-2 z-10 inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-semibold text-white"
                    >
                        <span class="absolute inset-0 rounded-full bg-rose-500 opacity-60 animate-ping" />
                        <span class="relative">{{ buttonUrgencyText(button) }}</span>
                    </span>
                    {{ button.label }}
                </button>
            </div>
        </form>
    </div>
</template>
