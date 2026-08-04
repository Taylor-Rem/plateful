<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '@/components/admin/EmptyState.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SuperAdminLayout from '@/layouts/admin/SuperAdminLayout.vue';
import {
    create as restaurantsCreate,
    forceDelete as restaurantsForceDelete,
    restore as restaurantsRestore,
    show as restaurantsShow,
} from '@/routes/admin/super/restaurants';

type RestaurantRow = App.Data.RestaurantData & {
    adminsCount: number;
};

type DeletedRestaurantRow = {
    id: number;
    name: string;
    subdomain: string;
    deletedAt: string | null;
    ordersCount: number;
};

defineProps<{
    restaurants: RestaurantRow[];
    deletedRestaurants: DeletedRestaurantRow[];
}>();

const processing = ref(false);

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

function restore(subdomain: string): void {
    processing.value = true;
    router.post(
        restaurantsRestore.url(subdomain),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

// Permanent delete is irreversible, so it lives behind a dialog that requires
// typing the subdomain to arm the button.
const hardDeleteTarget = ref<DeletedRestaurantRow | null>(null);
const confirmText = ref('');

const canHardDelete = computed(
    () =>
        hardDeleteTarget.value !== null &&
        hardDeleteTarget.value.ordersCount === 0 &&
        confirmText.value.trim() === hardDeleteTarget.value.subdomain,
);

function openHardDelete(row: DeletedRestaurantRow): void {
    hardDeleteTarget.value = row;
    confirmText.value = '';
}

function closeHardDelete(): void {
    hardDeleteTarget.value = null;
    confirmText.value = '';
}

function confirmHardDelete(): void {
    if (!hardDeleteTarget.value || !canHardDelete.value) {
        return;
    }

    processing.value = true;
    router.delete(
        restaurantsForceDelete.url(hardDeleteTarget.value.subdomain),
        {
            onFinish: () => {
                processing.value = false;
                closeHardDelete();
            },
        },
    );
}

defineOptions({ layout: SuperAdminLayout });
</script>

<template>
    <div>
        <Head title="Restaurants" />
        <div class="space-y-6">
            <PageHeader
                title="Restaurants"
                :description="`${restaurants.length} ${restaurants.length === 1 ? 'restaurant' : 'restaurants'}`"
            >
                <template #actions>
                    <Link :href="restaurantsCreate.url()">
                        <Button>Create restaurant</Button>
                    </Link>
                </template>
            </PageHeader>

            <EmptyState
                v-if="restaurants.length === 0"
                title="No restaurants yet"
                description="Create your first one to get started."
            />

            <ul
                v-else
                class="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card sm:hidden"
            >
                <li v-for="r in restaurants" :key="r.id">
                    <Link
                        :href="restaurantsShow.url(r.subdomain)"
                        class="flex flex-col gap-1 px-4 py-3 transition hover:bg-muted/30"
                    >
                        <span class="flex items-center justify-between gap-2">
                            <span
                                class="truncate text-sm font-medium text-foreground"
                            >
                                {{ r.name }}
                            </span>
                            <span
                                v-if="r.isActive"
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
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ r.subdomain }} · {{ r.adminsCount }}
                            {{ r.adminsCount === 1 ? 'admin' : 'admins' }} ·
                            created {{ formatDate(r.createdAt) }}
                        </span>
                    </Link>
                </li>
            </ul>

            <table
                v-if="restaurants.length > 0"
                class="hidden w-full divide-y divide-border overflow-hidden rounded-lg border border-border bg-card sm:table"
            >
                <thead
                    class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                >
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Subdomain</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Admins</th>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-sm">
                    <tr
                        v-for="r in restaurants"
                        :key="r.id"
                        class="hover:bg-muted/30"
                    >
                        <td class="px-4 py-3 font-medium text-foreground">
                            <Link
                                :href="restaurantsShow.url(r.subdomain)"
                                class="hover:underline"
                            >
                                {{ r.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ r.subdomain }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="r.isActive"
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
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ r.adminsCount }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ formatDate(r.createdAt) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link
                                :href="restaurantsShow.url(r.subdomain)"
                                class="text-sm text-primary hover:opacity-80"
                            >
                                Open →
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>

            <section v-if="deletedRestaurants.length > 0" class="space-y-3">
                <h2 class="text-sm font-semibold text-foreground">
                    Deleted restaurants
                </h2>
                <p class="text-sm text-muted-foreground">
                    These are hidden from the roster and their storefronts are
                    offline. Restore one to bring it back, or permanently delete
                    it (only allowed when it has no order history).
                </p>
                <ul
                    class="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card sm:hidden"
                >
                    <li
                        v-for="r in deletedRestaurants"
                        :key="r.id"
                        class="flex flex-col gap-2 px-4 py-3"
                    >
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm font-medium text-foreground">
                                {{ r.name }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ r.subdomain }} · {{ r.ordersCount }}
                                {{ r.ordersCount === 1 ? 'order' : 'orders' }} ·
                                deleted {{ formatDate(r.deletedAt) }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                                @click="restore(r.subdomain)"
                            >
                                Restore
                            </Button>
                            <Button
                                variant="destructive"
                                size="sm"
                                :disabled="processing || r.ordersCount > 0"
                                :title="
                                    r.ordersCount > 0
                                        ? 'Has order history — permanent delete is disabled'
                                        : undefined
                                "
                                @click="openHardDelete(r)"
                            >
                                Delete permanently
                            </Button>
                        </div>
                    </li>
                </ul>
                <table
                    class="hidden w-full divide-y divide-border overflow-hidden rounded-lg border border-border bg-card sm:table"
                >
                    <thead
                        class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Subdomain</th>
                            <th class="px-4 py-3">Orders</th>
                            <th class="px-4 py-3">Deleted</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-sm">
                        <tr
                            v-for="r in deletedRestaurants"
                            :key="r.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-medium text-foreground">
                                {{ r.name }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ r.subdomain }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ r.ordersCount }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatDate(r.deletedAt) }}
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :disabled="processing"
                                        @click="restore(r.subdomain)"
                                    >
                                        Restore
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        :disabled="
                                            processing || r.ordersCount > 0
                                        "
                                        :title="
                                            r.ordersCount > 0
                                                ? 'Has order history — permanent delete is disabled'
                                                : undefined
                                        "
                                        @click="openHardDelete(r)"
                                    >
                                        Delete permanently
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <Dialog
            :open="hardDeleteTarget !== null"
            @update:open="(open: boolean) => !open && closeHardDelete()"
        >
            <DialogContent>
                <DialogHeader class="space-y-3">
                    <DialogTitle>Permanently delete restaurant?</DialogTitle>
                    <DialogDescription>
                        This will permanently delete
                        <span class="font-medium text-foreground">{{
                            hardDeleteTarget?.name
                        }}</span>
                        and all of its menus, integrations, and settings. This
                        cannot be undone. Type
                        <span class="font-mono font-medium text-foreground">{{
                            hardDeleteTarget?.subdomain
                        }}</span>
                        to confirm.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="confirm-subdomain" class="sr-only"
                        >Subdomain</Label
                    >
                    <Input
                        id="confirm-subdomain"
                        v-model="confirmText"
                        :placeholder="hardDeleteTarget?.subdomain"
                        autocomplete="off"
                    />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        variant="secondary"
                        :disabled="processing"
                        @click="closeHardDelete"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="!canHardDelete || processing"
                        @click="confirmHardDelete"
                    >
                        {{ processing ? 'Deleting…' : 'Permanently delete' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
