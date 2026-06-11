<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type TutorialVideo = {
    title: string;
    description: string;
    src: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tutorial', href: '/tutorial' },
];

const videos: TutorialVideo[] = [
    {
        title: 'Dashboard Webinar View',
        description: 'Overview of dashboard navigation and webinar metrics.',
        src: 'https://res.cloudinary.com/dp0bpzh9b/video/upload/v1776948316/webinar-tutorial-videos/smtp-setup_WgWxWuq9_ogp0yi.mp4',
    },
    {
        title: 'Webinar Creation',
        description: 'Step-by-step setup for creating a new webinar.',
        src: 'https://res.cloudinary.com/dp0bpzh9b/video/upload/v1776958680/webinar-creation_1_k6euab.mp4',
    },
    {
        title: 'Webinar Room and Chat Page',
        description: 'How webinar room controls and attendee chat work.',
        src: 'https://res.cloudinary.com/dp0bpzh9b/video/upload/v1776958490/webinar-room-and-chat-page_0tBh8g6s_qk9boz.mp4',
    },
    {
        title: 'SMTP Setup',
        description: 'Configure SMTP to deliver webinar emails properly.',
        src: 'https://res.cloudinary.com/dp0bpzh9b/video/upload/v1776948316/webinar-tutorial-videos/smtp-setup_WgWxWuq9_ogp0yi.mp4',
    },
];
</script>

<template>
    <Head title="Tutorial" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 pb-10 md:p-6">
            <Heading
                title="Tutorial Library"
                description="Learn each key area of the platform with quick video walkthroughs."
            />

            <Card class="border-border/80 shadow-sm">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Icon icon="solar:video-library-bold-duotone" class="size-5 text-primary" />
                        Platform Tutorials
                    </CardTitle>
                    <CardDescription>
                        Play any video below to get guided training on each feature.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-5 lg:grid-cols-2">
                        <article
                            v-for="video in videos"
                            :key="video.src"
                            class="overflow-hidden rounded-xl border bg-card shadow-sm"
                        >
                            <div class="border-b bg-muted/30 px-4 py-3">
                                <h2 class="text-sm font-semibold text-foreground">
                                    {{ video.title }}
                                </h2>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ video.description }}
                                </p>
                            </div>
                            <div class="bg-black">
                                <video
                                    :src="video.src"
                                    controls
                                    preload="metadata"
                                    class="w-full"
                                    style="aspect-ratio: 16 / 9"
                                >
                                    Your browser does not support the video tag.
                                </video>
                            </div>
                        </article>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
