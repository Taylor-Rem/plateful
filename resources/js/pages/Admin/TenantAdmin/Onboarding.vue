<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Check, Copy, ExternalLink, PartyPopper } from 'lucide-vue-next';
import QRCode from 'qrcode';
import { computed, ref, watchEffect } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StepBasics from './Onboarding/StepBasics.vue';
import StepHours from './Onboarding/StepHours.vue';
import StepMenu from './Onboarding/StepMenu.vue';
import StepPayments from './Onboarding/StepPayments.vue';
import StepReview from './Onboarding/StepReview.vue';

type Step = {
    key: string;
    title: string;
    description: string;
    complete: boolean;
    required: boolean;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    onboarding: {
        status: string;
        pendingCustomDomain: string | null;
        customDomainRequestedAt: string | null;
        onboardingCompletedAt: string | null;
        stripeStatus: string | null;
    };
    steps: Step[];
    canGoLive: boolean;
    menuPresets: { value: string; label: string }[];
    menuSummary: { categories: number; items: number };
    menuImport: {
        id: number;
        status: 'queued' | 'processing' | 'needs_review' | 'failed';
        error: string | null;
        itemCount: number;
    } | null;
    menuImportLimits: { maxFiles: number; maxFileKb: number };
    primaryDomain: string;
}>();

const stepOrder = ['basics', 'hours', 'menu', 'stripe', 'review'] as const;

const firstIncomplete = (): string =>
    props.steps.find((s) => !s.complete)?.key ?? 'review';

const currentKey = ref<string>(firstIncomplete());

const currentIndex = computed(() =>
    stepOrder.indexOf(currentKey.value as (typeof stepOrder)[number]),
);

const goto = (key: string): void => {
    currentKey.value = key;
};

const advance = (): void => {
    const next = stepOrder[currentIndex.value + 1];

    if (next) {
        currentKey.value = next;
    }
};

const setupSteps = computed(() =>
    props.steps.filter((s) => s.key !== 'review'),
);
const completedCount = computed(
    () => setupSteps.value.filter((s) => s.complete).length,
);

const currentStep = computed(
    () => props.steps.find((s) => s.key === currentKey.value) ?? props.steps[0],
);

// ----- Custom domain ("More options") -----
const showDomain = ref(false);
const domainForm = useForm<{ pending_custom_domain: string }>({
    pending_custom_domain: props.onboarding.pendingCustomDomain ?? '',
});
function submitDomain() {
    domainForm.post(`/${props.restaurant.subdomain}/onboarding/custom-domain`, {
        preserveScroll: true,
        onSuccess: () => {
            showDomain.value = false;
        },
    });
}

// ----- Live celebration -----
const qrCanvas = ref<HTMLCanvasElement | null>(null);
watchEffect(() => {
    if (props.restaurant.isLive && qrCanvas.value) {
        QRCode.toCanvas(qrCanvas.value, props.restaurant.publicUrl, {
            width: 168,
            margin: 1,
        });
    }
});

const copied = ref(false);
const copyUrl = async (): Promise<void> => {
    await navigator.clipboard.writeText(props.restaurant.publicUrl);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
};
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head :title="`Set up ${restaurant.name}`" />

        <header class="border-b border-border bg-card">
            <div
                class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4"
            >
                <h1 class="text-lg font-semibold">
                    {{
                        restaurant.isLive
                            ? restaurant.name
                            : `Set up ${restaurant.name}`
                    }}
                </h1>
                <div class="flex items-center gap-4">
                    <a
                        v-if="!restaurant.isLive"
                        :href="`/${restaurant.subdomain}/onboarding/preview`"
                        target="_blank"
                        class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-80"
                        data-test="preview-site-link"
                    >
                        Preview your site
                        <ExternalLink class="size-3.5" />
                    </a>
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
            <!-- ============== Live: celebration ============== -->
            <template v-if="restaurant.isLive">
                <section
                    class="rounded-lg border border-primary/30 bg-primary/5 p-8 text-center"
                    data-test="live-celebration"
                >
                    <PartyPopper class="mx-auto size-10 text-primary" />
                    <h2 class="mt-4 text-2xl font-semibold">You're live!</h2>
                    <p
                        class="mx-auto mt-2 max-w-md text-sm text-muted-foreground"
                    >
                        {{ restaurant.name }} is open for orders and listed on
                        the Plateful homepage. Share your link — or let
                        customers scan the code from a flyer or table tent.
                    </p>

                    <div
                        class="mx-auto mt-6 flex max-w-md items-center justify-center gap-2 rounded-md border border-border bg-background px-4 py-3"
                    >
                        <a
                            :href="restaurant.publicUrl"
                            target="_blank"
                            class="truncate font-mono text-sm text-primary hover:opacity-80"
                        >
                            {{ restaurant.publicUrl }}
                        </a>
                        <button
                            type="button"
                            class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                            :aria-label="copied ? 'Copied' : 'Copy link'"
                            @click="copyUrl"
                        >
                            <Check
                                v-if="copied"
                                class="size-4 text-green-600"
                            />
                            <Copy v-else class="size-4" />
                        </button>
                    </div>

                    <canvas
                        ref="qrCanvas"
                        class="mx-auto mt-6 rounded-md border border-border bg-white p-2"
                    ></canvas>

                    <div class="mt-6 flex items-center justify-center gap-3">
                        <Button as-child>
                            <a :href="restaurant.publicUrl" target="_blank">
                                Open your site
                            </a>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="`/${restaurant.subdomain}/dashboard`">
                                Go to dashboard
                            </Link>
                        </Button>
                    </div>
                </section>
            </template>

            <!-- ============== Pre-live: wizard ============== -->
            <template v-else>
                <section>
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Welcome to Plateful
                    </p>
                    <h2 class="mt-1 text-2xl font-semibold">
                        Let's get your storefront live
                    </h2>
                    <div class="mt-4 flex items-center gap-3">
                        <div
                            class="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-primary transition-all"
                                :style="{
                                    width: `${(completedCount / setupSteps.length) * 100}%`,
                                }"
                            ></div>
                        </div>
                        <span
                            class="text-sm text-muted-foreground"
                            data-test="wizard-progress"
                        >
                            {{ completedCount }} of {{ setupSteps.length }} done
                        </span>
                    </div>
                </section>

                <nav class="flex flex-wrap gap-2" aria-label="Setup steps">
                    <button
                        v-for="(step, idx) in steps"
                        :key="step.key"
                        type="button"
                        :class="[
                            'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition',
                            step.key === currentKey
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card text-muted-foreground hover:text-foreground',
                        ]"
                        :data-test="`wizard-step-tab-${step.key}`"
                        @click="goto(step.key)"
                    >
                        <span
                            :class="[
                                'flex size-4 items-center justify-center rounded-full text-[10px]',
                                step.complete
                                    ? 'bg-green-100 text-green-700'
                                    : step.key === currentKey
                                      ? 'bg-primary-foreground/20'
                                      : 'bg-muted',
                            ]"
                        >
                            <Check v-if="step.complete" class="size-3" />
                            <template v-else>{{ idx + 1 }}</template>
                        </span>
                        {{ step.title }}
                    </button>
                </nav>

                <section
                    class="rounded-lg border border-border bg-card p-6"
                    :data-test="`wizard-step-${currentKey}`"
                >
                    <div class="mb-5 flex items-baseline justify-between">
                        <h3 class="text-lg font-semibold">
                            {{ currentStep.title }}
                        </h3>
                        <span
                            v-if="!currentStep.required"
                            class="text-xs text-muted-foreground"
                        >
                            Optional
                        </span>
                    </div>

                    <StepBasics
                        v-if="currentKey === 'basics'"
                        :restaurant="restaurant"
                        @advance="advance"
                    />
                    <StepHours
                        v-else-if="currentKey === 'hours'"
                        :restaurant="restaurant"
                        @advance="advance"
                    />
                    <StepMenu
                        v-else-if="currentKey === 'menu'"
                        :restaurant="restaurant"
                        :menu-presets="menuPresets"
                        :menu-summary="menuSummary"
                        :menu-import="menuImport"
                        :menu-import-limits="menuImportLimits"
                        @advance="advance"
                    />
                    <StepPayments
                        v-else-if="currentKey === 'stripe'"
                        :restaurant="restaurant"
                        :stripe-status="onboarding.stripeStatus"
                        :description="currentStep.description"
                        @advance="advance"
                    />
                    <StepReview
                        v-else
                        :restaurant="restaurant"
                        :steps="steps"
                        :can-go-live="canGoLive"
                        :primary-domain="primaryDomain"
                        @goto="goto"
                    />
                </section>
            </template>

            <!-- ============== More options ============== -->
            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold">More options</h2>

                <div class="mt-4 space-y-6">
                    <div>
                        <h3 class="text-sm font-medium">Custom domain</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Use your own domain (like
                            <code>pizzajoint.com</code>) instead of a Plateful
                            address. We set up DNS and TLS, usually within a
                            business day.
                        </p>

                        <p
                            v-if="restaurant.customDomain"
                            class="mt-3 inline-flex items-center rounded-md bg-green-100 px-2 py-1 text-xs font-medium text-green-800"
                        >
                            Live at {{ restaurant.customDomain }}
                        </p>
                        <p
                            v-else-if="onboarding.pendingCustomDomain"
                            class="mt-3 inline-flex items-center rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800"
                            data-test="custom-domain-pending"
                        >
                            Pending: {{ onboarding.pendingCustomDomain }}
                        </p>

                        <Button
                            v-if="!showDomain && !restaurant.customDomain"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="mt-3"
                            @click="showDomain = true"
                        >
                            {{
                                onboarding.pendingCustomDomain
                                    ? 'Change request'
                                    : 'Request custom domain'
                            }}
                        </Button>

                        <form
                            v-if="showDomain"
                            class="mt-3 space-y-2"
                            @submit.prevent="submitDomain"
                        >
                            <Label for="pending_custom_domain">Domain</Label>
                            <Input
                                id="pending_custom_domain"
                                v-model="domainForm.pending_custom_domain"
                                placeholder="pizzajoint.com"
                                required
                            />
                            <p
                                v-if="domainForm.errors.pending_custom_domain"
                                class="text-sm text-destructive"
                            >
                                {{ domainForm.errors.pending_custom_domain }}
                            </p>
                            <div class="flex gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="domainForm.processing"
                                >
                                    Request
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="showDomain = false"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium">Point-of-sale</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Push online orders straight into your Square or
                            Clover register.
                            <a
                                :href="`/${restaurant.subdomain}/settings/pos`"
                                class="font-medium text-primary underline hover:opacity-80"
                                >Connect your POS</a
                            >.
                        </p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
