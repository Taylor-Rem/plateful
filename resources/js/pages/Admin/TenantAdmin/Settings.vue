<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Settings`" />
        <h2 class="text-2xl font-semibold text-neutral-900">Settings</h2>

        <section class="mt-6 max-w-md rounded-lg border border-neutral-200 bg-white p-5">
            <h3 class="text-lg font-medium text-neutral-900">Invite admin</h3>
            <p class="mt-1 text-sm text-neutral-500">
                Send an invitation to a new admin for {{ restaurant.name }}.
            </p>

            <Form
                :action="`/${restaurant.subdomain}/invitations`"
                method="post"
                :reset-on-success="['email']"
                v-slot="{ errors, processing }"
                class="mt-4 space-y-3"
            >
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        required
                        placeholder="admin@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>
                <Button type="submit" :disabled="processing">Send invitation</Button>
            </Form>
        </section>
    </TenantAdminLayout>
</template>
