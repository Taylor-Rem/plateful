<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    Banknote,
    BookOpen,
    BookUser,
    ChefHat,
    ClipboardCheck,
    Clock,
    ExternalLink,
    LayoutGrid,
    LayoutTemplate,
    Plug,
    Settings,
    ShoppingBag,
    Truck,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AdminNavGroups from '@/components/admin/AdminNavGroups.vue';
import AdminNavUser from '@/components/admin/AdminNavUser.vue';
import RestaurantSwitcher from '@/components/admin/RestaurantSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes/admin/restaurant';
import { index as customersIndex } from '@/routes/admin/restaurant/customers';
import { show as deliveryShow } from '@/routes/admin/restaurant/delivery';
import { edit as hoursEdit } from '@/routes/admin/restaurant/hours';
import { index as kitchenIndex } from '@/routes/admin/restaurant/kitchen';
import { index as membersIndex } from '@/routes/admin/restaurant/members';
import { index as menuIndex } from '@/routes/admin/restaurant/menu';
import { show as onboardingShow } from '@/routes/admin/restaurant/onboarding';
import { index as ordersIndex } from '@/routes/admin/restaurant/orders';
import { index as payoutsIndex } from '@/routes/admin/restaurant/payouts';
import { show as posShow } from '@/routes/admin/restaurant/pos';
import { edit as settingsEdit } from '@/routes/admin/restaurant/settings';
import { index as templatesIndex } from '@/routes/admin/restaurant/templates';
import type { NavGroup } from '@/types';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

const page = usePage<{ currentRestaurantRole: string | null }>();
const isAdmin = computed(() => page.props.currentRestaurantRole === 'admin');

const setupComplete = computed(
    () => props.restaurant.isLive && props.restaurant.isStripeReady,
);

const groups = computed<NavGroup[]>(() => {
    const restaurant = props.restaurant.subdomain;

    const operations: NavGroup = {
        label: 'Operations',
        items: [
            {
                title: 'Dashboard',
                href: dashboard(restaurant),
                icon: LayoutGrid,
            },
            {
                title: 'Orders',
                href: ordersIndex(restaurant),
                icon: ShoppingBag,
            },
            { title: 'Kitchen', href: kitchenIndex(restaurant), icon: ChefHat },
            { title: 'Hours', href: hoursEdit(restaurant), icon: Clock },
        ],
    };

    const menu: NavGroup = {
        label: 'Menu',
        items: [
            { title: 'Menu', href: menuIndex(restaurant), icon: BookOpen },
            ...(isAdmin.value
                ? [
                      {
                          title: 'Templates',
                          href: templatesIndex(restaurant),
                          icon: LayoutTemplate,
                      },
                  ]
                : []),
        ],
    };

    if (!isAdmin.value) {
        return [operations, menu];
    }

    const manage: NavGroup = {
        label: 'Manage',
        items: [
            {
                title: setupComplete.value ? 'Setup' : 'Finish setup',
                href: onboardingShow(restaurant),
                icon: ClipboardCheck,
                attention: !setupComplete.value,
            },
            {
                title: 'Customers',
                href: customersIndex(restaurant),
                icon: BookUser,
            },
            {
                title: 'Payouts',
                href: payoutsIndex(restaurant),
                icon: Banknote,
            },
            { title: 'Team', href: membersIndex(restaurant), icon: Users },
            { title: 'Delivery', href: deliveryShow(restaurant), icon: Truck },
            { title: 'POS', href: posShow(restaurant), icon: Plug },
            {
                title: 'Settings',
                href: settingsEdit(restaurant),
                icon: Settings,
            },
        ],
    };

    return [operations, menu, manage];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <RestaurantSwitcher :restaurant="restaurant" />
        </SidebarHeader>

        <SidebarContent>
            <AdminNavGroups :groups="groups" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child tooltip="Visit storefront">
                        <a
                            :href="restaurant.publicUrl"
                            target="_blank"
                            rel="noopener"
                        >
                            <ExternalLink />
                            <span>Visit storefront</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <AdminNavUser />
        </SidebarFooter>
    </Sidebar>
</template>
