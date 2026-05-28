<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    restaurant: {
        id: number;
        name: string;
        subdomain: string;
        status: string;
        isSuspended: boolean;
    };
    billing: {
        onTrial: boolean;
        trialEndsAt: string | null;
        trialDaysLeft: number | null;
        hasSubscription: boolean;
        isSubscribed: boolean;
        subscriptionStatus: string | null;
        subscriptionEndsAt: string | null;
        priceConfigured: boolean;
    };
}>();

const checkoutForm = useForm({});
function startCheckout() {
    checkoutForm.post(`/${props.restaurant.subdomain}/billing/checkout`);
}

const portalForm = useForm({});
function openPortal() {
    portalForm.post(`/${props.restaurant.subdomain}/billing/portal`);
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return '—';
    }
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head :title="`Billing — ${restaurant.name}`" />

        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link :href="`/${restaurant.subdomain}/dashboard`" class="text-sm text-muted-foreground hover:text-foreground">← Dashboard</Link>
                    <h1 class="text-lg font-semibold">Billing</h1>
                </div>
                <AppearanceTabs />
            </div>
        </header>

        <main class="mx-auto max-w-3xl space-y-6 px-6 py-8">
            <section
                v-if="restaurant.isSuspended"
                class="rounded-lg border border-red-300 bg-red-50 p-6 text-red-900"
                data-test="suspended-banner"
            >
                <h2 class="text-base font-semibold">Your restaurant is suspended</h2>
                <p class="mt-1 text-sm">
                    Your storefront is offline until you subscribe. Customers see an "Unavailable" page right now.
                </p>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold">Subscription status</h2>

                <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Plan</dt>
                        <dd>{{ billing.isSubscribed ? 'Plateful — paid' : (billing.onTrial ? 'Free trial' : 'Inactive') }}</dd>
                    </div>
                    <div v-if="billing.onTrial">
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Trial ends</dt>
                        <dd data-test="trial-ends">
                            {{ formatDate(billing.trialEndsAt) }}
                            <span v-if="billing.trialDaysLeft !== null" class="ml-1 text-muted-foreground">
                                ({{ billing.trialDaysLeft }} day{{ billing.trialDaysLeft === 1 ? '' : 's' }} left)
                            </span>
                        </dd>
                    </div>
                    <div v-if="billing.subscriptionStatus">
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Stripe status</dt>
                        <dd>{{ billing.subscriptionStatus }}</dd>
                    </div>
                    <div v-if="billing.subscriptionEndsAt">
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Cancels on</dt>
                        <dd>{{ formatDate(billing.subscriptionEndsAt) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold">
                    {{ billing.isSubscribed ? 'Manage subscription' : 'Subscribe' }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    <template v-if="billing.isSubscribed">
                        Update your card, view invoices, or cancel from Stripe's secure billing portal.
                    </template>
                    <template v-else-if="billing.onTrial">
                        Start your subscription now so service continues uninterrupted when your trial ends.
                    </template>
                    <template v-else>
                        Subscribe to bring your restaurant back online.
                    </template>
                </p>

                <p v-if="!billing.priceConfigured" class="mt-3 rounded-md bg-amber-100 px-3 py-2 text-xs text-amber-800">
                    Billing isn't configured on this environment yet (no <code>PLATFORM_STRIPE_PRICE</code> set).
                </p>

                <div class="mt-4 flex flex-wrap gap-3">
                    <Button
                        v-if="!billing.isSubscribed"
                        type="button"
                        :disabled="checkoutForm.processing || !billing.priceConfigured"
                        @click="startCheckout"
                        data-test="subscribe-button"
                    >
                        Subscribe
                    </Button>
                    <Button
                        v-if="billing.hasSubscription || billing.isSubscribed"
                        type="button"
                        variant="outline"
                        :disabled="portalForm.processing"
                        @click="openPortal"
                        data-test="portal-button"
                    >
                        Open billing portal
                    </Button>
                </div>
            </section>
        </main>
    </div>
</template>
