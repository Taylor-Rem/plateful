<script setup lang="ts">
import { Form, Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

const form = useForm({
    _method: 'put' as const,
    name: props.restaurant.name,
    description: props.restaurant.description ?? '',
    primary_color: props.restaurant.primaryColor ?? '#111827',
    secondary_color: props.restaurant.secondaryColor ?? '#ffffff',
    email: props.restaurant.email ?? '',
    phone: props.restaurant.phone ?? '',
    logo: null as File | null,
    remove_logo: false as boolean,
    tax_rate_percent: props.restaurant.taxRatePercent ?? 0,
    delivery_fee: ((props.restaurant.deliveryFeeCents ?? 0) / 100).toFixed(2),
    pickup_refunds_enabled: props.restaurant.pickupRefundsEnabled ?? false,
    delivery_refunds_enabled: props.restaurant.deliveryRefundsEnabled ?? false,
});

const newLogoPreview = ref<string | null>(null);

const currentLogo = computed(() => props.restaurant.logoMediumUrl);

const onLogoChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    form.logo = file;
    form.remove_logo = false;

    if (newLogoPreview.value) {
        URL.revokeObjectURL(newLogoPreview.value);
    }

    newLogoPreview.value = file ? URL.createObjectURL(file) : null;
};

const clearNewLogo = (): void => {
    form.logo = null;

    if (newLogoPreview.value) {
        URL.revokeObjectURL(newLogoPreview.value);
    }

    newLogoPreview.value = null;
};

const markRemoveLogo = (): void => {
    form.remove_logo = true;
    clearNewLogo();
};

const submit = (): void => {
    form.post(`/${props.restaurant.subdomain}/settings`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            clearNewLogo();
            form.remove_logo = false;
        },
    });
};
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Settings`" />
        <h2 class="text-2xl font-semibold text-foreground">Settings</h2>

        <form class="mt-6 max-w-2xl space-y-6" @submit.prevent="submit">
            <section class="rounded-lg border border-border bg-card p-5">
                <h3 class="text-base font-medium text-foreground">Branding</h3>

                <div class="mt-4 grid gap-2">
                    <Label>Logo</Label>
                    <div class="flex items-start gap-4">
                        <div
                            class="flex size-24 items-center justify-center overflow-hidden rounded-md border border-dashed border-border bg-muted/30"
                        >
                            <img
                                v-if="newLogoPreview"
                                :src="newLogoPreview"
                                alt="New logo preview"
                                class="size-full object-contain"
                            />
                            <img
                                v-else-if="currentLogo && !form.remove_logo"
                                :src="currentLogo"
                                alt="Current logo"
                                class="size-full object-contain"
                            />
                            <span
                                v-else
                                class="px-2 text-center text-xs text-muted-foreground"
                                >No logo</span
                            >
                        </div>
                        <div class="flex-1 space-y-2">
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                @change="onLogoChange"
                            />
                            <p class="text-xs text-muted-foreground">
                                JPEG, PNG, or WebP up to 5 MB.
                            </p>
                            <div
                                v-if="currentLogo && !form.remove_logo"
                                class="flex items-center gap-2"
                            >
                                <button
                                    type="button"
                                    class="text-xs text-destructive hover:opacity-80"
                                    @click="markRemoveLogo"
                                >
                                    Remove logo
                                </button>
                            </div>
                            <p
                                v-if="form.remove_logo"
                                class="text-xs text-amber-600 dark:text-amber-400"
                            >
                                Will remove logo on save.
                                <button
                                    type="button"
                                    class="underline"
                                    @click="form.remove_logo = false"
                                >
                                    Undo
                                </button>
                            </p>
                            <InputError :message="form.errors.logo" />
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="primary-color">Primary color</Label>
                        <div class="flex items-center gap-2">
                            <input
                                id="primary-color-picker"
                                v-model="form.primary_color"
                                type="color"
                                class="h-9 w-12 cursor-pointer rounded border border-input bg-background"
                            />
                            <Input
                                id="primary-color"
                                v-model="form.primary_color"
                                class="flex-1"
                            />
                        </div>
                        <InputError :message="form.errors.primary_color" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="secondary-color">Secondary color</Label>
                        <div class="flex items-center gap-2">
                            <input
                                id="secondary-color-picker"
                                v-model="form.secondary_color"
                                type="color"
                                class="h-9 w-12 cursor-pointer rounded border border-input bg-background"
                            />
                            <Input
                                id="secondary-color"
                                v-model="form.secondary_color"
                                class="flex-1"
                            />
                        </div>
                        <InputError :message="form.errors.secondary_color" />
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-border bg-card p-5">
                <h3 class="text-base font-medium text-foreground">
                    Restaurant details
                </h3>
                <div class="mt-4 grid gap-4">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="description">Description</Label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus:border-ring focus:ring-1 focus:ring-ring focus:outline-none"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="email">Email</Label>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                            />
                            <InputError :message="form.errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="phone">Phone</Label>
                            <Input id="phone" v-model="form.phone" />
                            <InputError :message="form.errors.phone" />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="tax-rate">Sales tax rate (%)</Label>
                            <Input
                                id="tax-rate"
                                v-model="form.tax_rate_percent"
                                type="number"
                                step="0.01"
                                min="0"
                                max="30"
                            />
                            <InputError
                                :message="form.errors.tax_rate_percent"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="delivery-fee">Delivery fee ($)</Label>
                            <Input
                                id="delivery-fee"
                                v-model="form.delivery_fee"
                                type="number"
                                step="0.01"
                                min="0"
                                max="500"
                            />
                            <InputError :message="form.errors.delivery_fee" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-border bg-card p-5">
                <h3 class="text-base font-medium text-foreground">
                    Refund policy
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    When you cancel a paid order, choose whether the food is
                    refunded to the customer. The delivery fee is always
                    refunded when the courier can still be called off — this
                    only controls the food. Both are off by default.
                </p>
                <div class="mt-4 grid gap-4">
                    <label class="flex items-start gap-3" for="pickup-refunds">
                        <input
                            id="pickup-refunds"
                            v-model="form.pickup_refunds_enabled"
                            type="checkbox"
                            class="mt-1 h-4 w-4 rounded border-input"
                        />
                        <span class="grid gap-0.5">
                            <span class="text-sm font-medium text-foreground"
                                >Refund food on cancelled pickup orders</span
                            >
                            <span class="text-sm text-muted-foreground"
                                >Customers get their food charge back if you
                                cancel a pickup order.</span
                            >
                        </span>
                    </label>
                    <label
                        class="flex items-start gap-3"
                        for="delivery-refunds"
                    >
                        <input
                            id="delivery-refunds"
                            v-model="form.delivery_refunds_enabled"
                            type="checkbox"
                            class="mt-1 h-4 w-4 rounded border-input"
                        />
                        <span class="grid gap-0.5">
                            <span class="text-sm font-medium text-foreground"
                                >Refund food on cancelled delivery orders</span
                            >
                            <span class="text-sm text-muted-foreground"
                                >Customers get their food charge back if you
                                cancel a delivery order.</span
                            >
                        </span>
                    </label>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing"
                    >Save settings</Button
                >
                <span
                    v-if="form.recentlySuccessful"
                    class="text-sm text-emerald-600 dark:text-emerald-400"
                    >Saved.</span
                >
            </div>
        </form>

        <section
            class="mt-10 max-w-md rounded-lg border border-border bg-card p-5"
        >
            <h3 class="text-lg font-medium text-foreground">Invite admin</h3>
            <p class="mt-1 text-sm text-muted-foreground">
                Send an invitation to a new admin for {{ restaurant.name }}.
            </p>

            <Form
                :action="`/${restaurant.subdomain}/invitations`"
                method="post"
                :reset-on-success="['email']"
                v-slot="{ errors, processing }"
                class="mt-4 space-y-3"
            >
                <div class="grid gap-2">
                    <Label for="invite-email">Email address</Label>
                    <Input
                        id="invite-email"
                        name="email"
                        type="email"
                        required
                        placeholder="admin@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>
                <Button type="submit" :disabled="processing"
                    >Send invitation</Button
                >
            </Form>
        </section>
    </TenantAdminLayout>
</template>
