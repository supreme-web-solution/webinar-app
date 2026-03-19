<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    webinarTitle: string | null;
    email: string;
}>();
</script>

<template>
    <Head title="Unsubscribed" />

    <div class="mx-auto flex min-h-screen max-w-2xl items-center p-6">
        <div class="w-full rounded-xl border bg-card p-8 text-center shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Email Preferences Updated</p>
            <h1 class="mt-2 text-2xl font-semibold">You are unsubscribed</h1>
            <p class="mt-3 text-sm text-muted-foreground">
                {{ email }} will no longer receive webinar emails
                <span v-if="webinarTitle">for {{ webinarTitle }}</span>.
            </p>
            <Link href="/" class="mt-6 inline-block rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">
                Back to Home
            </Link>
        </div>
    </div>
</template>
