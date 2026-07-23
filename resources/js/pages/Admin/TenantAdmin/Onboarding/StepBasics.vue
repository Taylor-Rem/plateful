<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    /** Combined state + average local rate, keyed by 2-letter state code. */
    taxRateEstimates: Record<string, number>;
}>();

const emit = defineEmits<{ advance: [] }>();

const form = useForm({
    _method: 'put' as const,
    name: props.restaurant.name,
    description: props.restaurant.description ?? '',
    phone: props.restaurant.phone ?? '',
    primary_color: props.restaurant.primaryColor ?? '#111827',
    secondary_color: props.restaurant.secondaryColor ?? '#ffffff',
    logo: null as File | null,
    street: props.restaurant.street ?? '',
    city: props.restaurant.city ?? '',
    state: props.restaurant.state ?? '',
    postal_code: props.restaurant.postalCode ?? '',
    tax_rate_percent: props.restaurant.taxRatePercent ?? 0,
});

// ----- Sales tax -----
// A rate the owner already set is theirs; a guess must never overwrite it.
// Anything still sitting at the 0.00 column default is treated as unset.
const ownerSetTaxRate = ref((props.restaurant.taxRatePercent ?? 0) > 0);

const estimatedTaxRate = computed<number | null>(
    () => props.taxRateEstimates[form.state.trim().toUpperCase()] ?? null,
);

/** The field is currently showing a guess rather than the owner's own number. */
const taxRateIsEstimate = computed(
    () => !ownerSetTaxRate.value && estimatedTaxRate.value !== null,
);

// Re-suggest as the address is typed, right up until the owner takes over.
watch(
    estimatedTaxRate,
    (rate) => {
        if (!ownerSetTaxRate.value && rate !== null) {
            form.tax_rate_percent = rate;
        }
    },
    { immediate: true },
);

const newLogoPreview = ref<string | null>(null);
const currentLogo = computed(
    () => newLogoPreview.value ?? props.restaurant.logoMediumUrl,
);

const onLogoChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    form.logo = file;

    if (newLogoPreview.value) {
        URL.revokeObjectURL(newLogoPreview.value);
    }

    newLogoPreview.value = file ? URL.createObjectURL(file) : null;
};

const submit = (): void => {
    form.post(`/${props.restaurant.subdomain}/onboarding/basics`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('advance'),
    });
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <p class="text-sm text-muted-foreground">
            This is what customers see first — a logo and a sentence or two go a
            long way. Everything here can be changed later.
        </p>

        <div class="grid gap-2">
            <Label for="basics-name">Restaurant name</Label>
            <Input id="basics-name" v-model="form.name" type="text" required />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="basics-description">Description</Label>
            <textarea
                id="basics-description"
                v-model="form.description"
                rows="3"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                placeholder="Wood-fired pizza and homemade pasta in the heart of Brooklyn."
            ></textarea>
            <InputError :message="form.errors.description" />
        </div>

        <div class="grid gap-2">
            <Label for="basics-logo">Logo</Label>
            <div class="flex items-center gap-4">
                <img
                    v-if="currentLogo"
                    :src="currentLogo"
                    alt="Logo preview"
                    class="size-16 rounded-md border border-border object-cover"
                />
                <input
                    id="basics-logo"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="text-sm"
                    @change="onLogoChange"
                />
            </div>
            <InputError :message="form.errors.logo" />
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="grid gap-2">
                <Label for="basics-primary-color">Brand color</Label>
                <input
                    id="basics-primary-color"
                    v-model="form.primary_color"
                    type="color"
                    class="h-10 w-full cursor-pointer rounded-md border border-input bg-background px-1"
                />
                <InputError :message="form.errors.primary_color" />
            </div>
            <div class="grid gap-2">
                <Label for="basics-secondary-color">Accent color</Label>
                <input
                    id="basics-secondary-color"
                    v-model="form.secondary_color"
                    type="color"
                    class="h-10 w-full cursor-pointer rounded-md border border-input bg-background px-1"
                />
                <InputError :message="form.errors.secondary_color" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="basics-phone">Phone</Label>
            <Input
                id="basics-phone"
                v-model="form.phone"
                type="tel"
                autocomplete="tel"
            />
            <InputError :message="form.errors.phone" />
        </div>

        <fieldset class="grid gap-4">
            <legend class="text-sm font-medium">Address</legend>
            <div class="grid gap-2">
                <Label for="basics-street">Street</Label>
                <Input id="basics-street" v-model="form.street" type="text" />
                <InputError :message="form.errors.street" />
            </div>
            <div class="grid grid-cols-4 gap-4">
                <div class="col-span-2 grid gap-2">
                    <Label for="basics-city">City</Label>
                    <Input id="basics-city" v-model="form.city" type="text" />
                    <InputError :message="form.errors.city" />
                </div>
                <div class="grid gap-2">
                    <Label for="basics-state">State</Label>
                    <Input
                        id="basics-state"
                        v-model="form.state"
                        type="text"
                        maxlength="2"
                        placeholder="NY"
                    />
                    <InputError :message="form.errors.state" />
                </div>
                <div class="grid gap-2">
                    <Label for="basics-postal">ZIP</Label>
                    <Input
                        id="basics-postal"
                        v-model="form.postal_code"
                        type="text"
                    />
                    <InputError :message="form.errors.postal_code" />
                </div>
            </div>
        </fieldset>

        <div class="grid gap-2">
            <Label for="basics-tax-rate">Sales tax rate %</Label>
            <Input
                id="basics-tax-rate"
                v-model="form.tax_rate_percent"
                type="number"
                step="0.01"
                min="0"
                max="30"
                class="max-w-40"
                data-test="basics-tax-rate"
                @input="ownerSetTaxRate = true"
            />
            <p
                v-if="taxRateIsEstimate"
                class="text-xs text-muted-foreground"
                data-test="basics-tax-estimate-note"
            >
                Estimated from the average combined rate in
                {{ form.state.trim().toUpperCase() }} — a guess based on your
                location, not a guaranteed rate. Local rates vary by city and
                county, and many states tax prepared food differently. Confirm
                the right rate for your address and change it here.
            </p>
            <p v-else class="text-xs text-muted-foreground">
                Applied to the food subtotal at checkout. Plateful's fee never
                applies to tax — it's passed through to you in full.
            </p>
            <InputError :message="form.errors.tax_rate_percent" />
        </div>

        <div class="flex items-center justify-between">
            <button
                type="button"
                class="text-sm text-muted-foreground underline hover:text-foreground"
                @click="emit('advance')"
            >
                Skip for now
            </button>
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-basics-button"
            >
                {{ form.processing ? 'Saving...' : 'Save & continue' }}
            </Button>
        </div>
    </form>
</template>
