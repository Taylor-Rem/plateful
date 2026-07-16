<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Circle, ExternalLink } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

type Step = {
    key: string;
    title: string;
    description: string;
    complete: boolean;
    required: boolean;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    steps: Step[];
    canGoLive: boolean;
    primaryDomain: string;
}>();

const emit = defineEmits<{ goto: [key: string] }>();

const setupSteps = computed(() =>
    props.steps.filter((s) => s.key !== 'review'),
);

const goLiveForm = useForm({});
const goLive = (): void => {
    goLiveForm.post(`/${props.restaurant.subdomain}/onboarding/go-live`);
};

// `go_live` arrives from the server, not the (empty) form data, so it isn't
// in the inferred error type.
const goLiveError = computed(
    () => (goLiveForm.errors as Record<string, string>).go_live,
);
</script>

<template>
    <div class="space-y-6">
        <div class="rounded-md border border-border bg-background p-4 text-sm">
            <p class="text-xs tracking-wide text-muted-foreground uppercase">
                Your storefront
            </p>
            <p class="mt-1 flex items-center gap-2 font-mono">
                {{ restaurant.subdomain }}.{{ primaryDomain }}
                <a
                    :href="`/${restaurant.subdomain}/onboarding/preview`"
                    target="_blank"
                    class="inline-flex items-center gap-1 font-sans text-xs font-medium text-primary hover:opacity-80"
                >
                    Preview
                    <ExternalLink class="size-3" />
                </a>
            </p>
        </div>

        <ul class="space-y-2">
            <li
                v-for="step in setupSteps"
                :key="step.key"
                class="flex items-center justify-between rounded-lg border border-border bg-card px-4 py-3"
            >
                <span class="flex items-center gap-3 text-sm">
                    <span
                        :class="[
                            'flex size-5 items-center justify-center rounded-full',
                            step.complete
                                ? 'bg-green-100 text-green-700'
                                : 'border border-border text-muted-foreground',
                        ]"
                    >
                        <Check v-if="step.complete" class="size-3" />
                        <Circle v-else class="size-2" />
                    </span>
                    {{ step.title }}
                    <span
                        v-if="!step.required"
                        class="text-xs text-muted-foreground"
                        >Optional</span
                    >
                </span>
                <button
                    v-if="!step.complete"
                    type="button"
                    class="text-sm font-medium text-primary hover:opacity-80"
                    @click="emit('goto', step.key)"
                >
                    Finish
                </button>
            </li>
        </ul>

        <div class="rounded-lg border border-primary/30 bg-primary/5 p-6">
            <h3 class="text-base font-semibold">Ready when you are</h3>
            <p class="mt-1 text-sm text-muted-foreground">
                Going live opens your storefront for orders and lists your
                restaurant on the Plateful homepage. You can pause anytime from
                settings.
            </p>
            <Button
                type="button"
                class="mt-4"
                :disabled="!canGoLive || goLiveForm.processing"
                data-test="go-live-button"
                @click="goLive"
            >
                {{ canGoLive ? 'Go live now' : 'Finish required steps first' }}
            </Button>
            <p v-if="goLiveError" class="mt-2 text-sm text-destructive">
                {{ goLiveError }}
            </p>
        </div>
    </div>
</template>
