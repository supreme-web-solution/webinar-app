<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Solo Ad Traffic Training',
        href: '/settings/solo-ad-traffic-training',
    },
];
</script>

<template>
    <Head title="Solo Ad Traffic Training" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 pb-10 md:p-6">
            <div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
                <Heading
                    title="Solo Ad Traffic Training"
                    description="Watch the full training video and apply the traffic workflow to your webinar campaigns."
                />

                <Card class="border-border/80 mx-auto w-full max-w-3xl shadow-sm">
                    <CardHeader>
                        <CardTitle>Training Video</CardTitle>
                        <CardDescription>
                            RAP: Solo Ad Training
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-hidden rounded-xl border bg-black shadow-sm">
                            <iframe
                                class="w-full"
                                style="aspect-ratio: 16 / 9"
                                src="https://player.vimeo.com/video/913010007"
                                title="Solo Ad Traffic Training"
                                frameborder="0"
                                allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media; web-share"
                                referrerpolicy="strict-origin-when-cross-origin"
                                allowfullscreen
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
