<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

const emit = defineEmits<{ advance: [] }>();

const form = useForm({
    _method: 'put' as const,
    pickup_refunds_enabled: props.restaurant.pickupRefundsEnabled ?? false,
    delivery_refunds_enabled: props.restaurant.deliveryRefundsEnabled ?? false,
});

const submit = (): void => {
    form.post(`/${props.restaurant.subdomain}/onboarding/refund-policy`, {
        preserveScroll: true,
        onSuccess: () => emit('advance'),
    });
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <p class="text-sm text-muted-foreground">
            When you cancel a paid order, choose whether the food is refunded to
            the customer. The delivery fee is always refunded when the courier
            can still be called off — this only controls the food. Both are off
            by default; you can change this anytime in Settings.
        </p>

        <div class="grid gap-4">
            <label class="flex items-start gap-3" for="onboarding-pickup-refunds">
                <input
                    id="onboarding-pickup-refunds"
                    v-model="form.pickup_refunds_enabled"
                    type="checkbox"
                    class="mt-1 h-4 w-4 rounded border-input"
                />
                <span class="grid gap-0.5">
                    <span class="text-sm font-medium text-foreground"
                        >Refund food on cancelled pickup orders</span
                    >
                    <span class="text-sm text-muted-foreground"
                        >Customers get their food charge back if you cancel a
                        pickup order.</span
                    >
                </span>
            </label>

            <label
                class="flex items-start gap-3"
                for="onboarding-delivery-refunds"
            >
                <input
                    id="onboarding-delivery-refunds"
                    v-model="form.delivery_refunds_enabled"
                    type="checkbox"
                    class="mt-1 h-4 w-4 rounded border-input"
                />
                <span class="grid gap-0.5">
                    <span class="text-sm font-medium text-foreground"
                        >Refund food on cancelled delivery orders</span
                    >
                    <span class="text-sm text-muted-foreground"
                        >Customers get their food charge back if you cancel a
                        delivery order.</span
                    >
                </span>
            </label>
        </div>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">Save & continue</Button>
            <span
                v-if="form.recentlySuccessful"
                class="text-sm text-emerald-600 dark:text-emerald-400"
                >Saved.</span
            >
        </div>
    </form>
</template>
