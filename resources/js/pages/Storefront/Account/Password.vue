<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AccountTabs from '@/pages/Storefront/Account/AccountTabs.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
    passwordRules: string;
}>();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = (): void => {
    form.patch('/account/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <div>
        <Head title="Change password" />

        <main class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
            <h1
                class="mb-1 text-2xl font-bold tracking-tight"
                :style="{ color: 'var(--brand-primary)' }"
            >
                Password
            </h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Choose a strong password you don't reuse elsewhere.
            </p>

            <AccountTabs active="password" />

            <form
                class="rounded-lg border border-border bg-card p-5"
                @submit.prevent="submit"
            >
                <div class="grid gap-4">
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium"
                            for="current_password"
                        >
                            Current password
                        </label>
                        <input
                            id="current_password"
                            v-model="form.current_password"
                            type="password"
                            autocomplete="current-password"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            required
                        />
                        <p
                            v-if="form.errors.current_password"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.current_password }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium"
                            for="password"
                        >
                            New password
                        </label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            required
                        />
                        <p
                            v-if="form.errors.password"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.password }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-sm font-medium"
                            for="password_confirmation"
                        >
                            Confirm new password
                        </label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            required
                        />
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
                    {{ form.processing ? 'Saving…' : 'Update password' }}
                </button>
            </form>
        </main>
    </div>
</template>
