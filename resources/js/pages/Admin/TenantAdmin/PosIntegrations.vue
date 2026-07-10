<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Plug } from 'lucide-vue-next';

type PosProviderCard = {
    provider: string;
    label: string;
    status: string;
    lastError: string | null;
    connectedAt: string | null;
    available: boolean;
};

defineProps<{
    restaurant: App.Data.RestaurantData;
    providers: PosProviderCard[];
}>();

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
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`POS integrations — ${restaurant.name}`" />

        <main class="mx-auto max-w-3xl space-y-6 px-6 py-8">
            <div>
                <h1 class="text-xl font-semibold">POS integrations</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Push online orders straight into your register so the kitchen sees them
                    without a separate tablet.
                </p>
            </div>

            <section class="space-y-3">
                <div
                    v-for="card in providers"
                    :key="card.provider"
                    class="flex items-start justify-between rounded-lg border border-border bg-card p-4"
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
                                    :class="statusClasses[card.status] ?? statusClasses.disconnected"
                                >
                                    {{ statusLabels[card.status] ?? card.status }}
                                </span>
                            </h3>
                            <p v-if="card.lastError" class="mt-1 text-sm text-red-600">
                                {{ card.lastError }}
                            </p>
                        </div>
                    </div>
                    <Button type="button" size="sm" :disabled="!card.available">
                        {{ card.available ? 'Connect' : 'Connect — coming soon' }}
                    </Button>
                </div>
            </section>
        </main>
    </TenantAdminLayout>
</template>
