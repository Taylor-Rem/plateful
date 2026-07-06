<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import GoogleLogo from '@/components/GoogleLogo.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { redirect as googleRedirect } from '@/routes/auth/google';
import { store } from '@/routes/register';

const props = defineProps<{
    passwordRules: string;
    restaurantName?: string | null;
    googleEnabled?: boolean;
}>();

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Enter your details below to create your account',
    },
});

// The Google routes live on the platform host; carry the storefront origin so
// the callback can hand the customer back here after login. Resolved after
// mount to avoid an SSR hydration mismatch on the href.
const googleLoginUrl = ref(googleRedirect.url());

onMounted(() => {
    googleLoginUrl.value = googleRedirect.url({
        query: { return_to: window.location.origin },
    });
});
</script>

<template>
    <Head title="Register" />

    <p
        v-if="props.restaurantName"
        class="-mt-3 mb-2 text-center text-xs text-muted-foreground"
        data-test="plateful-account-subtitle"
    >
        You'll get a Plateful account that works at every Plateful restaurant.
    </p>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="Full name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="phone">Phone (optional)</Label>
                <Input
                    id="phone"
                    type="tel"
                    :tabindex="3"
                    autocomplete="tel"
                    name="phone"
                    placeholder="(555) 123-4567"
                />
                <InputError :message="errors.phone" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Create account
            </Button>
        </div>

        <div v-if="props.googleEnabled" class="flex flex-col gap-6">
            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <span class="w-full border-t"></span>
                </div>
                <div class="relative flex justify-center text-xs uppercase">
                    <span class="bg-background px-2 text-muted-foreground">
                        Or continue with
                    </span>
                </div>
            </div>

            <Button
                as="a"
                :href="googleLoginUrl"
                variant="outline"
                class="w-full"
                :tabindex="7"
                data-test="google-register-button"
            >
                <GoogleLogo class="size-4" />
                Continue with Google
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Already have an account?
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="6"
                >Log in</TextLink
            >
        </div>
    </Form>
</template>
