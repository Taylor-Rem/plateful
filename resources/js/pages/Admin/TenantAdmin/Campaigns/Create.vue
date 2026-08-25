<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { ArrowLeft, Send, Users } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SectionCard from '@/components/admin/SectionCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';
import {
    count as campaignsCount,
    index as campaignsIndex,
    preview as campaignsPreview,
    store as campaignsStore,
    test as campaignsTest,
} from '@/routes/admin/restaurant/campaigns';

type AudienceType = 'all' | 'lapsed' | 'regulars';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    optedInCount: number;
    sendBlocker: string | null;
}>();

const form = useForm({
    subject: '',
    preheader: '',
    headline: '',
    body: '',
    offer_callout: '',
    cta_label: '',
    cta_url: '',
    audience: {
        type: 'all' as AudienceType,
        days: 30,
        min_orders: 3,
    },
    scheduled_at: '',
});

const errors = computed(() => form.errors as Record<string, string>);

// Wayfinder emits host-absolute URLs (the admin routes are domain-routed);
// the JSON endpoints are always on the page's own origin, so XHR goes
// through the path alone.
function samePath(url: string): string {
    const parsed = new URL(url, window.location.href);

    return parsed.pathname + parsed.search;
}

// ——— Live recipient count ——————————————————————————————————————————————
const countHttp = useHttp<
    { type: string; days: number | null; min_orders: number | null },
    { count: number }
>({ type: 'all', days: null, min_orders: null });

const recipientCount = ref<number | null>(null);

async function refreshCount(): Promise<void> {
    countHttp.type = form.audience.type;
    countHttp.days =
        form.audience.type === 'lapsed' ? form.audience.days : null;
    countHttp.min_orders =
        form.audience.type === 'regulars' ? form.audience.min_orders : null;

    try {
        const response = await countHttp.get(
            samePath(campaignsCount.url(props.restaurant.subdomain)),
        );
        recipientCount.value = response?.count ?? null;
    } catch {
        recipientCount.value = null;
    }
}

let countTimer: number | undefined;
watch(
    () => [form.audience.type, form.audience.days, form.audience.min_orders],
    () => {
        window.clearTimeout(countTimer);
        countTimer = window.setTimeout(() => void refreshCount(), 300);
    },
    { immediate: true },
);

// ——— Server-rendered preview ———————————————————————————————————————————
const previewHttp = useHttp<
    {
        subject: string;
        preheader: string;
        headline: string;
        body: string;
        offer_callout: string;
        cta_label: string;
        cta_url: string;
        audience: { type: AudienceType; days: number; min_orders: number };
    },
    { html: string }
>({
    subject: '',
    preheader: '',
    headline: '',
    body: '',
    offer_callout: '',
    cta_label: '',
    cta_url: '',
    audience: { type: 'all', days: 30, min_orders: 3 },
});

const previewHtml = ref<string>('');
const canPreview = computed(
    () =>
        form.subject.trim() !== '' &&
        form.headline.trim() !== '' &&
        form.body.trim() !== '',
);

async function refreshPreview(): Promise<void> {
    if (!canPreview.value) {
        return;
    }

    previewHttp.subject = form.subject;
    previewHttp.preheader = form.preheader;
    previewHttp.headline = form.headline;
    previewHttp.body = form.body;
    previewHttp.offer_callout = form.offer_callout;
    previewHttp.cta_label = form.cta_label;
    previewHttp.cta_url = form.cta_url;
    previewHttp.audience = { ...form.audience };

    try {
        const response = await previewHttp.post(
            samePath(campaignsPreview.url(props.restaurant.subdomain)),
        );
        previewHtml.value = response?.html ?? '';
    } catch {
        // Invalid interim state (e.g. a half-typed CTA URL) — keep the last
        // good preview rather than erroring mid-compose.
    }
}

let previewTimer: number | undefined;
watch(
    () => [
        form.subject,
        form.preheader,
        form.headline,
        form.body,
        form.offer_callout,
        form.cta_label,
        form.cta_url,
    ],
    () => {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(() => void refreshPreview(), 600);
    },
);

onBeforeUnmount(() => {
    window.clearTimeout(countTimer);
    window.clearTimeout(previewTimer);
});

// ——— Actions ————————————————————————————————————————————————————————————
const audienceOptions: { type: AudienceType; label: string }[] = [
    { type: 'all', label: 'All opted-in customers' },
    { type: 'regulars', label: 'Regulars' },
    { type: 'lapsed', label: 'Lapsed customers' },
];

function submit(action: 'save' | 'send' | 'schedule'): void {
    if (action === 'send') {
        const target =
            recipientCount.value === null
                ? 'your opted-in customers'
                : `${recipientCount.value} ${recipientCount.value === 1 ? 'customer' : 'customers'}`;

        if (!window.confirm(`Send this campaign to ${target} now?`)) {
            return;
        }
    }

    form.transform((data) => ({ ...data, action })).post(
        campaignsStore.url(props.restaurant.subdomain),
    );
}

function sendTest(): void {
    form.transform((data) => data).post(
        campaignsTest.url(props.restaurant.subdomain),
        { preserveScroll: true, preserveState: true },
    );
}

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="New campaign" />

        <PageHeader
            title="New campaign"
            description="Compose an email offer for your opted-in customers."
        >
            <template #actions>
                <Link
                    :href="campaignsIndex(props.restaurant.subdomain)"
                    class="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
                >
                    <ArrowLeft class="size-4" />
                    Back to campaigns
                </Link>
            </template>
        </PageHeader>

        <div
            v-if="sendBlocker"
            class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
        >
            {{ sendBlocker }} You can still compose and save a draft.
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="grid content-start gap-6">
                <SectionCard title="Email content">
                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="subject">Subject line</Label>
                            <Input
                                id="subject"
                                v-model="form.subject"
                                placeholder="Half-price pizza this Tuesday"
                                maxlength="150"
                            />
                            <InputError :message="errors.subject" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="preheader">
                                Preview text
                                <span class="text-muted-foreground"
                                    >(optional)</span
                                >
                            </Label>
                            <Input
                                id="preheader"
                                v-model="form.preheader"
                                placeholder="Shown after the subject in most inboxes"
                                maxlength="150"
                            />
                            <InputError :message="errors.preheader" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="headline">Headline</Label>
                            <Input
                                id="headline"
                                v-model="form.headline"
                                placeholder="Slow Tuesday? Not anymore."
                                maxlength="150"
                            />
                            <InputError :message="errors.headline" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="body">Message</Label>
                            <textarea
                                id="body"
                                v-model="form.body"
                                rows="6"
                                maxlength="5000"
                                placeholder="Tell your customers what's on..."
                                class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus:border-ring focus:ring-1 focus:ring-ring focus:outline-none"
                            />
                            <InputError :message="errors.body" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="offer-callout">
                                Offer callout
                                <span class="text-muted-foreground"
                                    >(optional)</span
                                >
                            </Label>
                            <Input
                                id="offer-callout"
                                v-model="form.offer_callout"
                                placeholder="50% off all pies, dine-in or pickup"
                                maxlength="200"
                            />
                            <InputError :message="errors.offer_callout" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="cta-label">
                                    Button label
                                    <span class="text-muted-foreground"
                                        >(optional)</span
                                    >
                                </Label>
                                <Input
                                    id="cta-label"
                                    v-model="form.cta_label"
                                    placeholder="Order now"
                                    maxlength="60"
                                />
                                <InputError :message="errors.cta_label" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cta-url">
                                    Button link
                                    <span class="text-muted-foreground"
                                        >(optional)</span
                                    >
                                </Label>
                                <Input
                                    id="cta-url"
                                    v-model="form.cta_url"
                                    type="url"
                                    :placeholder="restaurant.publicUrl"
                                />
                                <InputError :message="errors.cta_url" />
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            The button links to your storefront unless you set a
                            custom link. An unsubscribe link and your
                            restaurant's address are always included in the
                            footer.
                        </p>
                    </div>
                </SectionCard>

                <SectionCard
                    title="Audience"
                    description="Only customers who opted into your emails can be reached."
                >
                    <div class="grid gap-4">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="option in audienceOptions"
                                :key="option.type"
                                type="button"
                                class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                                :class="
                                    form.audience.type === option.type
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground hover:bg-muted/80'
                                "
                                @click="form.audience.type = option.type"
                            >
                                {{ option.label }}
                            </button>
                        </div>

                        <div
                            v-if="form.audience.type === 'regulars'"
                            class="grid gap-2"
                        >
                            <Label for="min-orders">Minimum orders</Label>
                            <Input
                                id="min-orders"
                                v-model.number="form.audience.min_orders"
                                type="number"
                                min="1"
                                max="100"
                                class="max-w-32"
                            />
                            <InputError
                                :message="errors['audience.min_orders']"
                            />
                        </div>

                        <div
                            v-if="form.audience.type === 'lapsed'"
                            class="grid gap-2"
                        >
                            <Label for="lapsed-days">
                                No order in the last (days)
                            </Label>
                            <Input
                                id="lapsed-days"
                                v-model.number="form.audience.days"
                                type="number"
                                min="1"
                                max="365"
                                class="max-w-32"
                            />
                            <InputError :message="errors['audience.days']" />
                        </div>

                        <p
                            class="inline-flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Users class="size-4" />
                            <span v-if="countHttp.processing"
                                >Counting recipients…</span
                            >
                            <span v-else-if="recipientCount !== null">
                                <span
                                    class="font-semibold text-foreground tabular-nums"
                                    >{{ recipientCount }}</span
                                >
                                {{
                                    recipientCount === 1
                                        ? 'recipient'
                                        : 'recipients'
                                }}
                                will get this email
                            </span>
                        </p>
                    </div>
                </SectionCard>

                <SectionCard title="Send">
                    <div class="grid gap-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                :disabled="
                                    form.processing || sendBlocker !== null
                                "
                                @click="submit('send')"
                            >
                                <Send class="size-4" />
                                Send now
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="form.processing"
                                @click="submit('save')"
                            >
                                Save draft
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="!canPreview || form.processing"
                                @click="sendTest"
                            >
                                Send test to my email
                            </Button>
                        </div>

                        <div class="grid gap-2">
                            <Label for="scheduled-at"
                                >Or schedule for later</Label
                            >
                            <div class="flex flex-wrap items-center gap-3">
                                <Input
                                    id="scheduled-at"
                                    v-model="form.scheduled_at"
                                    type="datetime-local"
                                    class="max-w-60"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="
                                        !form.scheduled_at ||
                                        form.processing ||
                                        sendBlocker !== null
                                    "
                                    @click="submit('schedule')"
                                >
                                    Schedule
                                </Button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Times are in your restaurant's timezone ({{
                                    restaurant.timezone
                                }}).
                            </p>
                            <InputError :message="errors.scheduled_at" />
                        </div>
                    </div>
                </SectionCard>
            </div>

            <SectionCard
                title="Preview"
                description="Exactly what lands in the inbox, footer included."
            >
                <div
                    class="overflow-hidden rounded-md border border-border bg-white"
                >
                    <iframe
                        v-if="previewHtml"
                        :srcdoc="previewHtml"
                        sandbox=""
                        title="Email preview"
                        class="h-[600px] w-full"
                    />
                    <div
                        v-else
                        class="flex h-[600px] items-center justify-center px-8 text-center text-sm text-muted-foreground"
                    >
                        Fill in a subject, headline and message to see the email
                        preview.
                    </div>
                </div>
            </SectionCard>
        </div>
    </div>
</template>
