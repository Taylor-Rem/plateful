<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ChartNoAxesColumn,
    Home,
    Newspaper,
    ShieldCheck,
    Store,
    Users,
} from 'lucide-vue-next';
import AdminNavGroups from '@/components/admin/AdminNavGroups.vue';
import AdminNavUser from '@/components/admin/AdminNavUser.vue';
import AppLogo from '@/components/AppLogo.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index as adminsIndex } from '@/routes/admin/super/admins';
import { index as earningsIndex } from '@/routes/admin/super/earnings';
import { index as restaurantsIndex } from '@/routes/admin/super/restaurants';
import { index as storiesIndex } from '@/routes/admin/super/stories';
import { index as usersIndex } from '@/routes/admin/super/users';
import type { NavGroup } from '@/types';

const groups: NavGroup[] = [
    {
        label: 'Platform',
        items: [
            { title: 'Restaurants', href: restaurantsIndex(), icon: Store },
            {
                title: 'Earnings',
                href: earningsIndex(),
                icon: ChartNoAxesColumn,
            },
            { title: 'Admins', href: adminsIndex(), icon: ShieldCheck },
            { title: 'Users', href: usersIndex(), icon: Users },
            { title: 'Stories', href: storiesIndex(), icon: Newspaper },
        ],
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <AdminNavGroups :groups="groups" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child tooltip="My restaurants">
                        <Link href="/">
                            <Home />
                            <span>My restaurants</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <AdminNavUser />
        </SidebarFooter>
    </Sidebar>
</template>
