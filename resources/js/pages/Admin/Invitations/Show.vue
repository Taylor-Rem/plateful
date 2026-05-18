<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    invitation: {
        token: string;
        email: string;
        restaurantName: string | null;
        asSuperAdmin: boolean;
    } | null;
    error: string | null;
}>();
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-6">
        <Head title="Accept invitation" />

        <div v-if="error || !invitation" class="max-w-md rounded-lg border border-red-200 bg-red-50 p-6 text-center">
            <h1 class="text-lg font-semibold text-red-900">Invitation invalid</h1>
            <p class="mt-2 text-sm text-red-700">{{ error ?? 'This invitation is no longer valid.' }}</p>
        </div>

        <div
            v-else
            class="w-full max-w-md rounded-lg border border-neutral-200 bg-white p-6 shadow-sm"
        >
            <h1 class="text-xl font-semibold text-neutral-900">Accept invitation</h1>
            <p class="mt-1 text-sm text-neutral-600">
                You've been invited to manage
                <span class="font-medium">{{ invitation.restaurantName ?? 'the Plateful platform' }}</span>.
            </p>

            <Form
                :action="`/invitations/${invitation.token}`"
                method="post"
                v-slot="{ errors, processing }"
                class="mt-6 space-y-4"
            >
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        :model-value="invitation.email"
                        readonly
                        disabled
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" name="name" required autofocus />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <PasswordInput id="password" name="password" required autocomplete="new-password" />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        required
                        autocomplete="new-password"
                    />
                </div>

                <Button type="submit" class="w-full" :disabled="processing">
                    Create account
                </Button>
            </Form>
        </div>
    </div>
</template>
