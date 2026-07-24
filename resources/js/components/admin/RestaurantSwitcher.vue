<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronsUpDown, Store } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes/admin/restaurant';

type SwitcherRestaurant = { name: string; subdomain: string };

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

const { isMobile } = useSidebar();

const page = usePage<{ adminRestaurants?: SwitcherRestaurant[] }>();

const otherRestaurants = computed<SwitcherRestaurant[]>(() =>
    (page.props.adminRestaurants ?? []).filter(
        (r) => r.subdomain !== props.restaurant.subdomain,
    ),
);
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
                    <template v-if="otherRestaurants.length > 0">
                        <DropdownMenuLabel
                            class="text-xs text-muted-foreground"
                        >
                            Switch restaurant
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="other in otherRestaurants"
                            :key="other.subdomain"
                            as-child
                        >
                            <Link
                                :href="dashboard.url(other.subdomain)"
                                class="w-full cursor-pointer"
                            >
                                <Store class="mr-2 size-4" />
                                {{ other.name }}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </template>
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
