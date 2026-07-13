<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Check, ExternalLink } from 'lucide-vue-next';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    stripeStatus: string | null;
    description: string;
}>();

const emit = defineEmits<{ advance: [] }>();

const stripeForm = useForm({});
const connectStripe = (): void => {
    stripeForm.post(`/${props.restaurant.subdomain}/onboarding/stripe/connect`);
};
</script>

<template>
    <div class="space-y-4">
        <template v-if="restaurant.isStripeReady">
            <div
                class="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950"
            >
                <span
                    class="mt-0.5 flex size-6 items-center justify-center rounded-full bg-green-100 text-green-700"
                >
                    <Check class="size-4" />
                </span>
                <p class="text-sm font-medium">
                    Stripe is connected — you're ready to take payments.
                </p>
            </div>
            <div class="flex items-center justify-between">
                <a
                    :href="`/${restaurant.subdomain}/onboarding/stripe/dashboard`"
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-80"
                >
                    Manage on Stripe
                    <ExternalLink class="size-3.5" />
                </a>
                <Button type="button" @click="emit('advance')">Continue</Button>
            </div>
        </template>

        <template v-else>
            <p class="text-sm text-muted-foreground">{{ description }}</p>
            <p class="text-sm text-muted-foreground">
                Stripe handles the payment details — bank account, identity,
                payouts. It takes about five minutes and you'll come right back
                here when you're done.
            </p>
            <Button
                type="button"
                :disabled="stripeForm.processing"
                data-test="connect-stripe-button"
                @click="connectStripe"
            >
                {{ stripeStatus === 'pending' ? 'Continue Stripe setup' : 'Connect Stripe' }}
            </Button>
        </template>
    </div>
</template>
