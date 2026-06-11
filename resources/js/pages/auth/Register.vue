<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';
</script>

<template>
    <AuthBase
        title="Create your account"
        description="Start hosting webinars in minutes — it's free"
    >
        <Head title="Register" />

        <Form
            v-bind="store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="space-y-4"
        >
            <!-- Full name -->
            <div class="space-y-1.5">
                <Label for="name" class="text-sm font-medium">Full name</Label>
                <div class="relative">
                    <Icon
                        icon="solar:user-linear"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground size-4"
                    />
                    <Input
                        id="name"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="name"
                        name="name"
                        placeholder="Jane Smith"
                        class="pl-9 h-10 bg-white dark:bg-muted/30 border-border/60 focus-visible:ring-primary/30"
                    />
                </div>
                <InputError :message="errors.name" />
            </div>

            <!-- Work email -->
            <div class="space-y-1.5">
                <Label for="email" class="text-sm font-medium">Work email</Label>
                <div class="relative">
                    <Icon
                        icon="solar:letter-linear"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground size-4"
                    />
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        name="email"
                        placeholder="you@company.com"
                        class="pl-9 h-10 bg-white dark:bg-muted/30 border-border/60 focus-visible:ring-primary/30"
                    />
                </div>
                <InputError :message="errors.email" />
            </div>

            <!-- Password -->
            <div class="space-y-1.5">
                <Label for="password" class="text-sm font-medium">Password</Label>
                <div class="relative">
                    <Icon
                        icon="solar:lock-password-linear"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground size-4 z-10"
                    />
                    <PasswordInput
                        id="password"
                        required
                        :tabindex="3"
                        autocomplete="new-password"
                        name="password"
                        placeholder="Min. 8 characters"
                        class="pl-9 h-10 bg-white dark:bg-muted/30 border-border/60 focus-visible:ring-primary/30"
                    />
                </div>
                <InputError :message="errors.password" />
            </div>

            <!-- Confirm password -->
            <div class="space-y-1.5">
                <Label for="password_confirmation" class="text-sm font-medium">Confirm password</Label>
                <div class="relative">
                    <Icon
                        icon="solar:lock-check-linear"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground size-4 z-10"
                    />
                    <PasswordInput
                        id="password_confirmation"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="Repeat password"
                        class="pl-9 h-10 bg-white dark:bg-muted/30 border-border/60 focus-visible:ring-primary/30"
                    />
                </div>
                <InputError :message="errors.password_confirmation" />
            </div>

            <!-- Submit -->
            <Button
                type="submit"
                class="w-full h-10 gap-2 font-semibold shadow-sm mt-2"
                :tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" class="size-4" />
                <Icon v-else icon="solar:user-plus-linear" class="size-4" />
                Create account
            </Button>

            <!-- Login link -->
            <p class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink
                    :href="login()"
                    class="font-semibold text-primary hover:text-primary/80 transition-colors"
                    :tabindex="6"
                >
                    Sign in
                </TextLink>
            </p>
        </Form>
    </AuthBase>
</template>
