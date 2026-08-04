<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plug } from 'lucide-vue-next';
import PageHeader from '@/components/admin/PageHeader.vue';
import { Button } from '@/components/ui/button';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';

type PosProviderCard = {
    provider: string;
    label: string;
    status: string;
    lastError: string | null;
    connectedAt: string | null;
    available: boolean;
    connectUrl: string | null;
    disconnectUrl: string | null;
};

defineProps<{
    restaurant: App.Data.RestaurantData;
    providers: PosProviderCard[];
}>();

const form = useForm({});

const connect = (card: PosProviderCard): void => {
    if (card.connectUrl) {
        form.post(card.connectUrl);
    }
};

const disconnect = (card: PosProviderCard): void => {
    if (
        card.disconnectUrl &&
        confirm(
            `Disconnect ${card.label}? New orders will stop pushing to your register.`,
        )
    ) {
        form.post(card.disconnectUrl);
    }
};

const statusLabels: Record<string, string> = {
    connected: 'Connected',
    disconnected: 'Not connected',
    token_expired: 'Reconnect required',
    error: 'Error',
};

const statusClasses: Record<string, string> = {
    connected: 'bg-green-100 text-green-800',
    disconnected: 'bg-muted text-muted-foreground',
    token_expired: 'bg-amber-100 text-amber-800',
    error: 'bg-red-100 text-red-800',
};

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="POS integrations" />

        <div class="mx-auto max-w-3xl space-y-6">
            <PageHeader
                title="POS integrations"
                description="Push online orders straight into your register so the kitchen sees them without a separate tablet."
            />

            <section class="space-y-3">
                <div
                    v-for="card in providers"
                    :key="card.provider"
                    class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border bg-card p-4"
                    :data-test="`pos-provider-${card.provider}`"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-0.5 flex h-6 w-6 items-center justify-center rounded-full border border-border text-muted-foreground"
                        >
                            <Plug class="size-3.5" />
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
                                v-if="card.lastError"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ card.lastError }}
                            </p>
                        </div>
                    </div>
                    <Button
                        v-if="card.status === 'connected'"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="form.processing"
                        @click="disconnect(card)"
                    >
                        Disconnect
                    </Button>
                    <Button
                        v-else
                        type="button"
                        size="sm"
                        :disabled="!card.available || form.processing"
                        @click="connect(card)"
                    >
                        {{
                            !card.available
                                ? 'Connect — coming soon'
                                : card.status === 'token_expired'
                                  ? 'Reconnect'
                                  : 'Connect'
                        }}
                    </Button>
                </div>
            </section>
        </div>
    </div>
</template>
