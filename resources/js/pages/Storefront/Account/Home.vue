<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AccountTabs from '@/pages/Storefront/Account/AccountTabs.vue';
import { ReceiptText, MapPin, Sparkles, User } from 'lucide-vue-next';

defineProps<{
    restaurant: App.Data.RestaurantData;
    summary: App.Data.AccountSummaryData;
}>();
</script>

<template>
    <div>
        <Head title="My account" />

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <header class="mb-6">
                <h1
                    class="text-2xl font-bold tracking-tight"
                    :style="{ color: 'var(--brand-primary)' }"
                >
                    Hi, {{ summary.userName }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Manage your {{ restaurant.name }} account.
                </p>
            </header>

            <AccountTabs active="overview" />

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    href="/account/orders"
                    class="rounded-lg border border-border bg-card p-5 transition hover:border-foreground/30 hover:shadow-sm"
                >
                    <div class="flex items-center gap-3 text-muted-foreground">
                        <ReceiptText class="size-5" />
                        <span class="text-sm font-medium uppercase tracking-wide">Orders</span>
                    </div>
                    <p class="mt-3 text-3xl font-semibold">
                        {{ summary.orderCount }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        View order history
                    </p>
                </Link>

                <Link
                    href="/account/loyalty"
                    class="rounded-lg border border-border bg-card p-5 transition hover:border-foreground/30 hover:shadow-sm"
                >
                    <div class="flex items-center gap-3 text-muted-foreground">
                        <Sparkles class="size-5" />
                        <span class="text-sm font-medium uppercase tracking-wide">Loyalty</span>
                    </div>
                    <p
                        class="mt-3 text-3xl font-semibold"
                        :style="{ color: 'var(--brand-primary)' }"
                    >
                        {{ summary.loyaltyPoints }}
                        <span class="text-base font-normal text-muted-foreground">pts</span>
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Earn more on every order
                    </p>
                </Link>

                <Link
                    href="/account/addresses"
                    class="rounded-lg border border-border bg-card p-5 transition hover:border-foreground/30 hover:shadow-sm"
                >
                    <div class="flex items-center gap-3 text-muted-foreground">
                        <MapPin class="size-5" />
                        <span class="text-sm font-medium uppercase tracking-wide">Addresses</span>
                    </div>
                    <p class="mt-3 text-3xl font-semibold">
                        {{ summary.addressCount }}
                    </p>
                    <div
                        v-if="summary.defaultAddress"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Default: {{ summary.defaultAddress.street }},
                        {{ summary.defaultAddress.city }}
                    </div>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        No saved addresses yet
                    </p>
                </Link>

                <Link
                    href="/account/profile"
                    class="rounded-lg border border-border bg-card p-5 transition hover:border-foreground/30 hover:shadow-sm sm:col-span-2 lg:col-span-3"
                >
                    <div class="flex items-center gap-3 text-muted-foreground">
                        <User class="size-5" />
                        <span class="text-sm font-medium uppercase tracking-wide">Profile</span>
                    </div>
                    <div class="mt-3 space-y-1 text-sm">
                        <p>{{ summary.userName }}</p>
                        <p class="text-muted-foreground">{{ summary.userEmail }}</p>
                        <p v-if="summary.userPhone" class="text-muted-foreground">
                            {{ summary.userPhone }}
                        </p>
                    </div>
                </Link>
            </div>
        </main>
    </div>
</template>
