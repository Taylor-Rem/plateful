<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
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
        `/super/restaurants/${subdomain}/restore`,
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
        `/super/restaurants/${hardDeleteTarget.value.subdomain}/force`,
        {
            onFinish: () => {
                processing.value = false;
                closeHardDelete();
            },
        },
    );
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Restaurants" />
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
                        Restaurants
                    </h1>
                </div>
                <div class="flex items-center gap-4">
                    <AppearanceTabs />
                    <Link
                        href="/super/earnings"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Earnings
                    </Link>
                    <Link
                        href="/super/admins"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Admins
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-6 px-6 py-8">
            <div class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    {{ restaurants.length }}
                    {{
                        restaurants.length === 1 ? 'restaurant' : 'restaurants'
                    }}
                </p>
                <Link href="/super/restaurants/create">
                    <Button>Create restaurant</Button>
                </Link>
            </div>

            <div
                v-if="restaurants.length === 0"
                class="rounded-lg border border-dashed border-border bg-card p-10 text-center text-sm text-muted-foreground"
            >
                No restaurants yet. Create your first one to get started.
            </div>

            <table
                v-else
                class="w-full divide-y divide-border overflow-hidden rounded-lg border border-border bg-card"
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
                                :href="`/super/restaurants/${r.subdomain}`"
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
                                :href="`/super/restaurants/${r.subdomain}`"
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
                <table
                    class="w-full divide-y divide-border overflow-hidden rounded-lg border border-border bg-card"
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
        </main>

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
