<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SectionCard from '@/components/admin/SectionCard.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import SuperAdminLayout from '@/layouts/admin/SuperAdminLayout.vue';
import { show as restaurantsShow } from '@/routes/admin/super/restaurants';
import {
    destroy as usersDestroy,
    forceDelete as usersForceDelete,
    index as usersIndex,
    restore as usersRestore,
} from '@/routes/admin/super/users';
import type { UserRow } from './types';
import { TYPE_BADGE_CLASSES, TYPE_LABELS } from './types';

const props = defineProps<{
    user: UserRow;
    impact: {
        ordersCount: number;
        lifetimeSpendCents: number;
        lastOrderAt: string | null;
        loyaltyPoints: number;
        feeDistributionsCount: number;
        addressesCount: number;
        customerRestaurants: {
            id: number;
            name: string;
            subdomain: string;
            totalOrders: number;
            totalSpentCents: number;
            lastOrderedAt: string | null;
        }[];
    };
    account: {
        phone: string | null;
        emailVerifiedAt: string | null;
        hasPassword: boolean;
        hasGoogleLink: boolean;
        twoFactorEnabled: boolean;
        updatedAt: string | null;
    };
}>();

const processing = ref(false);
const confirmingDelete = ref(false);
const confirmingHardDelete = ref(false);

const soleAdminOf = computed(() =>
    props.user.restaurants.filter((r) => r.isSoleAdmin),
);

const canHardDelete = computed(
    () =>
        props.impact.ordersCount === 0 &&
        props.impact.feeDistributionsCount === 0,
);

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return '—';
    }
}

function formatCents(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

function softDelete(): void {
    processing.value = true;
    router.delete(usersDestroy.url(props.user.id), {
        onFinish: () => {
            processing.value = false;
            confirmingDelete.value = false;
        },
    });
}

function restore(): void {
    processing.value = true;
    router.post(
        usersRestore.url(props.user.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function hardDelete(): void {
    processing.value = true;
    router.delete(usersForceDelete.url(props.user.id), {
        onFinish: () => {
            processing.value = false;
            confirmingHardDelete.value = false;
        },
    });
}

defineOptions({ layout: SuperAdminLayout });
</script>

<template>
    <div>
        <Head :title="user.name" />

        <div class="space-y-6">
            <Link
                :href="usersIndex.url()"
                class="text-sm text-muted-foreground hover:underline"
            >
                ← All users
            </Link>

            <PageHeader :title="user.name" :description="user.email">
                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span
                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="TYPE_BADGE_CLASSES[user.type]"
                    >
                        {{ TYPE_LABELS[user.type] }}
                    </span>
                    <span
                        v-if="user.isDeleted"
                        class="inline-flex rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive"
                    >
                        Deleted {{ formatDate(user.deletedAt) }}
                    </span>
                </div>

                <template #actions>
                    <template v-if="user.isDeleted">
                        <Button
                            variant="outline"
                            :disabled="processing"
                            @click="restore"
                        >
                            Restore
                        </Button>
                        <Button
                            variant="destructive"
                            :disabled="processing || !canHardDelete"
                            :title="
                                canHardDelete
                                    ? undefined
                                    : 'Has history that must be preserved — permanent delete is disabled'
                            "
                            @click="confirmingHardDelete = true"
                        >
                            Delete permanently
                        </Button>
                    </template>
                    <Button
                        v-else
                        variant="destructive"
                        :disabled="
                            processing || user.deleteBlockedReason !== null
                        "
                        :title="user.deleteBlockedReason ?? undefined"
                        @click="confirmingDelete = true"
                    >
                        Delete account
                    </Button>
                </template>
            </PageHeader>

            <div class="grid gap-6 lg:grid-cols-2">
                <SectionCard title="Account">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">Phone</dt>
                            <dd>{{ account.phone ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Email verified
                            </dt>
                            <dd>{{ formatDate(account.emailVerifiedAt) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Sign-in</dt>
                            <dd>
                                {{
                                    [
                                        account.hasPassword ? 'Password' : null,
                                        account.hasGoogleLink ? 'Google' : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' + ') || '—'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Two-factor</dt>
                            <dd>
                                {{ account.twoFactorEnabled ? 'On' : 'Off' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Joined</dt>
                            <dd>{{ formatDate(user.createdAt) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Last updated</dt>
                            <dd>{{ formatDate(account.updatedAt) }}</dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="Footprint">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">Orders</dt>
                            <dd class="tabular-nums">
                                {{ impact.ordersCount }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Lifetime spend
                            </dt>
                            <dd class="tabular-nums">
                                {{ formatCents(impact.lifetimeSpendCents) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Last order</dt>
                            <dd>{{ formatDate(impact.lastOrderAt) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Loyalty points
                            </dt>
                            <dd class="tabular-nums">
                                {{ impact.loyaltyPoints }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Saved addresses
                            </dt>
                            <dd class="tabular-nums">
                                {{ impact.addressesCount }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Earnings records
                            </dt>
                            <dd class="tabular-nums">
                                {{ impact.feeDistributionsCount }}
                            </dd>
                        </div>
                    </dl>
                </SectionCard>
            </div>

            <SectionCard
                title="Admin access"
                description="Restaurants this person can manage."
            >
                <p
                    v-if="user.restaurants.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No restaurant access.
                </p>
                <ul v-else class="divide-y divide-border text-sm">
                    <li
                        v-for="restaurant in user.restaurants"
                        :key="restaurant.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                    >
                        <Link
                            :href="restaurantsShow.url(restaurant.subdomain)"
                            class="font-medium text-foreground hover:underline"
                        >
                            {{ restaurant.name }}
                        </Link>
                        <span class="flex items-center gap-2">
                            <span
                                v-if="restaurant.isSoleAdmin"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-500/15 dark:text-amber-200"
                            >
                                Only admin
                            </span>
                            <span
                                class="text-xs text-muted-foreground capitalize"
                            >
                                {{ restaurant.role }}
                            </span>
                        </span>
                    </li>
                </ul>
            </SectionCard>

            <SectionCard
                title="Customer of"
                description="Restaurants this person has ordered from."
            >
                <p
                    v-if="impact.customerRestaurants.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No customer relationships.
                </p>
                <ul v-else class="divide-y divide-border text-sm">
                    <li
                        v-for="restaurant in impact.customerRestaurants"
                        :key="restaurant.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                    >
                        <span class="font-medium text-foreground">
                            {{ restaurant.name }}
                        </span>
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{ restaurant.totalOrders }} orders ·
                            {{ formatCents(restaurant.totalSpentCents) }} · last
                            {{ formatDate(restaurant.lastOrderedAt) }}
                        </span>
                    </li>
                </ul>
            </SectionCard>
        </div>

        <Dialog
            :open="confirmingDelete"
            @update:open="(open: boolean) => (confirmingDelete = open)"
        >
            <DialogContent>
                <DialogHeader class="space-y-3">
                    <DialogTitle>Delete this account?</DialogTitle>
                    <DialogDescription>
                        <span class="font-medium text-foreground">{{
                            user.name
                        }}</span>
                        ({{ user.email }}) will no longer be able to sign in,
                        and the email address is freed for a new account.
                        Nothing is destroyed — you can restore this account from
                        the Deleted filter.
                    </DialogDescription>
                </DialogHeader>

                <p
                    v-if="soleAdminOf.length > 0"
                    class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
                >
                    Heads up: they are the only admin of
                    {{ soleAdminOf.map((r) => r.name).join(', ') }}. Those
                    restaurants keep running, but nobody will be able to manage
                    them until another admin is added.
                </p>

                <DialogFooter class="gap-2">
                    <Button
                        variant="secondary"
                        :disabled="processing"
                        @click="confirmingDelete = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="processing"
                        @click="softDelete"
                    >
                        {{ processing ? 'Deleting…' : 'Delete account' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="confirmingHardDelete"
            @update:open="(open: boolean) => (confirmingHardDelete = open)"
        >
            <DialogContent>
                <DialogHeader class="space-y-3">
                    <DialogTitle>Permanently delete this account?</DialogTitle>
                    <DialogDescription>
                        This erases
                        <span class="font-medium text-foreground">{{
                            user.name
                        }}</span>
                        ({{ user.email }}) and everything attached to it —
                        addresses, carts, and loyalty points. This cannot be
                        undone.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <Button
                        variant="secondary"
                        :disabled="processing"
                        @click="confirmingHardDelete = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="processing"
                        @click="hardDelete"
                    >
                        {{ processing ? 'Deleting…' : 'Permanently delete' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
