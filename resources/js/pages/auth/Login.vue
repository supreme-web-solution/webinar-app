<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Icon } from '@iconify/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();
</script>

<template>
    <AuthBase
        title="Welcome back"
        description="Sign in to your account to continue"
    >
        <Head title="Log in" />

        <div
            v-if="status"
            class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300"
        >
            {{ status }}
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="space-y-4"
        >
            <!-- Email -->
            <div class="space-y-1.5">
                <Label for="email" class="text-sm font-medium">Email address</Label>
                <div class="relative">
                    <Icon
                        icon="solar:letter-linear"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground size-4"
                    />
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="you@example.com"
                        class="pl-9 h-10 bg-white dark:bg-muted/30 border-border/60 focus-visible:ring-primary/30"
                    />
                </div>
                <InputError :message="errors.email" />
            </div>

            <!-- Password -->
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <Label for="password" class="text-sm font-medium">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-xs text-muted-foreground hover:text-primary transition-colors"
                        :tabindex="5"
                    >
                        Forgot password?
                    </TextLink>
                </div>
                <div class="relative">
                    <Icon
                        icon="solar:lock-password-linear"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground size-4 z-10"
                    />
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="**************"
                        class="pl-9 h-10 bg-white dark:bg-muted/30 border-border/60 focus-visible:ring-primary/30"
                    />
                </div>
                <InputError :message="errors.password" />
            </div>

            <!-- Remember me -->
            <div class="flex items-center gap-2.5">
                <Checkbox id="remember" name="remember" :tabindex="3" />
                <Label for="remember" class="cursor-pointer text-sm font-normal text-muted-foreground">
                    Keep me signed in for 30 days
                </Label>
            </div>

            <!-- Submit -->
            <Button
                type="submit"
                class="w-full h-10 gap-2 font-semibold shadow-sm"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" class="size-4" />
                <Icon v-else icon="solar:login-2-linear" class="size-4" />
                Sign in
            </Button>

        </Form>
    </AuthBase>
</template>
