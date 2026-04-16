<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type UserRow = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedUsers = {
    data: UserRow[];
    links: PaginationLink[];
    total: number;
    from: number | null;
    to: number | null;
};

type Props = {
    users: PaginatedUsers;
    filters: {
        search: string;
        verification: 'all' | 'verified' | 'unverified';
        per_page: number;
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/admin/users' },
];

const filterForm = reactive({
    search: props.filters.search ?? '',
    verification: props.filters.verification ?? 'all',
    per_page: String(props.filters.per_page ?? 20),
});

const applyFilters = (): void => {
    router.get('/admin/users', {
        search: filterForm.search || undefined,
        verification: filterForm.verification !== 'all' ? filterForm.verification : undefined,
        per_page: Number(filterForm.per_page || 20),
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = (): void => {
    filterForm.search = '';
    filterForm.verification = 'all';
    filterForm.per_page = '20';
    applyFilters();
};

const editingUserId = ref<number | null>(null);
const editForm = useForm({
    name: '',
    email: '',
    password: '',
});

const activeUser = computed(() => props.users.data.find((user) => user.id === editingUserId.value) ?? null);

const openEditor = (user: UserRow): void => {
    editingUserId.value = user.id;
    editForm.reset();
    editForm.clearErrors();
    editForm.name = user.name;
    editForm.email = user.email;
};

const closeEditor = (): void => {
    editingUserId.value = null;
    editForm.reset();
    editForm.clearErrors();
};

const saveUser = (): void => {
    if (! editingUserId.value) return;

    editForm.put(`/admin/users/${editingUserId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeEditor();
        },
    });
};

const deleteUser = (user: UserRow): void => {
    const confirmed = window.confirm(`Delete user "${user.email}"? This cannot be undone.`);
    if (! confirmed) return;

    router.delete(`/admin/users/${user.id}`, {
        preserveScroll: true,
    });
};

const formatDate = (value: string | null): string => {
    if (! value) return '-';
    return new Date(value).toLocaleString();
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="User Management" />

        <div class="space-y-5 p-4 md:p-6">
            <Heading
                title="User Management"
                description="Filter, review, edit, and delete user accounts."
            />

            <div class="grid gap-3 rounded-xl border border-border/60 bg-card p-4 md:grid-cols-4">
                <div class="grid gap-1.5 md:col-span-2">
                    <Label for="search">Search</Label>
                    <Input id="search" v-model="filterForm.search" placeholder="Name or email..." />
                </div>
                <div class="grid gap-1.5">
                    <Label for="verification">Verification</Label>
                    <select
                        id="verification"
                        v-model="filterForm.verification"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option value="all">All users</option>
                        <option value="verified">Verified</option>
                        <option value="unverified">Unverified</option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="per_page">Per page</Label>
                    <select
                        id="per_page"
                        v-model="filterForm.per_page"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div class="md:col-span-4 flex items-center gap-2">
                    <Button type="button" @click="applyFilters">Apply Filters</Button>
                    <Button type="button" variant="outline" @click="clearFilters">Clear</Button>
                    <p class="ml-auto text-xs text-muted-foreground">
                        Showing {{ users.from ?? 0 }}-{{ users.to ?? 0 }} of {{ users.total }}
                    </p>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-border/60 bg-card">
                <div class="overflow-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead class="bg-muted/30">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">Name</th>
                                <th class="px-4 py-2 text-left font-medium">Email</th>
                                <th class="px-4 py-2 text-left font-medium">Status</th>
                                <th class="px-4 py-2 text-left font-medium">Created</th>
                                <th class="px-4 py-2 text-left font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in users.data" :key="user.id" class="border-t">
                                <td class="px-4 py-2">{{ user.name }}</td>
                                <td class="px-4 py-2">{{ user.email }}</td>
                                <td class="px-4 py-2">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="user.email_verified_at ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                                    >
                                        {{ user.email_verified_at ? 'Verified' : 'Unverified' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-muted-foreground">{{ formatDate(user.created_at) }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <Button type="button" variant="outline" class="h-8 px-3 text-xs" @click="openEditor(user)">Edit</Button>
                                        <Button type="button" variant="destructive" class="h-8 px-3 text-xs" @click="deleteUser(user)">Delete</Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="users.data.length === 0">
                                <td class="px-4 py-8 text-center text-muted-foreground" colspan="5">
                                    No users found with the selected filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center gap-2 border-t px-4 py-3">
                    <a
                        v-for="(link, i) in users.links"
                        :key="`link-${i}`"
                        :href="link.url || '#'"
                        class="rounded-md border px-2 py-1 text-xs"
                        :class="[
                            link.active ? 'bg-primary text-primary-foreground border-primary' : 'bg-background',
                            !link.url ? 'pointer-events-none opacity-40' : '',
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>

            <div v-if="activeUser" class="rounded-xl border border-border/60 bg-card p-4">
                <h3 class="text-sm font-semibold text-foreground">Edit User</h3>
                <p class="mb-4 text-xs text-muted-foreground">User ID: {{ activeUser.id }}</p>
                <form class="grid gap-3 md:grid-cols-2" @submit.prevent="saveUser">
                    <div class="grid gap-1.5">
                        <Label for="edit_name">Name</Label>
                        <Input id="edit_name" v-model="editForm.name" />
                        <InputError :message="editForm.errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="edit_email">Email</Label>
                        <Input id="edit_email" v-model="editForm.email" type="email" />
                        <InputError :message="editForm.errors.email" />
                    </div>
                    <div class="grid gap-1.5 md:col-span-2">
                        <Label for="edit_password">New Password (optional)</Label>
                        <Input id="edit_password" v-model="editForm.password" type="password" placeholder="Leave blank to keep current password" />
                        <InputError :message="editForm.errors.password" />
                    </div>
                    <div class="md:col-span-2 flex items-center gap-2">
                        <Button type="submit" :disabled="editForm.processing">Save User</Button>
                        <Button type="button" variant="outline" @click="closeEditor">Cancel</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

