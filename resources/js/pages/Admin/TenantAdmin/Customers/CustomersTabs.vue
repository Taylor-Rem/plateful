<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChartColumn, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    index as customersIndex,
    stats as customersStats,
} from '@/routes/admin/restaurant/customers';

const props = defineProps<{
    subdomain: string;
    active: 'list' | 'stats';
}>();

const tabs = computed(() => [
    {
        key: 'list',
        label: 'List',
        href: customersIndex.url(props.subdomain),
        icon: Users,
    },
    {
        key: 'stats',
        label: 'Stats',
        href: customersStats.url(props.subdomain),
        icon: ChartColumn,
    },
]);
</script>

<template>
    <nav
        class="mt-4 flex items-center gap-1 border-b border-border"
        aria-label="Customers"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition"
            :class="
                active === tab.key
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:text-foreground'
            "
            :aria-current="active === tab.key ? 'page' : undefined"
        >
            <component :is="tab.icon" class="size-4" />
            {{ tab.label }}
        </Link>
    </nav>
</template>
