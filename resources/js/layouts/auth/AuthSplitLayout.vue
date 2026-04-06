<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Icon } from '@iconify/vue';
import { home } from '@/routes';

const page = usePage();
const name = (page.props.name as string) ?? 'WebinarPro';

defineProps<{
    title?: string;
    description?: string;
}>();

const features = [
    {
        icon: 'solar:monitor-camera-bold-duotone',
        title: 'Live & automated webinars',
        desc: 'Host real-time sessions or run fully automated evergreen funnels.',
    },
    {
        icon: 'solar:users-group-rounded-bold-duotone',
        title: 'Grow your audience',
        desc: 'Custom registration pages with one-click share links and analytics.',
    },
    {
        icon: 'solar:chart-2-bold-duotone',
        title: 'Real-time analytics',
        desc: 'Track views, registrations, attendance, and engagement live.',
    },
];
</script>

<template>
    <div class="grid min-h-dvh lg:grid-cols-[1fr_480px]">
        <!-- Left branding panel -->
        <div class="relative hidden overflow-hidden lg:flex flex-col bg-[hsl(222_47%_11%)]">
            <!-- Gradient overlays -->
            <div class="pointer-events-none absolute -top-40 -left-40 h-125 w-125 rounded-full bg-indigo-600/20 blur-3xl" />
            <div class="pointer-events-none absolute bottom-0 right-0 h-72 w-72 rounded-full bg-violet-500/20 blur-3xl" />
            <div class="pointer-events-none absolute top-1/3 right-1/4 h-48 w-48 rounded-full bg-indigo-400/10 blur-2xl" />
            <!-- Grid pattern overlay -->
            <div class="pointer-events-none absolute inset-0 opacity-[0.03]"
                style="background-image: linear-gradient(hsl(0 0% 100%) 1px, transparent 1px), linear-gradient(to right, hsl(0 0% 100%) 1px, transparent 1px); background-size: 32px 32px;" />

            <div class="relative z-10 flex flex-1 flex-col justify-between p-10 xl:p-12">
                <!-- Logo -->
                <Link :href="home()" class="flex items-center gap-3 w-fit group">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500/20 ring-1 ring-indigo-400/30 backdrop-blur group-hover:bg-indigo-500/30 transition-colors">
                        <Icon icon="solar:monitor-camera-bold-duotone" class="text-indigo-200 text-xl" />
                    </div>
                    <span class="text-white font-semibold text-lg tracking-tight">{{ name }}</span>
                </Link>

                <!-- Hero content -->
                <div class="space-y-8">
                    <div class="space-y-3">
                        <div class="inline-flex items-center gap-2 rounded-full border border-indigo-400/20 bg-indigo-500/10 px-3 py-1">
                            <div class="h-1.5 w-1.5 rounded-full bg-indigo-400 animate-pulse" />
                            <span class="text-xs text-indigo-300 font-medium">Enterprise Webinar Platform</span>
                        </div>
                        <h2 class="text-3xl xl:text-4xl font-bold text-white leading-tight">
                            Host webinars that<br />
                            <span class="text-indigo-300">convert audiences.</span>
                        </h2>
                        <p class="text-[hsl(220_14%_65%)] text-sm leading-relaxed max-w-sm">
                            A complete platform for creators and teams — from live sessions to on-demand replays with built‑in analytics.
                        </p>
                    </div>

                    <!-- Feature list -->
                    <div class="space-y-4">
                        <div v-for="feature in features" :key="feature.title" class="flex items-start gap-4">
                            <div class="flex shrink-0 h-9 w-9 items-center justify-center rounded-lg bg-indigo-500/15 ring-1 ring-indigo-400/20">
                                <Icon :icon="feature.icon" class="text-indigo-300 text-base" />
                            </div>
                            <div>
                                <p class="text-white font-medium text-sm">{{ feature.title }}</p>
                                <p class="mt-0.5 text-[hsl(220_14%_58%)] text-xs leading-relaxed">{{ feature.desc }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Testimonial -->
                <div class="rounded-2xl border border-white/8 bg-white/5 p-5 backdrop-blur-sm">
                    <div class="flex gap-1 mb-3">
                        <Icon v-for="i in 5" :key="i" icon="solar:star-bold" class="text-amber-400 text-sm" />
                    </div>
                    <p class="text-[hsl(220_14%_72%)] text-sm leading-relaxed">
                        "This platform helped us 3× our registrations and run fully automated, evergreen sessions without a technical team."
                    </p>
                    <div class="mt-4 flex items-center gap-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-400/20 text-xs font-bold text-indigo-200">
                            SM
                        </div>
                        <div>
                            <p class="text-white text-xs font-medium">Sarah Mitchell</p>
                            <p class="text-[hsl(220_14%_55%)] text-xs">Head of Growth, TechCo</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right form panel -->
        <div class="flex flex-col items-center justify-center bg-background px-8 py-12 min-h-dvh">
            <!-- Mobile logo -->
            <Link :href="home()" class="mb-8 flex items-center gap-2.5 lg:hidden">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary">
                    <Icon icon="solar:monitor-camera-bold-duotone" class="text-white text-lg" />
                </div>
                <span class="font-semibold text-lg text-foreground">{{ name }}</span>
            </Link>

            <div class="w-full max-w-100 space-y-6">
                <div class="space-y-1.5 text-center">
                    <h1 v-if="title" class="text-2xl font-bold tracking-tight text-foreground">{{ title }}</h1>
                    <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
