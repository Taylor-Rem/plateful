<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { store } from '@/actions/App/Http/Controllers/OwnerSignupController';
import AppWordmark from '@/components/AppWordmark.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home } from '@/routes';
import { Check, LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
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
        if (subdomainEdited.value) return;
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

    <div
        class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
    >
        <header class="border-b border-black/5 dark:border-white/10">
            <div
                class="mx-auto flex max-w-xl items-center justify-between px-6 py-5"
            >
                <Link :href="home()" class="flex items-center">
                    <AppWordmark class-name="h-8 w-auto" />
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-xl px-6 py-12">
            <h1 class="text-3xl font-semibold tracking-tight">
                Set up your restaurant
            </h1>
            <p class="mt-2 text-sm text-[#1b1b18]/70 dark:text-[#EDEDEC]/70">
                Five quick fields and you're in. You'll add your menu, hours,
                and payments next — with a live preview of your site as you go.
            </p>

            <form @submit.prevent="submit" class="mt-10 flex flex-col gap-6">
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
                        <span
                            class="text-sm text-[#1b1b18]/60 dark:text-[#EDEDEC]/60"
                            >.{{ primaryDomain }}</span
                        >
                    </div>
                    <p
                        v-if="form.validating"
                        class="flex items-center gap-1.5 text-xs text-[#1b1b18]/50 dark:text-[#EDEDEC]/50"
                    >
                        <LoaderCircle class="size-3 animate-spin" />
                        Checking availability…
                    </p>
                    <p
                        v-else-if="subdomainAvailable"
                        class="flex items-center gap-1.5 text-xs text-green-700 dark:text-green-400"
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
                <p
                    class="text-center text-xs text-[#1b1b18]/50 dark:text-[#EDEDEC]/50"
                >
                    Free to set up. Plateful only takes a small fee when you
                    sell.
                </p>
            </form>
        </main>
    </div>
</template>
