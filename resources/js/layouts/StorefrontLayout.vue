<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ShoppingCart, User } from 'lucide-vue-next';
import { Toaster } from '@/components/ui/sonner';
import CartDrawer from '@/components/Storefront/CartDrawer.vue';

type StorefrontPageProps = {
    restaurant?: App.Data.RestaurantData;
    cart?: App.Data.CartData | null;
    auth?: { user?: { name: string } | null } | null;
};

const page = usePage<StorefrontPageProps>();
const restaurant = computed(() => page.props.restaurant);
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const cartCount = computed(() => page.props.cart?.itemCount ?? 0);

const drawerOpen = ref(false);
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <nav
            class="sticky top-0 z-40 border-b border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80"
        >
            <div class="mx-auto flex h-14 max-w-5xl items-center justify-between px-6">
                <Link
                    href="/"
                    class="flex items-center gap-3 text-sm font-semibold tracking-tight"
                    :style="{ color: 'var(--brand-primary)' }"
                >
                    <img
                        v-if="restaurant?.logoThumbUrl"
                        :src="restaurant.logoThumbUrl"
                        :alt="`${restaurant.name} logo`"
                        class="size-8 rounded-md bg-white object-contain p-0.5"
                    />
                    <span>{{ restaurant?.name ?? 'Menu' }}</span>
                </Link>

                <div class="flex items-center gap-1">
                    <a
                        href="#menu"
                        class="rounded-md px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                    >
                        Menu
                    </a>
                    <button
                        type="button"
                        class="relative rounded-md p-2 text-foreground hover:bg-muted"
                        aria-label="Cart"
                        @click="drawerOpen = true"
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
                        class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                    >
                        <User class="size-4" />
                        Sign in
                    </Link>
                    <Link
                        v-else
                        href="/account"
                        class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                    >
                        <User class="size-4" />
                        Account
                    </Link>
                </div>
            </div>
        </nav>

        <slot />

        <CartDrawer v-model:open="drawerOpen" />
        <Toaster />
    </div>
</template>
