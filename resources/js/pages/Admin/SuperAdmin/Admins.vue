<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import PageHeader from '@/components/admin/PageHeader.vue';
import SectionCard from '@/components/admin/SectionCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SuperAdminLayout from '@/layouts/admin/SuperAdminLayout.vue';
import {
    destroy as platformInvitationsDestroy,
    store as platformInvitationsStore,
} from '@/routes/admin/super/admins/invitations';

type AdminRow = {
    id: number;
    name: string;
    email: string;
    isSuperAdmin: boolean;
    restaurants: { id: number; name: string; subdomain: string }[];
};

type PendingInvitation = {
    id: number;
    email: string;
    restaurantName: string | null;
    asSuperAdmin: boolean;
    expiresAt: string | null;
    invitedByName: string | null;
};

defineProps<{
    admins: AdminRow[];
    restaurants: { id: number; name: string; subdomain: string }[];
    pendingInvitations: PendingInvitation[];
}>();

const revoke = (invitation: PendingInvitation): void => {
    if (!confirm(`Revoke the invitation for ${invitation.email}?`)) {
        return;
    }

    router.delete(platformInvitationsDestroy.url(invitation.id), {
        preserveScroll: true,
    });
};

const describe = (invitation: PendingInvitation): string => {
    if (invitation.asSuperAdmin) {
        return 'Super admin';
    }

    return invitation.restaurantName ?? 'Platform';
};

defineOptions({ layout: SuperAdminLayout });
</script>

<template>
    <div>
        <Head title="Admins" />
        <div class="space-y-8">
            <PageHeader title="Admins" />
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

            <SectionCard
                v-if="pendingInvitations.length > 0"
                title="Pending invitations"
                description="Sent but not yet accepted. Revoking one invalidates its link."
            >
                <ul class="divide-y divide-border text-sm">
                    <li
                        v-for="invitation in pendingInvitations"
                        :key="invitation.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div>
                            <div class="font-medium text-foreground">
                                {{ invitation.email }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ describe(invitation) }}
                                <template v-if="invitation.invitedByName">
                                    · invited by {{ invitation.invitedByName }}
                                </template>
                            </div>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="revoke(invitation)"
                        >
                            Revoke
                        </Button>
                    </li>
                </ul>
            </SectionCard>

            <section
                class="max-w-md rounded-lg border border-border bg-card p-5"
            >
                <h2 class="text-lg font-medium text-foreground">
                    Invite admin
                </h2>

                <Form
                    :action="platformInvitationsStore.url()"
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
        </div>
    </div>
</template>
