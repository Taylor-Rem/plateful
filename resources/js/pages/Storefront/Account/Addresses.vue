<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Trash2, Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import AccountTabs from '@/pages/Storefront/Account/AccountTabs.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
    addresses: App.Data.AddressData[];
}>();

type AddressForm = {
    label: string;
    street: string;
    street2: string;
    city: string;
    state: string;
    postal_code: string;
    country: string;
    instructions: string;
    is_default: boolean;
};

const blank = (): AddressForm => ({
    label: '',
    street: '',
    street2: '',
    city: '',
    state: '',
    postal_code: '',
    country: 'US',
    instructions: '',
    is_default: false,
});

const form = useForm<AddressForm>(blank());
const editingId = ref<number | null>(null);
const showForm = ref(false);

const startNew = (): void => {
    form.reset();
    form.clearErrors();
    Object.assign(form, blank());
    editingId.value = null;
    showForm.value = true;
};

const startEdit = (a: App.Data.AddressData): void => {
    editingId.value = a.id;
    Object.assign(form, {
        label: a.label ?? '',
        street: a.street,
        street2: a.street2 ?? '',
        city: a.city,
        state: a.state,
        postal_code: a.postalCode,
        country: a.country,
        instructions: a.instructions ?? '',
        is_default: a.isDefault,
    });
    form.clearErrors();
    showForm.value = true;
};

const cancelForm = (): void => {
    showForm.value = false;
    editingId.value = null;
    form.reset();
};

const submit = (): void => {
    if (editingId.value === null) {
        form.post('/account/addresses', {
            preserveScroll: true,
            onSuccess: () => {
                showForm.value = false;
            },
        });
    } else {
        form.patch(`/account/addresses/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: () => {
                showForm.value = false;
                editingId.value = null;
            },
        });
    }
};

const remove = (a: App.Data.AddressData): void => {
    if (!confirm('Delete this address?')) {
        return;
    }

    router.delete(`/account/addresses/${a.id}`, { preserveScroll: true });
};
</script>

<template>
    <div>
        <Head title="Addresses" />

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <h1
                class="mb-1 text-2xl font-bold tracking-tight"
                :style="{ color: 'var(--brand-primary)' }"
            >
                Saved addresses
            </h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Use these for fast delivery checkout.
            </p>

            <AccountTabs active="addresses" />

            <div v-if="addresses.length === 0 && !showForm" class="mb-4">
                <div
                    class="rounded-lg border border-dashed border-border bg-card p-10 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        No saved addresses yet.
                    </p>
                </div>
            </div>

            <ul v-else-if="addresses.length > 0" class="mb-4 space-y-3">
                <li
                    v-for="a in addresses"
                    :key="a.id"
                    class="flex items-start justify-between gap-4 rounded-lg border border-border bg-card p-4"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="font-medium">
                                {{ a.label ?? a.street }}
                            </p>
                            <span
                                v-if="a.isDefault"
                                class="rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                                :style="{
                                    backgroundColor: 'var(--brand-primary)',
                                    color: 'var(--brand-primary-foreground)',
                                }"
                            >
                                Default
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ a.street
                            }}<span v-if="a.street2">, {{ a.street2 }}</span
                            ><br />
                            {{ a.city }}, {{ a.state }} {{ a.postalCode }}
                        </p>
                        <p
                            v-if="a.instructions"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ a.instructions }}
                        </p>
                    </div>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
                            aria-label="Edit"
                            @click="startEdit(a)"
                        >
                            <Pencil class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-2 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                            aria-label="Delete"
                            @click="remove(a)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </li>
            </ul>

            <button
                v-if="!showForm"
                type="button"
                class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium"
                :style="{
                    backgroundColor: 'var(--brand-primary)',
                    color: 'var(--brand-primary-foreground)',
                }"
                @click="startNew"
            >
                <Plus class="size-4" /> Add address
            </button>

            <form
                v-else
                class="rounded-lg border border-border bg-card p-5"
                @submit.prevent="submit"
            >
                <h2 class="mb-4 text-base font-semibold">
                    {{ editingId === null ? 'New address' : 'Edit address' }}
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-medium"
                            >Label (optional)</label
                        >
                        <input
                            v-model="form.label"
                            type="text"
                            placeholder="Home, Work…"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                        <p
                            v-if="form.errors.label"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.label }}
                        </p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-medium"
                            >Street</label
                        >
                        <input
                            v-model="form.street"
                            type="text"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            required
                        />
                        <p
                            v-if="form.errors.street"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.street }}
                        </p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-medium"
                            >Apt / suite (optional)</label
                        >
                        <input
                            v-model="form.street2"
                            type="text"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium"
                            >City</label
                        >
                        <input
                            v-model="form.city"
                            type="text"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            required
                        />
                        <p
                            v-if="form.errors.city"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.city }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium"
                                >State</label
                            >
                            <input
                                v-model="form.state"
                                type="text"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                required
                            />
                            <p
                                v-if="form.errors.state"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.state }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium"
                                >ZIP</label
                            >
                            <input
                                v-model="form.postal_code"
                                type="text"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                required
                            />
                            <p
                                v-if="form.errors.postal_code"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.postal_code }}
                            </p>
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-medium"
                            >Delivery instructions (optional)</label
                        >
                        <textarea
                            v-model="form.instructions"
                            rows="2"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input
                                v-model="form.is_default"
                                type="checkbox"
                                class="rounded"
                            />
                            Set as default
                        </label>
                    </div>
                </div>
                <div class="mt-5 flex items-center gap-2">
                    <button
                        type="submit"
                        class="rounded-md px-4 py-2 text-sm font-semibold disabled:opacity-60"
                        :style="{
                            backgroundColor: 'var(--brand-primary)',
                            color: 'var(--brand-primary-foreground)',
                        }"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Saving…' : 'Save address' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted"
                        @click="cancelForm"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </main>
    </div>
</template>
