<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plug } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

type LaunchRestaurant = {
    id: number;
    name: string;
    subdomain: string;
    connectUrl: string;
};

defineProps<{
    merchantId: string | null;
    restaurants: LaunchRestaurant[];
}>();

const form = useForm({});

const connect = (restaurant: LaunchRestaurant): void => {
    form.post(restaurant.connectUrl);
};
</script>

<template>
    <div
        class="flex min-h-screen items-center justify-center bg-background px-6 py-10 text-foreground"
    >
        <Head title="Connect Clover" />
        <div class="w-full max-w-lg">
            <div class="text-center">
                <span
                    class="mx-auto flex h-10 w-10 items-center justify-center rounded-full border border-border text-muted-foreground"
                >
                    <Plug class="size-4" />
                </span>
                <h1 class="mt-4 text-2xl font-semibold text-foreground">
                    Connect Clover to a restaurant
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Choose which Plateful restaurant this Clover account belongs
                    to. Paid online orders will print to its register.
                </p>
                <p
                    v-if="merchantId"
                    class="mt-1 text-xs text-muted-foreground"
                    data-test="clover-merchant-id"
                >
                    Clover merchant {{ merchantId }}
                </p>
            </div>

            <ul class="mt-8 space-y-3">
                <li
                    v-for="restaurant in restaurants"
                    :key="restaurant.id"
                    class="flex items-center justify-between gap-3 rounded-lg border border-border bg-card p-4"
                    :data-test="`clover-launch-${restaurant.subdomain}`"
                >
                    <div>
                        <p class="text-sm font-medium">{{ restaurant.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ restaurant.subdomain }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="form.processing"
                        @click="connect(restaurant)"
                    >
                        Connect
                    </Button>
                </li>
            </ul>

            <p
                v-if="restaurants.length === 0"
                class="mt-6 rounded-lg border border-border bg-card p-6 text-center text-sm text-muted-foreground"
            >
                You don't manage any restaurants yet, so there is nothing to
                connect Clover to.
            </p>
        </div>
    </div>
</template>
