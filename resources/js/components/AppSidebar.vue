<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Icon } from '@iconify/vue';
import { computed } from 'vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import NavUser from '@/components/NavUser.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard } from '@/routes';

const page = usePage();
const appName = computed(() => (page.props.name as string) ?? 'WebinarPro');
const { isCurrentUrl } = useCurrentUrl();

const mainNavItems = [
    { title: 'Dashboard', href: dashboard(), icon: 'solar:widget-2-bold-duotone' },
    { title: 'Webinars', href: '/admin/webinars', icon: 'solar:monitor-camera-bold-duotone' },
    { title: 'Chat', href: '/admin/chats', icon: 'solar:chat-round-dots-bold-duotone' },
];

const managementNavItems = [
    { title: 'Registrants', href: '/admin/registrants', icon: 'solar:users-group-rounded-bold-duotone' },
    { title: 'Analytics', href: '/admin/analytics', icon: 'solar:chart-2-bold-duotone' },
    { title: 'Settings', href: '/settings/profile', icon: 'solar:settings-minimalistic-bold-duotone' },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <!-- Logo / App name -->
        <SidebarHeader class="pb-3 pt-4">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()" class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary shadow-sm">
                                <Icon icon="solar:monitor-camera-bold-duotone" class="text-white text-lg" />
                            </div>
                            <div class="grid leading-tight">
                                <span class="truncate font-semibold text-sm text-sidebar-foreground group-data-[collapsible=icon]:hidden">{{ appName }}</span>
                                <span class="truncate text-[11px] text-sidebar-foreground/50 group-data-[collapsible=icon]:hidden">Webinar Platform</span>
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-0">
            <!-- Platform nav -->
            <SidebarGroup class="px-3 py-2">
                <SidebarGroupLabel class="mb-1 px-2 text-[10px] font-semibold uppercase tracking-wider text-sidebar-foreground/40 group-data-[collapsible=icon]:hidden">
                    Platform
                </SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in mainNavItems" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentUrl(item.href)"
                            :tooltip="item.title"
                            class="h-9 gap-3 rounded-lg text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground data-[active=true]:bg-primary/20 data-[active=true]:text-white"
                        >
                            <Link :href="item.href" class="flex items-center gap-3">
                                <Icon :icon="item.icon" class="size-4 shrink-0" />
                                <span class="text-sm">{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>

            <!-- Separator -->
            <div class="mx-5 my-1 h-px bg-sidebar-border/60 group-data-[collapsible=icon]:mx-3" />

            <!-- Management nav -->
            <SidebarGroup class="px-3 py-2">
                <SidebarGroupLabel class="mb-1 px-2 text-[10px] font-semibold uppercase tracking-wider text-sidebar-foreground/40 group-data-[collapsible=icon]:hidden">
                    Management
                </SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in managementNavItems" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentUrl(item.href)"
                            :tooltip="item.title"
                            class="h-9 gap-3 rounded-lg text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground data-[active=true]:bg-primary/20 data-[active=true]:text-white"
                        >
                            <Link :href="item.href" class="flex items-center gap-3">
                                <Icon :icon="item.icon" class="size-4 shrink-0" />
                                <span class="text-sm">{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <!-- User profile at bottom -->
        <SidebarFooter class="border-t border-sidebar-border/60 py-3">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
</template>
