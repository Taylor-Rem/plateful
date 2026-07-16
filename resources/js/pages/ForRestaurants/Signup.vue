<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, LoaderCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { store } from '@/actions/App/Http/Controllers/OwnerSignupController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import MarketingLayout from '@/layouts/MarketingLayout.vue';

defineProps<{
    primaryDomain: string;
}>();

const detectTimezone = (): string => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
    } catch {
        return '';
    }
};

const form = useForm(store(), {
    name: '',
    email: '',
    password: '',
    restaurant_name: '',
    subdomain: '',
    timezone: detectTimezone(),
}).setValidationTimeout(500);

const slugify = (value: string): string =>
    value
        .toLowerCase()
        .replace(/['’]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 50)
        .replace(/-+$/g, '');

// Suggest the subdomain from the restaurant name until the owner edits it
// themselves — then their choice wins.
const subdomainEdited = ref(false);

watch(
    () => form.restaurant_name,
    (name) => {
        if (subdomainEdited.value) {
            return;
        }

        form.subdomain = slugify(name);

        if (form.subdomain.length >= 2) {
            form.validate('subdomain');
        }
    },
);

const onSubdomainInput = (): void => {
    subdomainEdited.value = form.subdomain !== '';
    form.subdomain = form.subdomain.toLowerCase();

    if (form.subdomain.length >= 2) {
        form.validate('subdomain');
    }
};

const subdomainAvailable = computed(
    () => form.subdomain.length >= 2 && form.valid('subdomain'),
);

const submit = (): void => {
    form.submit();
};
</script>

<template>
    <Head title="Set up your restaurant" />

    <MarketingLayout>
        <section class="relative overflow-hidden">
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0"
            >
                <div
                    class="absolute -top-32 -right-24 h-96 w-96 rounded-full bg-teal-100/70 blur-3xl"
                ></div>
                <div
                    class="absolute top-64 -left-32 h-80 w-80 rounded-full bg-crimson-100/40 blur-3xl"
                ></div>
            </div>

            <div class="relative mx-auto max-w-xl px-6 py-16 sm:py-20">
                <h1 class="text-4xl font-bold tracking-tight text-stone-900">
                    Set up your restaurant
                </h1>
                <p class="mt-3 text-stone-600">
                    Five quick fields and you're in. You'll add your menu,
                    hours, and payments next — with a live preview of your site
                    as you go.
                </p>

                <form
                    @submit.prevent="submit"
                    class="mt-10 flex flex-col gap-6 rounded-3xl bg-white p-8 shadow-xl ring-1 shadow-stone-900/5 ring-stone-900/5 sm:p-10"
                >
                    <div class="grid gap-2">
                        <Label for="restaurant_name">Restaurant name</Label>
                        <Input
                            id="restaurant_name"
                            v-model="form.restaurant_name"
                            type="text"
                            required
                            autofocus
                            placeholder="Marco's Pizza"
                        />
                        <InputError :message="form.errors.restaurant_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="subdomain">Your website address</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                id="subdomain"
                                v-model="form.subdomain"
                                type="text"
                                required
                                placeholder="marcos-pizza"
                                class="flex-1"
                                @input="onSubdomainInput"
                            />
                            <span class="text-sm text-stone-500"
                                >.{{ primaryDomain }}</span
                            >
                        </div>
                        <p
                            v-if="form.validating"
                            class="flex items-center gap-1.5 text-xs text-stone-400"
                        >
                            <LoaderCircle class="size-3 animate-spin" />
                            Checking availability…
                        </p>
                        <p
                            v-else-if="subdomainAvailable"
                            class="flex items-center gap-1.5 text-xs text-green-700"
                            data-test="subdomain-available"
                        >
                            <Check class="size-3" />
                            {{ form.subdomain }}.{{ primaryDomain }} is yours
                        </p>
                        <InputError v-else :message="form.errors.subdomain" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="name">Your name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            autocomplete="name"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="email"
                            @change="form.validate('email')"
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Password</Label>
                        <PasswordInput
                            id="password"
                            v-model="form.password"
                            required
                            name="password"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="submit-signup-button"
                    >
                        <Spinner v-if="form.processing" />
                        Create my restaurant
                    </Button>
                    <p class="text-center text-xs text-stone-400">
                        Free to set up. Plateful only takes a small fee when you
                        sell.
                    </p>
                </form>
            </div>
        </section>
    </MarketingLayout>
</template>
