<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const props = defineProps<{
    permissions: string[];
    selectedPlan?: string;
}>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    permission: props.selectedPlan || '',
});

const submit = (): void => {
    form.post('/access/register', { preserveScroll: true });
};
</script>

<template>
    <Head title="Register for Access" />

    <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div>
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    Register for Access
                </h2>
            </div>
            <form class="mt-8 space-y-6" @submit.prevent="submit">
                <div class="rounded-md shadow-sm -space-y-px">
                    <div>
                        <Label for="name" class="sr-only">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 focus:z-10 sm:text-sm"
                            placeholder="Name"
                        />
                        <InputError :message="form.errors.name" class="mt-2" />
                    </div>
                    <div>
                        <Label for="email" class="sr-only">Email address</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 focus:z-10 sm:text-sm"
                            placeholder="Email address"
                        />
                        <InputError :message="form.errors.email" class="mt-2" />
                    </div>
                    <div>
                        <Label for="password" class="sr-only">Password</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 focus:z-10 sm:text-sm"
                            placeholder="Password"
                        />
                        <InputError :message="form.errors.password" class="mt-2" />
                    </div>
                    <div>
                        <Label for="password_confirmation" class="sr-only">Confirm Password</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            required
                            class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 focus:z-10 sm:text-sm"
                            placeholder="Confirm Password"
                        />
                    </div>
                </div>

                <div>
                    <Label for="permission" class="block text-sm font-medium text-gray-700">Permission</Label>
                    <Select v-model="form.permission" required>
                        <SelectTrigger class="w-full mt-1">
                            <SelectValue placeholder="Select Permission" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="permission in permissions" :key="permission" :value="permission">
                                {{ permission }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.permission" class="mt-2" />
                </div>

                <div>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                    >
                        Register
                    </Button>
                </div>
            </form>
        </div>
    </div>
</template>