<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, provide, ref, watch } from 'vue';
import { Menu as MenuIcon, ShoppingCart, User, X } from 'lucide-vue-next';
import { Toaster } from '@/components/ui/sonner';
import CartDrawer from '@/components/Storefront/CartDrawer.vue';
import AdminBar from '@/pages/Storefront/components/AdminBar.vue';
import Footer from '@/pages/Storefront/components/Footer.vue';
import SocialLinksEditDrawer from '@/pages/Storefront/components/SocialLinksEditDrawer.vue';

type StorefrontPageProps = {
    restaurant?: App.Data.RestaurantData;
    cart?: App.Data.CartData | null;
    auth?: {
        user?: { name: string } | null;
        canEditSite?: boolean;
    } | null;
};

const page = usePage<StorefrontPageProps>();
const restaurant = computed(() => page.props.restaurant);
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const canEditSite = computed(() => Boolean(page.props.auth?.canEditSite));
const cartCount = computed(() => page.props.cart?.itemCount ?? 0);
const currentPath = computed(() => new URL(page.url, 'http://placeholder').pathname);
const isOnPath = (path: string): boolean => currentPath.value === path;

// ----- Edit mode (single source of truth, persisted) -----
const EDIT_MODE_KEY = 'plateful:storefront:editMode';
const editMode = ref(false);

onMounted(() => {
    if (canEditSite.value && typeof window !== 'undefined') {
        editMode.value = window.localStorage.getItem(EDIT_MODE_KEY) === '1';
    }
});

const setEditMode = (value: boolean): void => {
    editMode.value = value;
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(EDIT_MODE_KEY, value ? '1' : '0');
    }
};

watch(canEditSite, (value) => {
    if (!value) editMode.value = false;
});

provide('storefrontEditMode', editMode);

// ----- Drawers -----
const cartDrawerOpen = ref(false);
const socialDrawerOpen = ref(false);
const mobileNavOpen = ref(false);

const navLinks = [
    { label: 'Home', href: '/', kind: 'link' as const },
    { label: 'Menu', href: '/menu', kind: 'link' as const },
    { label: 'About', href: '/#about', kind: 'anchor' as const, matchPath: '/' },
    { label: 'Visit', href: '/#location', kind: 'anchor' as const, matchPath: '/' },
];

const isActive = (link: (typeof navLinks)[number]): boolean => {
    if (link.kind === 'link') return isOnPath(link.href);
    return false;
};
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <AdminBar
            v-if="canEditSite"
            :edit-mode="editMode"
            @update:edit-mode="setEditMode"
        />

        <nav
            class="sticky top-0 z-40 border-b border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80"
        >
            <div class="mx-auto flex h-14 max-w-5xl items-center justify-between gap-3 px-4 sm:px-6">
                <Link
                    href="/"
                    class="flex shrink-0 items-center gap-2 text-sm font-semibold tracking-tight"
                    :style="{ color: 'var(--brand-primary)' }"
                >
                    <img
                        v-if="restaurant?.logoThumbUrl"
                        :src="restaurant.logoThumbUrl"
                        :alt="`${restaurant.name} logo`"
                        class="size-8 rounded-md bg-white object-contain p-0.5"
                    />
                    <span class="max-w-[12rem] truncate">{{ restaurant?.name ?? 'Menu' }}</span>
                </Link>

                <div class="hidden items-center gap-1 md:flex">
                    <template v-for="link in navLinks" :key="link.href">
                        <Link
                            v-if="link.kind === 'link'"
                            :href="link.href"
                            class="rounded-md px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                            :class="{ 'bg-muted/60': isActive(link) }"
                        >
                            {{ link.label }}
                        </Link>
                        <a
                            v-else
                            :href="link.href"
                            class="rounded-md px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                        >
                            {{ link.label }}
                        </a>
                    </template>
                </div>

                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="relative rounded-md p-2 text-foreground hover:bg-muted"
                        aria-label="Cart"
                        @click="cartDrawerOpen = true"
                    >
                        <ShoppingCart class="size-5" />
                        <span
                            v-if="cartCount > 0"
                            class="absolute -right-1 -top-1 grid size-4 place-items-center rounded-full text-[10px] font-semibold"
                            :style="{
                                backgroundColor: 'var(--brand-primary)',
                                color: 'var(--brand-primary-foreground)',
                            }"
                        >
                            {{ cartCount }}
                        </span>
                    </button>
                    <Link
                        v-if="!isAuthenticated"
                        href="/login"
                        class="hidden items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted sm:flex"
                    >
                        <User class="size-4" />
                        Sign in
                    </Link>
                    <Link
                        v-else
                        href="/account"
                        class="hidden items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted sm:flex"
                    >
                        <User class="size-4" />
                        Account
                    </Link>
                    <button
                        type="button"
                        class="rounded-md p-2 text-foreground hover:bg-muted md:hidden"
                        :aria-label="mobileNavOpen ? 'Close menu' : 'Open menu'"
                        @click="mobileNavOpen = !mobileNavOpen"
                    >
                        <component :is="mobileNavOpen ? X : MenuIcon" class="size-5" />
                    </button>
                </div>
            </div>

            <div v-if="mobileNavOpen" class="border-t border-border md:hidden">
                <div class="mx-auto flex max-w-5xl flex-col gap-1 px-4 py-3 sm:px-6">
                    <template v-for="link in navLinks" :key="link.href">
                        <Link
                            v-if="link.kind === 'link'"
                            :href="link.href"
                            class="rounded-md px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
                            @click="mobileNavOpen = false"
                        >
                            {{ link.label }}
                        </Link>
                        <a
                            v-else
                            :href="link.href"
                            class="rounded-md px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
                            @click="mobileNavOpen = false"
                        >
                            {{ link.label }}
                        </a>
                    </template>
                    <Link
                        v-if="!isAuthenticated"
                        href="/login"
                        class="rounded-md px-3 py-2 text-sm font-medium text-foreground hover:bg-muted sm:hidden"
                        @click="mobileNavOpen = false"
                    >
                        Sign in
                    </Link>
                    <Link
                        v-else
                        href="/account"
                        class="rounded-md px-3 py-2 text-sm font-medium text-foreground hover:bg-muted sm:hidden"
                        @click="mobileNavOpen = false"
                    >
                        Account
                    </Link>
                </div>
            </div>
        </nav>

        <slot />

        <Footer
            v-if="restaurant"
            :restaurant="restaurant"
            :edit-mode="canEditSite && editMode"
            @edit-social="socialDrawerOpen = true"
        />

        <CartDrawer v-model:open="cartDrawerOpen" />
        <SocialLinksEditDrawer
            v-if="restaurant && canEditSite"
            v-model:open="socialDrawerOpen"
            :restaurant="restaurant"
        />
        <Toaster />
    </div>
</template>
