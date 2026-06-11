<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import RichTextEditor from '@/components/webinars/RichTextEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';

type SegmentForm = {
    enabled: boolean;
    subject: string;
    body: string;
};

type Props = {
    segments: {
        below_50: SegmentForm;
        above_50: SegmentForm;
        completed_no_click: SegmentForm;
    };
    status?: string;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Follow-up emails',
        href: '/settings/follow-up-emails',
    },
];

const form = useForm({
    segments: {
        below_50: { ...props.segments.below_50 },
        above_50: { ...props.segments.above_50 },
        completed_no_click: { ...props.segments.completed_no_click },
    },
});

const submit = (): void => {
    form.patch('/settings/follow-up-emails', {
        preserveScroll: true,
    });
};

const segmentMeta = [
    {
        key: 'below_50' as const,
        title: 'Watched less than 50%',
        description:
            'Sent after the webinar ends to registrants who did not reach the halfway point of the replay.',
    },
    {
        key: 'above_50' as const,
        title: 'Watched at least half, did not reach the end',
        description:
            'Sent to viewers who crossed the halfway point of the replay but did not watch all the way to the end.',
    },
    {
        key: 'completed_no_click' as const,
        title: 'Finished watching, no offer click',
        description:
            'Sent when someone reached the end of the webinar without clicking any tracked offer or CTA link.',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Follow-up emails" />

        <SettingsLayout>
            <div class="flex max-w-3xl flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Segment follow-up emails"
                    description="Customize the automated emails sent after a scheduled webinar ends, based on how each registrant watched. The message body uses the same rich editor as webinars (bold, lists, links). Leave subject or body blank to use the built-in default for that field. Disable a segment to stop those sends entirely."
                />

                <div
                    v-if="props.status === 'follow-up-emails-updated'"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                    role="status"
                >
                    Your follow-up email settings were saved.
                </div>

                <form class="space-y-6" @submit.prevent="submit">
                    <Card
                        v-for="block in segmentMeta"
                        :key="block.key"
                        class="border-border/80 shadow-sm"
                    >
                        <CardHeader class="pb-2">
                            <CardTitle class="text-base">{{ block.title }}</CardTitle>
                            <CardDescription>{{ block.description }}</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="flex items-center gap-3">
                                <input
                                    :id="`enabled-${block.key}`"
                                    v-model="form.segments[block.key].enabled"
                                    type="checkbox"
                                    class="border-input text-primary focus-visible:ring-ring size-4 shrink-0 rounded border shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                />
                                <Label :for="`enabled-${block.key}`" class="cursor-pointer font-medium leading-none">
                                    Enable emails for this segment
                                </Label>
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`subject-${block.key}`">Email subject</Label>
                                <Input
                                    :id="`subject-${block.key}`"
                                    v-model="form.segments[block.key].subject"
                                    type="text"
                                    autocomplete="off"
                                    placeholder="Optional — uses platform default if empty"
                                />
                                <InputError :message="form.errors[`segments.${block.key}.subject`]" />
                            </div>

                            <div class="grid gap-2">
                                <Label>Message body</Label>
                                <div class="follow-up-email-editor">
                                    <RichTextEditor
                                        v-model="form.segments[block.key].body"
                                        :placeholder="
                                            'Optional — default copy if empty. Type {{offer_links}} where the offer list should go; otherwise it is added at the end.'
                                        "
                                    />
                                </div>
                                <InputError :message="form.errors[`segments.${block.key}.body`]" />
                            </div>
                        </CardContent>
                    </Card>

                    <div class="flex items-center gap-3">
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Saving…' : 'Save changes' }}
                        </Button>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>

<style scoped>
.follow-up-email-editor :deep(.ql-container.ql-snow) {
    min-height: 200px;
}

.follow-up-email-editor :deep(.ql-editor) {
    min-height: 200px;
}
</style>
