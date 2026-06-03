<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/actions/App/Http/Controllers/OwnerSignupController';
import { home } from '@/routes';

defineProps<{
    reservedSubdomains: string[];
    primaryDomain: string;
    menuPresets: { value: string; label: string }[];
}>();
</script>

<template>
    <Head title="Set up your restaurant" />

    <div class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <header class="border-b border-black/5 dark:border-white/10">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-5">
                <Link :href="home()" class="flex items-center gap-2">
                    <AppLogoIcon class-name="h-7 w-7 text-[#f53003] dark:text-[#FF4433]" />
                    <span class="text-lg font-semibold tracking-tight">Plateful</span>
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-6 py-12">
            <h1 class="text-3xl font-semibold tracking-tight">Set up your restaurant</h1>
            <p class="mt-2 text-sm text-[#1b1b18]/70 dark:text-[#EDEDEC]/70">
                Tell us a bit about your restaurant and pick a subdomain. Next you'll add your menu, set your hours, and connect payments — then go live.
            </p>

            <Form
                v-bind="store.form()"
                :reset-on-success="['password', 'password_confirmation']"
                v-slot="{ errors, processing }"
                class="mt-10 flex flex-col gap-10"
            >
                <section class="grid gap-6">
                    <h2 class="text-lg font-semibold">About you</h2>

                    <div class="grid gap-2">
                        <Label for="name">Your name</Label>
                        <Input id="name" type="text" required name="name" autocomplete="name" />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email</Label>
                        <Input id="email" type="email" required name="email" autocomplete="email" />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="phone">Phone (optional)</Label>
                        <Input id="phone" type="tel" name="phone" autocomplete="tel" />
                        <InputError :message="errors.phone" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Password</Label>
                        <PasswordInput id="password" required name="password" autocomplete="new-password" />
                        <InputError :message="errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">Confirm password</Label>
                        <PasswordInput id="password_confirmation" required name="password_confirmation" autocomplete="new-password" />
                        <InputError :message="errors.password_confirmation" />
                    </div>
                </section>

                <section class="grid gap-6">
                    <h2 class="text-lg font-semibold">About your restaurant</h2>

                    <div class="grid gap-2">
                        <Label for="restaurant_name">Restaurant name</Label>
                        <Input id="restaurant_name" type="text" required name="restaurant_name" />
                        <InputError :message="errors.restaurant_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="subdomain">Subdomain</Label>
                        <div class="flex items-center gap-2">
                            <Input id="subdomain" type="text" required name="subdomain" placeholder="pizzajoint" class="flex-1" />
                            <span class="text-sm text-[#1b1b18]/60 dark:text-[#EDEDEC]/60">.{{ primaryDomain }}</span>
                        </div>
                        <p class="text-xs text-[#1b1b18]/50 dark:text-[#EDEDEC]/50">
                            Lowercase letters, numbers, and hyphens only.
                        </p>
                        <InputError :message="errors.subdomain" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="custom_domain">Custom domain (optional)</Label>
                        <Input id="custom_domain" type="text" name="custom_domain" placeholder="pizzajoint.com" />
                        <p class="text-xs text-[#1b1b18]/50 dark:text-[#EDEDEC]/50">
                            We'll help you wire this up after you sign up.
                        </p>
                        <InputError :message="errors.custom_domain" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="menu_preset">Starter menu (optional)</Label>
                        <select
                            id="menu_preset"
                            name="menu_preset"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Start blank — I'll build my own</option>
                            <option v-for="preset in menuPresets" :key="preset.value" :value="preset.value">
                                {{ preset.label }}
                            </option>
                        </select>
                        <p class="text-xs text-[#1b1b18]/50 dark:text-[#EDEDEC]/50">
                            We'll prefill a sample menu you can rename, re-price, or delete later.
                        </p>
                        <InputError :message="errors.menu_preset" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="city">City</Label>
                            <Input id="city" type="text" name="city" />
                            <InputError :message="errors.city" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="state">State</Label>
                            <Input id="state" type="text" name="state" maxlength="2" placeholder="NY" />
                            <InputError :message="errors.state" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="notes">Anything else? (optional)</Label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="4"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        ></textarea>
                        <InputError :message="errors.notes" />
                    </div>
                </section>

                <Button type="submit" :disabled="processing" data-test="submit-signup-button">
                    <Spinner v-if="processing" />
                    Create my restaurant
                </Button>
            </Form>
        </main>
    </div>
</template>
