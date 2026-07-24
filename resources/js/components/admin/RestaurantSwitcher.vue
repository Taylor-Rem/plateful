<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronsUpDown, Store } from 'lucide-vue-next';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

const { isMobile } = useSidebar();
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                    >
                        <div
                            class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-lg bg-sidebar-primary text-sidebar-primary-foreground"
                        >
                            <img
                                v-if="restaurant.logoThumbUrl"
                                :src="restaurant.logoThumbUrl"
                                :alt="`${restaurant.name} logo`"
                                class="size-8 object-cover"
                            />
                            <Store v-else class="size-4" />
                        </div>
                        <div
                            class="grid flex-1 text-left text-sm leading-tight"
                        >
                            <span class="truncate font-semibold">{{
                                restaurant.name
                            }}</span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                                >{{ restaurant.subdomain }}</span
                            >
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="isMobile ? 'bottom' : 'right'"
                    align="start"
                    :side-offset="4"
                >
                    <DropdownMenuItem as-child>
                        <Link href="/" class="w-full cursor-pointer">
                            All restaurants
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
