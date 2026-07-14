<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Truck } from 'lucide-vue-next';
import { ref } from 'vue';

type DeliveryProviderCard = {
    provider: string;
    label: string;
    status: string;
    lastError: string | null;
    connectedAt: string | null;
    customerId: string | null;
    hasWebhookKey: boolean;
    available: boolean;
    saveUrl: string | null;
    disconnectUrl: string | null;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    providers: DeliveryProviderCard[];
    webhookUrl: string;
}>();

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

const save = (): void => {
    if (!uber?.saveUrl) return;
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
                    automatically. You keep your own account — Uber bills you
                    directly for each delivery.
                </p>
            </div>

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
                                    {{ statusLabels[card.status] ?? card.status }}
                                </span>
                            </h3>
                            <p
                                v-if="card.customerId && card.status === 'connected'"
                                class="mt-1 font-mono text-xs text-muted-foreground"
                            >
                                Customer ID {{ card.customerId }}
                            </p>
                            <p
                                v-if="card.status === 'connected' && !card.hasWebhookKey"
                                class="mt-1 text-sm text-amber-700"
                            >
                                Deliveries will dispatch, but no live courier
                                updates — add a webhook signing key below.
                            </p>
                            <p v-if="card.lastError" class="mt-1 text-sm text-red-600">
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
                        v-else-if="card.available && !showForm"
                        type="button"
                        size="sm"
                        @click="showForm = true"
                    >
                        Connect
                    </Button>
                </div>

                <form
                    v-if="card.provider === 'uber' && card.available && showForm"
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
                        <label class="mb-1 block text-sm font-medium" for="client_id"
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
                        <p v-if="form.errors.client_id" class="mt-1 text-xs text-destructive">
                            {{ form.errors.client_id }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="client_secret"
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
                        <label class="mb-1 block text-sm font-medium" for="customer_id"
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
                        <Button type="submit" size="sm" :disabled="form.processing">
                            {{ form.processing ? 'Checking with Uber…' : 'Save and connect' }}
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
