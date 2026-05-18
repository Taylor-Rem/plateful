<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AdminRow = {
    id: number;
    name: string;
    email: string;
    isSuperAdmin: boolean;
    restaurants: { id: number; name: string; subdomain: string }[];
};

defineProps<{
    admins: AdminRow[];
    restaurants: { id: number; name: string; subdomain: string }[];
}>();
</script>

<template>
    <div class="min-h-screen bg-neutral-50">
        <Head title="Admins" />
        <header class="border-b border-neutral-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link href="/" class="text-sm text-neutral-500 hover:text-neutral-900">←</Link>
                    <h1 class="text-lg font-semibold text-neutral-900">Admins</h1>
                </div>
                <Link
                    href="/super/restaurants"
                    class="text-sm text-neutral-600 hover:text-neutral-900"
                >
                    Restaurants
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-8 px-6 py-8">
            <table class="w-full divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white">
                <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Restaurants</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 text-sm">
                    <tr v-for="admin in admins" :key="admin.id">
                        <td class="px-4 py-3 font-medium text-neutral-900">{{ admin.name }}</td>
                        <td class="px-4 py-3 text-neutral-600">{{ admin.email }}</td>
                        <td class="px-4 py-3 text-neutral-600">
                            <span v-if="admin.isSuperAdmin" class="font-semibold text-indigo-700">Super admin</span>
                            <span v-else>Admin</span>
                        </td>
                        <td class="px-4 py-3 text-neutral-600">
                            <span v-if="admin.isSuperAdmin">All</span>
                            <span v-else-if="admin.restaurants.length === 0">None</span>
                            <span v-else>{{ admin.restaurants.map((r) => r.name).join(', ') }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <section class="max-w-md rounded-lg border border-neutral-200 bg-white p-5">
                <h2 class="text-lg font-medium text-neutral-900">Invite admin</h2>

                <Form
                    action="/super/admins/invitations"
                    method="post"
                    :reset-on-success="['email']"
                    v-slot="{ errors, processing }"
                    class="mt-4 space-y-3"
                >
                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input id="email" type="email" name="email" required />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="restaurant_id">Restaurant</Label>
                        <select
                            id="restaurant_id"
                            name="restaurant_id"
                            class="rounded-md border border-neutral-300 px-3 py-2 text-sm"
                        >
                            <option value="">None (platform invitation)</option>
                            <option
                                v-for="r in restaurants"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{ r.name }}
                            </option>
                        </select>
                        <InputError :message="errors.restaurant_id" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                        <input type="checkbox" name="as_super_admin" value="1" />
                        Invite as super admin
                    </label>

                    <Button type="submit" :disabled="processing">Send invitation</Button>
                </Form>
            </section>
        </main>
    </div>
</template>
