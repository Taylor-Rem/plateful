<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Truck } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';

type DeliveryProviderCard = {
    provider: string;
    label: string;
    status: string;
    lastError: string | null;
    connectedAt: string | null;
    customerId: string | null;
    storeId: string | null;
    hasWebhookKey: boolean;
    oneClick: boolean;
    available: boolean;
    saveUrl: string | null;
    disconnectUrl: string | null;
};

type Option = { value: string; label: string };

type DeliverySettings = {
    deliveryEnabled: boolean;
    deliveryMode: string | null;
    deliveryFee: string;
    deliveryFeeStrategy: string;
    prepTimeMinutes: number;
    selfDeliveryTipRecipient: string;
    deliveryFallbackAction: string;
    saveUrl: string;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    providers: DeliveryProviderCard[];
    webhookUrl: string;
    settings: DeliverySettings;
    options: {
        modes: Option[];
        feeStrategies: Option[];
        tipRecipients: Option[];
        fallbackActions: Option[];
    };
}>();

const settingsForm = useForm({
    delivery_enabled: props.settings.deliveryEnabled,
    delivery_mode: props.settings.deliveryMode,
    delivery_fee: props.settings.deliveryFee,
    delivery_fee_strategy: props.settings.deliveryFeeStrategy,
    prep_time_minutes: props.settings.prepTimeMinutes,
    self_delivery_tip_recipient: props.settings.selfDeliveryTipRecipient,
    delivery_fallback_action: props.settings.deliveryFallbackAction,
});

const isSelfDelivery = computed(() => settingsForm.delivery_mode === 'self');

const saveSettings = (): void => {
    settingsForm.put(props.settings.saveUrl, { preserveScroll: true });
};

const uber = props.providers.find((p) => p.provider === 'uber') ?? null;

// Open the form automatically when there is nothing connected yet, so the
// first-run path is "paste and go" rather than "find the button".
const showForm = ref(uber?.status !== 'connected');

const form = useForm({
    client_id: '',
    client_secret: '',
    customer_id: '',
    webhook_signing_key: '',
});

const disconnectForm = useForm({});

// One-click providers (DoorDash) have no credential form — the button posts
// straight to saveUrl and Plateful provisions the Business/Store behind it.
const enableForm = useForm({});

const enable = (card: DeliveryProviderCard): void => {
    if (!card.saveUrl) {
        return;
    }

    enableForm.post(card.saveUrl, { preserveScroll: true });
};

const save = (): void => {
    if (!uber?.saveUrl) {
        return;
    }

    form.post(uber.saveUrl, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
};

const disconnect = (card: DeliveryProviderCard): void => {
    if (
        card.disconnectUrl &&
        confirm(
            `Disconnect ${card.label}? New delivery orders will stop being dispatched to couriers.`,
        )
    ) {
        disconnectForm.post(card.disconnectUrl, { preserveScroll: true });
    }
};

const statusLabels: Record<string, string> = {
    connected: 'Connected',
    disconnected: 'Not connected',
    error: 'Error',
};

const statusClasses: Record<string, string> = {
    connected: 'bg-green-100 text-green-800',
    disconnected: 'bg-muted text-muted-foreground',
    error: 'bg-red-100 text-red-800',
};
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`Delivery — ${restaurant.name}`" />

        <main class="mx-auto max-w-3xl space-y-6 px-6 py-8">
            <div>
                <h1 class="text-xl font-semibold">Delivery</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Connect a courier network so delivery orders are dispatched
                    automatically. DoorDash Drive enables in one click; Uber
                    Direct uses your own account.
                </p>
            </div>

            <!-- The behaviour flags. Every one of these lived in the schema
                 with no UI, so a restaurant could have delivery on with no mode
                 set and nobody could tell. -->
            <section class="rounded-lg border border-border bg-card p-5">
                <h2 class="mb-4 text-base font-semibold">Delivery settings</h2>

                <form class="space-y-4" @submit.prevent="saveSettings">
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="settingsForm.delivery_enabled"
                            type="checkbox"
                            class="rounded"
                        />
                        Offer delivery at checkout
                    </label>

                    <template v-if="settingsForm.delivery_enabled">
                        <div>
                            <label class="mb-1 block text-sm font-medium"
                                >Who delivers?</label
                            >
                            <select
                                v-model="settingsForm.delivery_mode"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option :value="null">Choose…</option>
                                <option
                                    v-for="m in options.modes"
                                    :key="m.value"
                                    :value="m.value"
                                >
                                    {{ m.label }}
                                </option>
                            </select>
                            <p
                                v-if="settingsForm.errors.delivery_mode"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ settingsForm.errors.delivery_mode }}
                            </p>
                        </div>

                        <div v-if="!isSelfDelivery">
                            <label class="mb-1 block text-sm font-medium"
                                >How is the delivery fee priced?</label
                            >
                            <select
                                v-model="settingsForm.delivery_fee_strategy"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="s in options.feeStrategies"
                                    :key="s.value"
                                    :value="s.value"
                                >
                                    {{ s.label }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Passing the real cost through means the customer
                                pays what the courier charges. Charging a flat
                                fee means you cover any difference — set it to
                                $0.00 to offer free delivery.
                            </p>
                        </div>

                        <div
                            v-if="
                                isSelfDelivery ||
                                settingsForm.delivery_fee_strategy === 'absorb'
                            "
                        >
                            <label class="mb-1 block text-sm font-medium"
                                >Your delivery fee ($)</label
                            >
                            <input
                                v-model="settingsForm.delivery_fee"
                                type="number"
                                step="0.01"
                                min="0"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                            <p
                                v-if="settingsForm.errors.delivery_fee"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ settingsForm.errors.delivery_fee }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium"
                                >Kitchen prep time (minutes)</label
                            >
                            <input
                                v-model.number="settingsForm.prep_time_minutes"
                                type="number"
                                min="0"
                                max="180"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                How long a ticket takes. Couriers are timed to
                                arrive when the food is ready, and customers are
                                quoted an arrival time that includes it.
                            </p>
                            <p
                                v-if="settingsForm.errors.prep_time_minutes"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ settingsForm.errors.prep_time_minutes }}
                            </p>
                        </div>

                        <div v-if="isSelfDelivery">
                            <label class="mb-1 block text-sm font-medium"
                                >Who gets the tip?</label
                            >
                            <select
                                v-model="
                                    settingsForm.self_delivery_tip_recipient
                                "
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="t in options.tipRecipients"
                                    :key="t.value"
                                    :value="t.value"
                                >
                                    {{ t.label }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-muted-foreground">
                                On courier-network deliveries the tip always
                                goes to the courier, so this only applies to
                                your own drivers.
                            </p>
                        </div>

                        <div v-else>
                            <label class="mb-1 block text-sm font-medium"
                                >If no courier can be found</label
                            >
                            <select
                                v-model="settingsForm.delivery_fallback_action"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="a in options.fallbackActions"
                                    :key="a.value"
                                    :value="a.value"
                                >
                                    {{ a.label }}
                                </option>
                            </select>
                        </div>
                    </template>

                    <Button
                        type="submit"
                        size="sm"
                        :disabled="settingsForm.processing"
                    >
                        {{
                            settingsForm.processing
                                ? 'Saving…'
                                : 'Save settings'
                        }}
                    </Button>
                </form>
            </section>

            <section
                v-for="card in providers"
                :key="card.provider"
                class="rounded-lg border border-border bg-card p-4"
                :data-test="`delivery-provider-${card.provider}`"
            >
                <div class="flex items-start justify-between">
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-0.5 flex h-6 w-6 items-center justify-center rounded-full border border-border text-muted-foreground"
                        >
                            <Truck class="size-3.5" />
                        </span>
                        <div>
                            <h3 class="text-sm font-medium">
                                {{ card.label }}
                                <span
                                    class="ml-2 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        statusClasses[card.status] ??
                                        statusClasses.disconnected
                                    "
                                >
                                    {{
                                        statusLabels[card.status] ?? card.status
                                    }}
                                </span>
                            </h3>
                            <p
                                v-if="
                                    card.customerId &&
                                    card.status === 'connected'
                                "
                                class="mt-1 font-mono text-xs text-muted-foreground"
                            >
                                Customer ID {{ card.customerId }}
                            </p>
                            <p
                                v-if="
                                    card.storeId && card.status === 'connected'
                                "
                                class="mt-1 font-mono text-xs text-muted-foreground"
                            >
                                Store ID {{ card.storeId }}
                            </p>
                            <p
                                v-if="
                                    !card.oneClick &&
                                    card.status === 'connected' &&
                                    !card.hasWebhookKey
                                "
                                class="mt-1 text-sm text-amber-700"
                            >
                                Deliveries will dispatch, but no live courier
                                updates — add a webhook signing key below.
                            </p>
                            <p
                                v-if="card.lastError"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ card.lastError }}
                            </p>
                            <p
                                v-if="!card.available"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                Not available yet.
                            </p>
                        </div>
                    </div>

                    <Button
                        v-if="card.status === 'connected'"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="disconnectForm.processing"
                        @click="disconnect(card)"
                    >
                        Disconnect
                    </Button>
                    <Button
                        v-else-if="card.available && card.oneClick"
                        type="button"
                        size="sm"
                        :disabled="enableForm.processing"
                        @click="enable(card)"
                    >
                        {{ enableForm.processing ? 'Enabling…' : 'Enable delivery' }}
                    </Button>
                    <Button
                        v-else-if="card.available && !showForm"
                        type="button"
                        size="sm"
                        @click="showForm = true"
                    >
                        Connect
                    </Button>
                </div>

                <form
                    v-if="
                        card.provider === 'uber' && card.available && showForm
                    "
                    class="mt-4 space-y-4 border-t border-border pt-4"
                    @submit.prevent="save"
                >
                    <p class="text-sm text-muted-foreground">
                        Find these at
                        <a
                            href="https://direct.uber.com"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline"
                            >direct.uber.com</a
                        >
                        under Management → Developer. We check them with Uber
                        before saving.
                    </p>

                    <div>
                        <label
                            class="mb-1 block text-sm font-medium"
                            for="client_id"
                            >Client ID</label
                        >
                        <input
                            id="client_id"
                            v-model="form.client_id"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm"
                        />
                        <p
                            v-if="form.errors.client_id"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.client_id }}
                        </p>
                    </div>

                    <div>
                        <label
                            class="mb-1 block text-sm font-medium"
                            for="client_secret"
                            >Client Secret</label
                        >
                        <input
                            id="client_secret"
                            v-model="form.client_secret"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm"
                        />
                        <p
                            v-if="form.errors.client_secret"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.client_secret }}
                        </p>
                    </div>

                    <div>
                        <label
                            class="mb-1 block text-sm font-medium"
                            for="customer_id"
                            >Customer ID</label
                        >
                        <input
                            id="customer_id"
                            v-model="form.customer_id"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm"
                        />
                        <p
                            v-if="form.errors.customer_id"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.customer_id }}
                        </p>
                    </div>

                    <div class="border-t border-border pt-4">
                        <label
                            class="mb-1 block text-sm font-medium"
                            for="webhook_signing_key"
                            >Webhook Signing Key
                            <span class="font-normal text-muted-foreground"
                                >— optional</span
                            ></label
                        >
                        <p class="mb-2 text-sm text-muted-foreground">
                            Without this, deliveries still dispatch — you just
                            won't get live courier updates. In your Uber
                            dashboard go to Developer → Webhooks → Create
                            Webhook, select
                            <span class="font-medium">delivery status</span> and
                            <span class="font-medium">courier update</span>, and
                            use this URL:
                        </p>
                        <code
                            class="mb-2 block overflow-x-auto rounded-md bg-muted px-3 py-2 text-xs"
                            >{{ webhookUrl }}</code
                        >
                        <input
                            id="webhook_signing_key"
                            v-model="form.webhook_signing_key"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="
                                card.hasWebhookKey
                                    ? 'Saved — leave blank to keep it'
                                    : ''
                            "
                            class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm"
                        />
                        <p
                            v-if="form.errors.webhook_signing_key"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.webhook_signing_key }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="form.processing"
                        >
                            {{
                                form.processing
                                    ? 'Checking with Uber…'
                                    : 'Save and connect'
                            }}
                        </Button>
                        <Button
                            v-if="card.status === 'connected'"
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="showForm = false"
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </section>
        </main>
    </TenantAdminLayout>
</template>
