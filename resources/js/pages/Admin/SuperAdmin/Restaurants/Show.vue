<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';

type Person = { id: number; name: string } | null;

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    admins: App.Data.AdminUserData[];
    pendingInvitations: App.Data.PendingInvitationData[];
    revenueRoles: {
        shares: Record<string, number>;
        recruiterId: number | null;
        overseerId: number | null;
        resolved: {
            founder: Person;
            operator: Person;
            recruiter: Person;
            overseer: Person;
            overseerIsFallback: boolean;
        };
    };
    assignableUsers: { id: number; name: string; email: string }[];
}>();

const confirming = ref(false);
const processing = ref(false);

const feeForm = useForm({
    application_fee_percent: props.restaurant.applicationFeePercent,
});

function saveFee() {
    feeForm.put(`/super/restaurants/${props.restaurant.subdomain}/fee`, {
        preserveScroll: true,
    });
}

const rolesForm = useForm({
    recruiter_id: props.revenueRoles.recruiterId,
    overseer_id: props.revenueRoles.overseerId,
});

function saveRoles() {
    rolesForm
        .transform((data) => ({
            recruiter_id: data.recruiter_id || null,
            overseer_id: data.overseer_id || null,
        }))
        .put(`/super/restaurants/${props.restaurant.subdomain}/roles`, {
            preserveScroll: true,
        });
}

function formatDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return '—';
    }
}

function deactivate() {
    processing.value = true;
    router.post(
        `/super/restaurants/${props.restaurant.subdomain}/deactivate`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                confirming.value = false;
            },
        },
    );
}

function activate() {
    processing.value = true;
    router.post(
        `/super/restaurants/${props.restaurant.subdomain}/activate`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head :title="restaurant.name" />
        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link
                        href="/super/restaurants"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        ←
                    </Link>
                    <h1 class="text-lg font-semibold text-foreground">{{ restaurant.name }}</h1>
                    <span
                        v-if="restaurant.isActive"
                        class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800"
                    >
                        Active
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center rounded-full bg-neutral-200 px-2 py-0.5 text-xs font-medium text-neutral-700"
                    >
                        Deactivated
                    </span>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/super/earnings"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Earnings
                    </Link>
                    <AppearanceTabs />
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl space-y-6 px-6 py-8">
            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold text-foreground">Quick info</h2>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Subdomain</dt>
                        <dd class="font-mono">{{ restaurant.subdomain }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Custom domain</dt>
                        <dd class="font-mono">{{ restaurant.customDomain ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Email</dt>
                        <dd>{{ restaurant.email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Created</dt>
                        <dd>{{ formatDate(restaurant.createdAt) }}</dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-3">
                    <a
                        :href="`/${restaurant.subdomain}/settings`"
                        class="text-sm text-primary hover:underline"
                    >
                        Manage settings &amp; branding →
                    </a>
                    <a
                        :href="`/${restaurant.subdomain}/dashboard`"
                        class="text-sm text-primary hover:underline"
                    >
                        Open admin dashboard →
                    </a>
                </div>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold text-foreground">Platform pricing</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    The application fee Plateful takes from each order, charged on the food
                    subtotal only. This restaurant keeps this rate unless you change it here.
                </p>
                <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="saveFee">
                    <div>
                        <label
                            for="application_fee_percent"
                            class="block text-xs font-medium text-muted-foreground"
                        >
                            Application fee (%)
                        </label>
                        <div class="mt-1 flex items-center gap-1">
                            <input
                                id="application_fee_percent"
                                v-model="feeForm.application_fee_percent"
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                class="w-28 rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground"
                            />
                            <span class="text-sm text-muted-foreground">%</span>
                        </div>
                    </div>
                    <Button type="submit" :disabled="feeForm.processing">
                        {{ feeForm.processing ? 'Saving…' : 'Save fee' }}
                    </Button>
                    <p
                        v-if="feeForm.recentlySuccessful"
                        class="text-sm text-green-600"
                    >
                        Saved.
                    </p>
                </form>
                <p
                    v-if="feeForm.errors.application_fee_percent"
                    class="mt-2 text-sm text-destructive"
                >
                    {{ feeForm.errors.application_fee_percent }}
                </p>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold text-foreground">Revenue roles &amp; payout split</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    How the platform fee Plateful keeps from this restaurant is attributed for
                    payouts. Shares are of Plateful's take (not the restaurant's sales) and drive
                    the monthly earnings report — this does not move money automatically, and does
                    not grant panel access.
                </p>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex items-center justify-between border-b border-border py-2">
                        <dt class="text-muted-foreground">
                            Founder
                            <span class="ml-1 text-xs">({{ revenueRoles.shares.founder ?? 0 }}%)</span>
                        </dt>
                        <dd class="font-medium text-foreground">
                            {{ revenueRoles.resolved.founder?.name ?? 'Unset' }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-muted-foreground">
                            Overseer
                            <span class="ml-1 text-xs">({{ revenueRoles.shares.overseer ?? 0 }}%)</span>
                        </dt>
                        <dd class="text-right font-medium text-foreground">
                            {{ revenueRoles.resolved.overseer?.name ?? 'Unset' }}
                            <span
                                v-if="revenueRoles.resolved.overseerIsFallback"
                                class="block text-xs font-normal text-muted-foreground"
                            >
                                via Operator (no overseer assigned)
                            </span>
                        </dd>
                    </div>
                </dl>

                <form class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="saveRoles">
                    <div>
                        <label for="overseer_id" class="block text-xs font-medium text-muted-foreground">
                            Overseer
                        </label>
                        <select
                            id="overseer_id"
                            v-model="rolesForm.overseer_id"
                            class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option :value="null">— Operator (fallback) —</option>
                            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">
                                {{ u.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="recruiter_id" class="block text-xs font-medium text-muted-foreground">
                            Recruiter
                            <span class="text-xs">(tracked, {{ revenueRoles.shares.recruiter ?? 0 }}%)</span>
                        </label>
                        <select
                            id="recruiter_id"
                            v-model="rolesForm.recruiter_id"
                            class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option :value="null">— None —</option>
                            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">
                                {{ u.name }}
                            </option>
                        </select>
                    </div>
                    <div class="sm:col-span-2 flex items-center gap-3">
                        <Button type="submit" :disabled="rolesForm.processing">
                            {{ rolesForm.processing ? 'Saving…' : 'Save roles' }}
                        </Button>
                        <p v-if="rolesForm.recentlySuccessful" class="text-sm text-green-600">
                            Saved.
                        </p>
                    </div>
                </form>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-foreground">Admins</h2>
                    <Link
                        href="/super/admins"
                        class="text-sm text-primary hover:underline"
                    >
                        Invite admin →
                    </Link>
                </div>
                <div
                    v-if="admins.length === 0"
                    class="mt-4 rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                >
                    No admins assigned yet.
                </div>
                <ul v-else class="mt-4 divide-y divide-border text-sm">
                    <li v-for="admin in admins" :key="admin.id" class="py-3">
                        <div class="font-medium text-foreground">{{ admin.name }}</div>
                        <div class="text-xs text-muted-foreground">{{ admin.email }}</div>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold text-foreground">Pending invitations</h2>
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
                        class="flex items-center justify-between py-3"
                    >
                        <div>
                            <div class="font-medium text-foreground">{{ inv.email }}</div>
                            <div class="text-xs text-muted-foreground">
                                Invited by {{ inv.invitedByName ?? 'Unknown' }}
                            </div>
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Expires {{ formatDate(inv.expiresAt) }}
                        </div>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border border-destructive/40 bg-card p-6">
                <h2 class="text-base font-semibold text-destructive">Danger zone</h2>
                <div v-if="restaurant.isActive" class="mt-4 space-y-3 text-sm">
                    <p class="text-muted-foreground">
                        Deactivating this restaurant will make the storefront unavailable to customers.
                        Restaurant admins will still be able to log in.
                    </p>
                    <div v-if="!confirming">
                        <Button variant="destructive" @click="confirming = true">
                            Deactivate restaurant
                        </Button>
                    </div>
                    <div v-else class="flex items-center gap-3">
                        <Button
                            variant="destructive"
                            :disabled="processing"
                            @click="deactivate"
                        >
                            {{ processing ? 'Deactivating…' : 'Yes, deactivate' }}
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="processing"
                            @click="confirming = false"
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
                <div v-else class="mt-4 space-y-3 text-sm">
                    <p class="text-muted-foreground">
                        This restaurant is currently deactivated.
                    </p>
                    <Button :disabled="processing" @click="activate">
                        {{ processing ? 'Reactivating…' : 'Reactivate restaurant' }}
                    </Button>
                </div>
            </section>
        </main>
    </div>
</template>
