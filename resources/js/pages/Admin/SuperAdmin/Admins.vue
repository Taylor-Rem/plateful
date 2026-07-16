<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
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
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Admins" />
        <header class="border-b border-border bg-card">
            <div
                class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4"
            >
                <div class="flex items-center gap-4">
                    <Link
                        href="/"
                        class="text-sm text-muted-foreground hover:text-foreground"
                        >←</Link
                    >
                    <h1 class="text-lg font-semibold text-foreground">
                        Admins
                    </h1>
                </div>
                <div class="flex items-center gap-4">
                    <AppearanceTabs />
                    <Link
                        href="/super/restaurants"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Restaurants
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-8 px-6 py-8">
            <table
                class="w-full divide-y divide-border overflow-hidden rounded-lg border border-border bg-card"
            >
                <thead
                    class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                >
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Restaurants</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-sm">
                    <tr v-for="admin in admins" :key="admin.id">
                        <td class="px-4 py-3 font-medium text-foreground">
                            {{ admin.name }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ admin.email }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <span
                                v-if="admin.isSuperAdmin"
                                class="font-semibold text-primary"
                                >Super admin</span
                            >
                            <span v-else>Admin</span>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <span v-if="admin.isSuperAdmin">All</span>
                            <span v-else-if="admin.restaurants.length === 0"
                                >None</span
                            >
                            <span v-else>{{
                                admin.restaurants.map((r) => r.name).join(', ')
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <section
                class="max-w-md rounded-lg border border-border bg-card p-5"
            >
                <h2 class="text-lg font-medium text-foreground">
                    Invite admin
                </h2>

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
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:border-ring focus:ring-1 focus:ring-ring focus:outline-none"
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

                    <label
                        class="flex items-center gap-2 text-sm text-foreground"
                    >
                        <input
                            type="checkbox"
                            name="as_super_admin"
                            value="1"
                            class="accent-primary"
                        />
                        Invite as super admin
                    </label>

                    <Button type="submit" :disabled="processing"
                        >Send invitation</Button
                    >
                </Form>
            </section>
        </main>
    </div>
</template>
