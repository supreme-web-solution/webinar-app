<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Icon } from '@iconify/vue';
import { computed, ref } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { SidebarTrigger } from '@/components/ui/sidebar';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useInitials } from '@/composables/useInitials';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const auth = computed(() => page.props.auth as { user: { name: string; email: string; avatar?: string } });
const { getInitials } = useInitials();
const searchQuery = ref('');
const { resolvedAppearance, updateAppearance } = useAppearance();

const toggleTheme = (): void => {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
};
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b border-border/60 bg-background/95 px-4 backdrop-blur-sm transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-5"
    >
        <!-- Left: sidebar trigger + breadcrumbs -->
        <div class="flex items-center gap-2 min-w-0">
            <SidebarTrigger class="-ml-1 text-muted-foreground hover:text-foreground" />
            <div class="h-4 w-px bg-border/60 hidden sm:block" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <!-- Spacer -->
        <div class="flex-1" />

        <!-- Right: search + actions -->
        <div class="flex items-center gap-2">
            <!-- Search -->
            <div class="relative hidden md:flex items-center">
                <Icon
                    icon="solar:magnifier-linear"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground size-3.5"
                />
                <Input
                    v-model="searchQuery"
                    type="search"
                    placeholder="Search..."
                    class="h-8 w-48 lg:w-64 pl-8 pr-3 text-sm bg-muted/50 border-border/50 focus-visible:ring-primary/30 focus-visible:bg-background transition-all"
                />
                <kbd class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 hidden lg:inline-flex h-4 select-none items-center gap-0.5 rounded border border-border bg-muted px-1 font-mono text-[10px] text-muted-foreground">
                    ⌘K
                </kbd>
            </div>

            <!-- Notifications -->
            <Button variant="ghost" size="icon" class="relative h-8 w-8 text-muted-foreground hover:text-foreground">
                <Icon icon="solar:bell-linear" class="size-4" />
                <span class="absolute right-1.5 top-1.5 h-1.5 w-1.5 rounded-full bg-primary ring-2 ring-background" />
                <span class="sr-only">Notifications</span>
            </Button>

            <!-- Dark mode toggle -->
            <Button
                variant="ghost"
                size="icon"
                class="h-8 w-8 text-muted-foreground hover:text-foreground"
                @click="toggleTheme"
            >
                <Icon :icon="resolvedAppearance === 'dark' ? 'solar:sun-linear' : 'solar:moon-linear'" class="size-4" />
                <span class="sr-only">Toggle theme</span>
            </Button>

            <!-- User avatar dropdown -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="ghost" class="relative h-8 w-8 rounded-full p-0 ring-2 ring-border/40 hover:ring-primary/40 transition-all">
                        <Avatar class="h-8 w-8 rounded-full">
                            <AvatarImage
                                v-if="auth.user?.avatar"
                                :src="auth.user.avatar"
                                :alt="auth.user.name"
                            />
                            <AvatarFallback class="rounded-full bg-primary/10 text-primary text-xs font-semibold">
                                {{ getInitials(auth.user?.name ?? '') }}
                            </AvatarFallback>
                        </Avatar>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-56 rounded-xl shadow-lg border-border/60"
                    align="end"
                    :side-offset="8"
                >
                    <UserMenuContent :user="auth.user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
