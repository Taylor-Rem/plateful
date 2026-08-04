<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ShieldAlert, ShieldCheck } from 'lucide-vue-next';
import { onUnmounted, ref } from 'vue';
import AdminSecurityController from '@/actions/App/Http/Controllers/Admin/AdminSecurityController';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { home } from '@/routes/admin';
import { disable, enable } from '@/routes/two-factor';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
    twoFactorRequired?: boolean;
    passwordRules: string;
};

const props = withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
    twoFactorRequired: false,
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Account security" />

        <header class="border-b border-border bg-card">
            <div
                class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-4 sm:px-6"
            >
                <Link
                    :href="home()"
                    class="text-lg font-semibold text-foreground hover:text-foreground/80"
                >
                    Plateful Admin
                </Link>
                <div class="flex items-center gap-4">
                    <AppearanceTabs />
                    <Link
                        :href="'/logout'"
                        method="post"
                        as="button"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Log out
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-2xl space-y-10 px-6 py-10">
            <h1 class="text-xl font-semibold text-foreground">
                Account security
            </h1>

            <div
                v-if="twoFactorRequired && !twoFactorEnabled"
                class="flex items-start gap-3 rounded-lg border border-yellow-300 bg-yellow-100 p-4 text-sm text-yellow-900"
                data-test="two-factor-required-callout"
            >
                <ShieldAlert class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <strong class="font-semibold"
                        >Two-factor authentication is required for platform
                        administrators.</strong
                    >
                    Enable it below to regain access to the admin console.
                </div>
            </div>

            <div class="space-y-6">
                <Heading
                    variant="small"
                    title="Update password"
                    description="Ensure your account is using a long, random password to stay secure"
                />

                <Form
                    v-bind="AdminSecurityController.updatePassword.form()"
                    :options="{
                        preserveScroll: true,
                    }"
                    reset-on-success
                    :reset-on-error="[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]"
                    class="space-y-6"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="current_password">Current password</Label>
                        <PasswordInput
                            id="current_password"
                            name="current_password"
                            class="mt-1 block w-full"
                            autocomplete="current-password"
                            placeholder="Current password"
                        />
                        <InputError :message="errors.current_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">New password</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            class="mt-1 block w-full"
                            autocomplete="new-password"
                            placeholder="New password"
                            :passwordrules="props.passwordRules"
                        />
                        <InputError :message="errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation"
                            >Confirm password</Label
                        >
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            class="mt-1 block w-full"
                            autocomplete="new-password"
                            placeholder="Confirm password"
                            :passwordrules="props.passwordRules"
                        />
                        <InputError :message="errors.password_confirmation" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="update-password-button"
                        >
                            Save password
                        </Button>
                    </div>
                </Form>
            </div>

            <div v-if="canManageTwoFactor" class="space-y-6">
                <Heading
                    variant="small"
                    title="Two-factor authentication"
                    description="Manage your two-factor authentication settings"
                />

                <div
                    v-if="!twoFactorEnabled"
                    class="flex flex-col items-start justify-start space-y-4"
                >
                    <p class="text-sm text-muted-foreground">
                        When you enable two-factor authentication, you will be
                        prompted for a secure pin during login. This pin can be
                        retrieved from a TOTP-supported application on your
                        phone.
                    </p>

                    <div>
                        <Button
                            v-if="hasSetupData"
                            @click="showSetupModal = true"
                        >
                            <ShieldCheck />Continue setup
                        </Button>
                        <Form
                            v-else
                            v-bind="enable.form()"
                            @success="showSetupModal = true"
                            #default="{ processing }"
                        >
                            <Button type="submit" :disabled="processing">
                                Enable 2FA
                            </Button>
                        </Form>
                    </div>
                </div>

                <div
                    v-else
                    class="flex flex-col items-start justify-start space-y-4"
                >
                    <p class="text-sm text-muted-foreground">
                        You will be prompted for a secure, random pin during
                        login, which you can retrieve from the TOTP-supported
                        application on your phone.
                    </p>

                    <div class="relative inline">
                        <Form v-bind="disable.form()" #default="{ processing }">
                            <Button
                                variant="destructive"
                                type="submit"
                                :disabled="processing"
                            >
                                Disable 2FA
                            </Button>
                        </Form>
                    </div>

                    <TwoFactorRecoveryCodes />
                </div>

                <TwoFactorSetupModal
                    v-model:isOpen="showSetupModal"
                    :requiresConfirmation="requiresConfirmation"
                    :twoFactorEnabled="twoFactorEnabled"
                />
            </div>
        </main>
    </div>
</template>
