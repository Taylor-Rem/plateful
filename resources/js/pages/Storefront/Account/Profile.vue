<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AccountTabs from '@/pages/Storefront/Account/AccountTabs.vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    profile: { name: string; email: string; phone: string | null };
}>();

const form = useForm({
    name: props.profile.name,
    email: props.profile.email,
    phone: props.profile.phone ?? '',
});

const submit = (): void => {
    form.patch('/account/profile', { preserveScroll: true });
};
</script>

<template>
    <div>
        <Head title="Profile" />

        <main class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
            <h1
                class="mb-1 text-2xl font-bold tracking-tight"
                :style="{ color: 'var(--brand-primary)' }"
            >
                Profile
            </h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Update your contact details.
            </p>

            <AccountTabs active="profile" />

            <form
                class="rounded-lg border border-border bg-card p-5"
                @submit.prevent="submit"
            >
                <div class="grid gap-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="name">Name</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            required
                        />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-destructive">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="email">Email</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            required
                        />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-destructive">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="phone">Phone (optional)</label>
                        <input
                            id="phone"
                            v-model="form.phone"
                            type="tel"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                        <p v-if="form.errors.phone" class="mt-1 text-xs text-destructive">{{ form.errors.phone }}</p>
                    </div>
                </div>
                <button
                    type="submit"
                    class="mt-5 rounded-md px-4 py-2 text-sm font-semibold disabled:opacity-60"
                    :style="{
                        backgroundColor: 'var(--brand-primary)',
                        color: 'var(--brand-primary-foreground)',
                    }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </button>
            </form>
        </main>
    </div>
</template>
