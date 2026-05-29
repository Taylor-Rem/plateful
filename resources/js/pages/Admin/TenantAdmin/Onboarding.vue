<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Check, Circle, ExternalLink } from 'lucide-vue-next';

type Step = {
    key: string;
    title: string;
    description: string;
    href: string;
    complete: boolean;
    required: boolean;
    stripeStatus?: string | null;
};

const props = defineProps<{
    restaurant: {
        id: number;
        name: string;
        subdomain: string;
        status: string;
        customDomain: string | null;
        pendingCustomDomain: string | null;
        customDomainRequestedAt: string | null;
        onboardingCompletedAt: string | null;
        isLive: boolean;
    };
    steps: Step[];
    canGoLive: boolean;
    primaryDomain: string;
}>();

const goLiveForm = useForm({});
function goLive() {
    goLiveForm.post(`/${props.restaurant.subdomain}/onboarding/go-live`);
}

const stripeForm = useForm({});
function connectStripe() {
    stripeForm.post(`/${props.restaurant.subdomain}/onboarding/stripe/connect`);
}

const showDomain = ref(false);
const domainForm = useForm<{ pending_custom_domain: string }>({
    pending_custom_domain: props.restaurant.pendingCustomDomain ?? '',
});
function submitDomain() {
    domainForm.post(`/${props.restaurant.subdomain}/onboarding/custom-domain`, {
        preserveScroll: true,
        onSuccess: () => {
            showDomain.value = false;
        },
    });
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head :title="`Set up ${restaurant.name}`" />

        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <h1 class="text-lg font-semibold">Set up {{ restaurant.name }}</h1>
                <div class="flex items-center gap-4">
                    <AppearanceTabs />
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Log out
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-3xl space-y-8 px-6 py-8">
            <section class="rounded-lg border border-border bg-card p-6">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Welcome to Plateful</p>
                <h2 class="mt-1 text-2xl font-semibold">Let's get your storefront live</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Work through the steps below. Required steps must be done before you can go live;
                    the rest you can come back to anytime.
                </p>

                <div class="mt-6 rounded-md border border-border bg-background p-4 text-sm">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Your storefront URL</p>
                    <p class="mt-1 font-mono">{{ restaurant.subdomain }}.{{ primaryDomain }}</p>
                </div>
            </section>

            <section class="space-y-3">
                <div
                    v-for="step in steps"
                    :key="step.key"
                    class="flex items-start justify-between rounded-lg border border-border bg-card p-4"
                    :data-test="`onboarding-step-${step.key}`"
                >
                    <div class="flex items-start gap-3">
                        <span
                            :class="[
                                'mt-0.5 flex h-6 w-6 items-center justify-center rounded-full',
                                step.complete
                                    ? 'bg-green-100 text-green-700'
                                    : 'border border-border text-muted-foreground',
                            ]"
                        >
                            <Check v-if="step.complete" class="size-4" />
                            <Circle v-else class="size-3" />
                        </span>
                        <div>
                            <h3 class="text-sm font-medium">
                                {{ step.title }}
                                <span
                                    v-if="!step.required"
                                    class="ml-2 text-xs font-normal text-muted-foreground"
                                >
                                    Optional
                                </span>
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">{{ step.description }}</p>
                        </div>
                    </div>
                    <template v-if="step.key === 'stripe'">
                        <a
                            v-if="step.complete"
                            :href="`/${restaurant.subdomain}/onboarding/stripe/dashboard`"
                            class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-80"
                        >
                            Manage on Stripe
                            <ExternalLink class="size-3.5" />
                        </a>
                        <Button
                            v-else
                            type="button"
                            size="sm"
                            :disabled="stripeForm.processing"
                            @click="connectStripe"
                            data-test="connect-stripe-button"
                        >
                            {{ step.stripeStatus === 'pending' ? 'Continue setup' : 'Connect Stripe' }}
                        </Button>
                    </template>
                    <a
                        v-else
                        :href="step.href"
                        class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-80"
                    >
                        {{ step.complete ? 'Edit' : 'Set up' }}
                        <ExternalLink class="size-3.5" />
                    </a>
                </div>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold">Custom domain</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Want to use your own domain (like <code>pizzajoint.com</code>) instead of a Plateful subdomain?
                    Request it here — we'll set up DNS and TLS, usually within a business day.
                </p>

                <p
                    v-if="restaurant.customDomain"
                    class="mt-4 inline-flex items-center rounded-md bg-green-100 px-2 py-1 text-xs font-medium text-green-800"
                >
                    Live at {{ restaurant.customDomain }}
                </p>
                <p
                    v-else-if="restaurant.pendingCustomDomain"
                    class="mt-4 inline-flex items-center rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800"
                    data-test="custom-domain-pending"
                >
                    Pending: {{ restaurant.pendingCustomDomain }}
                </p>

                <Button v-if="!showDomain" type="button" variant="outline" class="mt-4" @click="showDomain = true">
                    {{ restaurant.pendingCustomDomain ? 'Change request' : 'Request custom domain' }}
                </Button>

                <form v-else @submit.prevent="submitDomain" class="mt-4 space-y-2">
                    <Label for="pending_custom_domain">Domain</Label>
                    <Input
                        id="pending_custom_domain"
                        v-model="domainForm.pending_custom_domain"
                        placeholder="pizzajoint.com"
                        required
                    />
                    <p v-if="domainForm.errors.pending_custom_domain" class="text-sm text-red-600">
                        {{ domainForm.errors.pending_custom_domain }}
                    </p>
                    <div class="flex gap-2">
                        <Button type="submit" :disabled="domainForm.processing">Request</Button>
                        <Button type="button" variant="ghost" @click="showDomain = false">Cancel</Button>
                    </div>
                </form>
            </section>

            <section class="rounded-lg border border-primary/30 bg-primary/5 p-6">
                <h2 class="text-base font-semibold">Go live</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    When you're ready, flip the switch. Your storefront will be open for orders and your restaurant
                    will appear on the Plateful homepage.
                </p>

                <Button
                    type="button"
                    class="mt-4"
                    :disabled="!canGoLive || goLiveForm.processing"
                    @click="goLive"
                    data-test="go-live-button"
                >
                    {{ canGoLive ? 'Go live now' : 'Finish required steps first' }}
                </Button>
                <p v-if="goLiveForm.errors.go_live" class="mt-2 text-sm text-red-600">
                    {{ goLiveForm.errors.go_live }}
                </p>
            </section>
        </main>
    </div>
</template>
