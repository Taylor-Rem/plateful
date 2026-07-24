<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavGroup, NavItem } from '@/types';

const props = defineProps<{
    groups: NavGroup[];
}>();

const { currentUrl } = useCurrentUrl();

// Wayfinder URLs are host-absolute ("//admin.example.test/..."), so normalize
// every href down to its pathname before comparing against the current page.
const pathOf = (href: NavItem['href']): string => {
    const url = toUrl(href);

    if (url.startsWith('//')) {
        return new URL(`http:${url}`).pathname;
    }

    if (url.startsWith('http')) {
        return new URL(url).pathname;
    }

    return url;
};

// Longest matching prefix wins, so /x/settings/delivery activates Delivery
// without also activating Settings, and /x/menu/templates activates Templates
// without Menu.
const activePath = computed(() => {
    const current = currentUrl.value;
    let best: string | null = null;

    for (const group of props.groups) {
        for (const item of group.items) {
            const path = pathOf(item.href);

            if (current === path || current.startsWith(`${path}/`)) {
                if (best === null || path.length > best.length) {
                    best = path;
                }
            }
        }
    }

    return best;
});

const isActive = (item: NavItem): boolean =>
    pathOf(item.href) === activePath.value;
</script>

<template>
    <SidebarGroup
        v-for="group in groups"
        :key="group.label ?? group.items[0]?.title"
        class="px-2 py-0"
    >
        <SidebarGroupLabel v-if="group.label">{{
            group.label
        }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in group.items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isActive(item)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge v-if="item.attention">
                    <span
                        class="inline-block size-2 rounded-full bg-amber-500"
                        aria-hidden="true"
                        data-test="setup-attention-dot"
                    />
                </SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
