<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Home,
    ReceiptText,
    MapPin,
    Sparkles,
    Store,
    User,
    KeyRound,
    LogOut,
} from 'lucide-vue-next';

defineProps<{
    active:
        | 'overview'
        | 'orders'
        | 'addresses'
        | 'loyalty'
        | 'myPlateful'
        | 'profile'
        | 'password';
}>();

const tabs = computed(() => [
    { key: 'overview', label: 'Overview', href: '/account', icon: Home },
    { key: 'orders', label: 'Orders', href: '/account/orders', icon: ReceiptText },
    {
        key: 'addresses',
        label: 'Addresses',
        href: '/account/addresses',
        icon: MapPin,
    },
    {
        key: 'loyalty',
        label: 'Loyalty',
        href: '/account/loyalty',
        icon: Sparkles,
    },
    {
        key: 'myPlateful',
        label: 'My Plateful',
        href: '/account/my-plateful',
        icon: Store,
    },
    {
        key: 'profile',
        label: 'Profile',
        href: '/account/profile',
        icon: User,
    },
    {
        key: 'password',
        label: 'Password',
        href: '/account/password',
        icon: KeyRound,
    },
]);

const logout = (): void => {
    router.post('/logout');
};
</script>

<template>
    <nav
        class="mb-6 flex flex-wrap items-center gap-1 border-b border-border pb-1"
        aria-label="Account"
    >
        <Link
            v-for="t in tabs"
            :key="t.key"
            :href="t.href"
            class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition"
            :class="
                active === t.key
                    ? 'text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground'
            "
            :style="
                active === t.key
                    ? { borderColor: 'var(--brand-primary)', color: 'var(--brand-primary)' }
                    : {}
            "
        >
            <component :is="t.icon" class="size-4" />
            {{ t.label }}
        </Link>
        <button
            type="button"
            class="ml-auto inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
            @click="logout"
        >
            <LogOut class="size-4" />
            Log out
        </button>
    </nav>
</template>
