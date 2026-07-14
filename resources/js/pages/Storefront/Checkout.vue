<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type BrandPalette = {
    primary: string;
    primaryForeground: string;
    secondary: string;
    secondaryForeground: string;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    cart: App.Data.CartData;
    savedAddresses: App.Data.AddressData[];
    tipPresets: number[];
    brand: BrandPalette;
}>();

const page = usePage<{ auth?: { user?: { name: string; email: string } | null } }>();
const authUser = computed(() => page.props.auth?.user ?? null);

const formatPrice = (cents: number): string =>
    `$${(cents / 100).toFixed(2)}`;

type CheckoutForm = {
    customer_name: string;
    customer_email: string;
    customer_phone: string;
    type: 'pickup' | 'delivery';
    address_id: number | null;
    delivery_address: {
        street: string;
        street2: string;
        city: string;
        state: string;
        postal_code: string;
        country: string;
        instructions: string;
    };
    save_address: boolean;
    tip_preset: string;
    tip_custom_cents: number | null;
    notes: string;
};

const defaultAddress = props.savedAddresses.find((a) => a.isDefault) ?? props.savedAddresses[0] ?? null;

const form = useForm<CheckoutForm>({
    customer_name: authUser.value?.name ?? '',
    customer_email: authUser.value?.email ?? '',
    customer_phone: '',
    type: 'pickup',
    address_id: defaultAddress?.id ?? null,
    delivery_address: {
        street: defaultAddress?.street ?? '',
        street2: defaultAddress?.street2 ?? '',
        city: defaultAddress?.city ?? '',
        state: defaultAddress?.state ?? '',
        postal_code: defaultAddress?.postalCode ?? '',
        country: defaultAddress?.country ?? 'US',
        instructions: defaultAddress?.instructions ?? '',
    },
    save_address: true,
    tip_preset: '0',
    tip_custom_cents: null,
    notes: '',
});

const useNewAddress = ref(props.savedAddresses.length === 0);

watch(
    () => form.address_id,
    (id) => {
        if (id === null) return;
        const sel = props.savedAddresses.find((a) => a.id === id);
        if (!sel) return;
        form.delivery_address.street = sel.street;
        form.delivery_address.street2 = sel.street2 ?? '';
        form.delivery_address.city = sel.city;
        form.delivery_address.state = sel.state;
        form.delivery_address.postal_code = sel.postalCode;
        form.delivery_address.country = sel.country;
        form.delivery_address.instructions = sel.instructions ?? '';
    },
);

const subtotalCents = computed(() => props.cart.subtotalCents);
const taxCents = computed(() =>
    Math.round((subtotalCents.value * props.restaurant.taxRatePercent) / 100),
);
const deliveryFeeCents = computed(() =>
    form.type === 'delivery' ? props.restaurant.deliveryFeeCents : 0,
);
const tipCents = computed(() => {
    if (form.tip_preset === 'custom') {
        return Math.max(0, form.tip_custom_cents ?? 0);
    }
    const pct = Number(form.tip_preset);
    if (!Number.isFinite(pct) || pct === 0) return 0;
    return Math.round((subtotalCents.value * pct) / 100);
});
const totalCents = computed(
    () =>
        subtotalCents.value +
        taxCents.value +
        deliveryFeeCents.value +
        tipCents.value,
);

const tipCustomDollars = computed({
    get: () =>
        form.tip_custom_cents !== null
            ? (form.tip_custom_cents / 100).toFixed(2)
            : '',
    set: (v: string) => {
        const n = parseFloat(v);
        form.tip_custom_cents = Number.isFinite(n) ? Math.round(n * 100) : 0;
    },
});

const submit = (): void => {
    const payload: Record<string, unknown> = {
        customer_name: form.customer_name,
        customer_email: form.customer_email,
        customer_phone: form.customer_phone || null,
        type: form.type,
        notes: form.notes || null,
        tip_preset: form.tip_preset,
        tip_custom_cents: form.tip_custom_cents,
        save_address: form.save_address,
    };
    if (form.type === 'delivery') {
        payload.delivery_address = form.delivery_address;
        if (!useNewAddress.value && form.address_id) {
            payload.address_id = form.address_id;
        }
    }
    form.transform(() => payload).post('/orders', {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Checkout" />

    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <h1
            class="mb-6 text-2xl font-bold tracking-tight"
            :style="{ color: 'var(--brand-primary)' }"
        >
            Checkout
        </h1>

        <div
            v-if="restaurant.isOpen === false"
            class="mb-6 rounded-md border border-amber-300 bg-amber-100 px-4 py-3 text-sm text-amber-900"
        >
            <strong class="font-semibold">We're currently closed.</strong>
            {{ restaurant.nextOpenLabel }}. You can still keep items in your cart, but
            you'll need to wait until we open to check out.
        </div>

        <p
            v-if="form.errors.restaurant_closed"
            class="mb-4 rounded-md border border-destructive/30 bg-destructive/10 px-4 py-2 text-sm text-destructive"
        >
            {{ form.errors.restaurant_closed }}
        </p>

        <div
            v-if="cart.items.length === 0"
            class="rounded-lg border border-border bg-card p-8 text-center"
        >
            <p class="text-muted-foreground">Your cart is empty.</p>
            <a
                href="/"
                class="mt-4 inline-block rounded-md px-4 py-2 text-sm font-medium"
                :style="{
                    backgroundColor: 'var(--brand-primary)',
                    color: 'var(--brand-primary-foreground)',
                }"
            >
                Browse menu
            </a>
        </div>

        <form
            v-else
            class="grid gap-6 lg:grid-cols-[1fr_360px]"
            @submit.prevent="submit"
        >
            <!-- LEFT: form -->
            <div class="space-y-6">
                <!-- Customer info -->
                <section
                    class="rounded-lg border border-border bg-card p-5"
                >
                    <h2 class="mb-4 text-base font-semibold">Your info</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label
                                class="mb-1 block text-sm font-medium"
                                for="customer_name"
                                >Name</label
                            >
                            <input
                                id="customer_name"
                                v-model="form.customer_name"
                                type="text"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                required
                            />
                            <p
                                v-if="form.errors.customer_name"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.customer_name }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium"
                                for="customer_email"
                                >Email</label
                            >
                            <input
                                id="customer_email"
                                v-model="form.customer_email"
                                type="email"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                :readonly="!!authUser"
                                required
                            />
                            <p
                                v-if="form.errors.customer_email"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.customer_email }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium"
                                for="customer_phone"
                                >Phone (optional)</label
                            >
                            <input
                                id="customer_phone"
                                v-model="form.customer_phone"
                                type="tel"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                </section>

                <!-- Order type -->
                <section class="rounded-lg border border-border bg-card p-5">
                    <h2 class="mb-4 text-base font-semibold">Order type</h2>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="flex-1 rounded-md border px-4 py-2 text-sm font-medium"
                            :class="
                                form.type === 'pickup'
                                    ? 'border-transparent text-white'
                                    : 'border-border text-foreground hover:bg-muted'
                            "
                            :style="
                                form.type === 'pickup'
                                    ? {
                                          backgroundColor:
                                              'var(--brand-primary)',
                                          color: 'var(--brand-primary-foreground)',
                                      }
                                    : {}
                            "
                            @click="form.type = 'pickup'"
                        >
                            Pickup
                        </button>
                        <button
                            v-if="restaurant.deliveryEnabled"
                            type="button"
                            class="flex-1 rounded-md border px-4 py-2 text-sm font-medium"
                            :class="
                                form.type === 'delivery'
                                    ? 'border-transparent text-white'
                                    : 'border-border text-foreground hover:bg-muted'
                            "
                            :style="
                                form.type === 'delivery'
                                    ? {
                                          backgroundColor:
                                              'var(--brand-primary)',
                                          color: 'var(--brand-primary-foreground)',
                                      }
                                    : {}
                            "
                            @click="form.type = 'delivery'"
                        >
                            Delivery
                        </button>
                    </div>
                    <p
                        v-if="form.errors.type"
                        class="mt-2 text-xs text-destructive"
                    >
                        {{ form.errors.type }}
                    </p>
                </section>

                <!-- Delivery address -->
                <section
                    v-if="form.type === 'delivery'"
                    class="rounded-lg border border-border bg-card p-5"
                >
                    <h2 class="mb-4 text-base font-semibold">Delivery address</h2>

                    <div
                        v-if="authUser && savedAddresses.length > 0 && !useNewAddress"
                        class="mb-4 space-y-2"
                    >
                        <label
                            class="mb-1 block text-sm font-medium"
                            for="address_id"
                            >Use saved address</label
                        >
                        <select
                            id="address_id"
                            v-model.number="form.address_id"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option
                                v-for="a in savedAddresses"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.label ?? `${a.street}, ${a.city}` }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="text-xs text-muted-foreground underline hover:text-foreground"
                            @click="useNewAddress = true"
                        >
                            Use a new address
                        </button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium"
                                >Street</label
                            >
                            <input
                                v-model="form.delivery_address.street"
                                type="text"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                            <p
                                v-if="form.errors['delivery_address.street']"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors['delivery_address.street'] }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium"
                                >Apt / suite (optional)</label
                            >
                            <input
                                v-model="form.delivery_address.street2"
                                type="text"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium"
                                >City</label
                            >
                            <input
                                v-model="form.delivery_address.city"
                                type="text"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                            <p
                                v-if="form.errors['delivery_address.city']"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors['delivery_address.city'] }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label
                                    class="mb-1 block text-sm font-medium"
                                    >State</label
                                >
                                <input
                                    v-model="form.delivery_address.state"
                                    type="text"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                />
                            </div>
                            <div>
                                <label
                                    class="mb-1 block text-sm font-medium"
                                    >ZIP</label
                                >
                                <input
                                    v-model="
                                        form.delivery_address.postal_code
                                    "
                                    type="text"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                />
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium"
                                >Delivery instructions (optional)</label
                            >
                            <textarea
                                v-model="form.delivery_address.instructions"
                                rows="2"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                        </div>
                        <div v-if="authUser" class="sm:col-span-2">
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input
                                    v-model="form.save_address"
                                    type="checkbox"
                                    class="rounded"
                                />
                                Save this address for next time
                            </label>
                        </div>
                    </div>
                </section>

                <!-- Notes -->
                <section class="rounded-lg border border-border bg-card p-5">
                    <h2 class="mb-2 text-base font-semibold">
                        Notes for the kitchen (optional)
                    </h2>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </section>

                <!-- Tip -->
                <section class="rounded-lg border border-border bg-card p-5">
                    <h2 class="mb-4 text-base font-semibold">Add a tip</h2>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="p in tipPresets"
                            :key="p"
                            type="button"
                            class="rounded-md border px-4 py-2 text-sm font-medium"
                            :class="
                                form.tip_preset === String(p)
                                    ? 'border-transparent text-white'
                                    : 'border-border text-foreground hover:bg-muted'
                            "
                            :style="
                                form.tip_preset === String(p)
                                    ? {
                                          backgroundColor:
                                              'var(--brand-primary)',
                                          color: 'var(--brand-primary-foreground)',
                                      }
                                    : {}
                            "
                            @click="form.tip_preset = String(p)"
                        >
                            {{ p === 0 ? 'No tip' : `${p}%` }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-4 py-2 text-sm font-medium"
                            :class="
                                form.tip_preset === 'custom'
                                    ? 'border-transparent text-white'
                                    : 'border-border text-foreground hover:bg-muted'
                            "
                            :style="
                                form.tip_preset === 'custom'
                                    ? {
                                          backgroundColor:
                                              'var(--brand-primary)',
                                          color: 'var(--brand-primary-foreground)',
                                      }
                                    : {}
                            "
                            @click="form.tip_preset = 'custom'"
                        >
                            Custom
                        </button>
                    </div>
                    <div v-if="form.tip_preset === 'custom'" class="mt-3">
                        <label class="mb-1 block text-sm font-medium"
                            >Custom tip ($)</label
                        >
                        <input
                            v-model="tipCustomDollars"
                            type="number"
                            min="0"
                            step="0.01"
                            class="w-32 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <p
                        v-if="tipCents > 0"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Tip: {{ formatPrice(tipCents) }}
                    </p>
                </section>
            </div>

            <!-- RIGHT: summary -->
            <aside class="lg:sticky lg:top-20 lg:self-start">
                <div class="rounded-lg border border-border bg-card p-5">
                    <h2 class="mb-4 text-base font-semibold">Order summary</h2>
                    <ul class="space-y-2">
                        <li
                            v-for="item in cart.items"
                            :key="item.id"
                            class="flex justify-between gap-3 text-sm"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate">
                                    {{ item.quantity }}× {{ item.menuItemName }}
                                </p>
                                <p
                                    v-if="item.selectionSummary"
                                    class="line-clamp-1 text-xs text-muted-foreground"
                                >
                                    {{ item.selectionSummary }}
                                </p>
                            </div>
                            <span class="tabular-nums">{{
                                formatPrice(item.lineTotalCents)
                            }}</span>
                        </li>
                    </ul>

                    <hr class="my-4 border-border" />

                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Subtotal</dt>
                            <dd class="tabular-nums">
                                {{ formatPrice(subtotalCents) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Tax</dt>
                            <dd class="tabular-nums">
                                {{ formatPrice(taxCents) }}
                            </dd>
                        </div>
                        <div
                            v-if="form.type === 'delivery'"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">Delivery fee</dt>
                            <dd class="tabular-nums">
                                {{ formatPrice(deliveryFeeCents) }}
                            </dd>
                        </div>
                        <div v-if="tipCents > 0" class="flex justify-between">
                            <dt class="text-muted-foreground">Tip</dt>
                            <dd class="tabular-nums">
                                {{ formatPrice(tipCents) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between border-t border-border pt-3 text-base font-bold"
                            :style="{ color: 'var(--brand-primary)' }"
                        >
                            <dt>Total</dt>
                            <dd class="tabular-nums">
                                {{ formatPrice(totalCents) }}
                            </dd>
                        </div>
                    </dl>

                    <button
                        type="submit"
                        class="mt-5 w-full rounded-md px-4 py-3 text-sm font-semibold disabled:opacity-60"
                        :style="{
                            backgroundColor: 'var(--brand-primary)',
                            color: 'var(--brand-primary-foreground)',
                        }"
                        :disabled="form.processing || restaurant.isOpen === false"
                    >
                        {{ form.processing ? 'Placing order...' : 'Place order' }}
                    </button>

                    <p
                        v-if="restaurant.isOpen === false"
                        class="mt-3 text-center text-xs text-amber-700"
                    >
                        We're currently closed. {{ restaurant.nextOpenLabel }}.
                    </p>
                    <p
                        v-else
                        class="mt-3 text-center text-xs text-muted-foreground"
                    >
                        By placing this order you confirm the details above.
                    </p>
                </div>
            </aside>
        </form>
    </main>
</template>
