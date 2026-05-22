<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { computed } from 'vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    members: App.Data.RestaurantMemberData[];
    pendingInvitations: App.Data.PendingInvitationData[];
    roles: Array<{ value: string; label: string }>;
}>();

const base = computed(() => `/${props.restaurant.subdomain}`);

const inviteForm = useForm({
    email: '',
    role: 'staff',
});

const submitInvite = (): void => {
    inviteForm.post(`${base.value}/invitations`, {
        preserveScroll: true,
        onSuccess: () => inviteForm.reset(),
    });
};

const changeRole = (memberId: number, role: string): void => {
    router.put(
        `${base.value}/members/${memberId}`,
        { role },
        { preserveScroll: true },
    );
};

const removeMember = (memberId: number, name: string): void => {
    if (!confirm(`Remove ${name} from the team?`)) {
        return;
    }
    router.delete(`${base.value}/members/${memberId}`, { preserveScroll: true });
};

const revokeInvitation = (id: number, email: string): void => {
    if (!confirm(`Revoke invitation for ${email}?`)) {
        return;
    }
    router.delete(`${base.value}/invitations/${id}`, { preserveScroll: true });
};

function formatDate(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return '—';
    }
}
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Team`" />

        <div class="space-y-8">
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-semibold text-foreground">Team</h2>
            </div>

            <section class="rounded-lg border border-border bg-card p-6">
                <h3 class="text-base font-semibold text-foreground">Invite a team member</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Admins can manage menus, settings, and team members. Staff can manage orders, hours, and item availability.
                </p>
                <form class="mt-4 grid gap-3 sm:grid-cols-[1fr_180px_auto] sm:items-end" @submit.prevent="submitInvite">
                    <div>
                        <Label for="invite-email">Email</Label>
                        <Input
                            id="invite-email"
                            v-model="inviteForm.email"
                            type="email"
                            placeholder="person@example.com"
                            autocomplete="off"
                        />
                        <InputError :message="inviteForm.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <Label for="invite-role">Role</Label>
                        <select
                            id="invite-role"
                            v-model="inviteForm.role"
                            class="block h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option v-for="role in roles" :key="role.value" :value="role.value">
                                {{ role.label }}
                            </option>
                        </select>
                        <InputError :message="inviteForm.errors.role" class="mt-1" />
                    </div>
                    <Button type="submit" :disabled="inviteForm.processing">
                        {{ inviteForm.processing ? 'Sending…' : 'Send invitation' }}
                    </Button>
                </form>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h3 class="text-base font-semibold text-foreground">Members</h3>
                <div
                    v-if="members.length === 0"
                    class="mt-4 rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                >
                    No team members yet.
                </div>
                <ul v-else class="mt-4 divide-y divide-border text-sm">
                    <li v-for="member in members" :key="member.id" class="flex items-center justify-between gap-4 py-3">
                        <div>
                            <div class="font-medium text-foreground">{{ member.name }}</div>
                            <div class="text-xs text-muted-foreground">{{ member.email }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <select
                                :value="member.role"
                                class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                @change="(e) => changeRole(member.id, (e.target as HTMLSelectElement).value)"
                            >
                                <option v-for="role in roles" :key="role.value" :value="role.value">
                                    {{ role.label }}
                                </option>
                            </select>
                            <Button variant="outline" size="sm" @click="removeMember(member.id, member.name)">
                                Remove
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h3 class="text-base font-semibold text-foreground">Pending invitations</h3>
                <div
                    v-if="pendingInvitations.length === 0"
                    class="mt-4 rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                >
                    No pending invitations.
                </div>
                <ul v-else class="mt-4 divide-y divide-border text-sm">
                    <li
                        v-for="inv in pendingInvitations"
                        :key="inv.id"
                        class="flex items-center justify-between gap-4 py-3"
                    >
                        <div>
                            <div class="font-medium text-foreground">{{ inv.email }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ inv.role === 'admin' ? 'Admin' : 'Staff' }} ·
                                Invited by {{ inv.invitedByName ?? 'Unknown' }} ·
                                Expires {{ formatDate(inv.expiresAt) }}
                            </div>
                        </div>
                        <Button variant="outline" size="sm" @click="revokeInvitation(inv.id, inv.email)">
                            Revoke
                        </Button>
                    </li>
                </ul>
            </section>
        </div>
    </TenantAdminLayout>
</template>
