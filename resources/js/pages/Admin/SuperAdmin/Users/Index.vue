<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
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
import SuperAdminLayout from '@/layouts/admin/SuperAdminLayout.vue';
import {
    destroy as usersDestroy,
    forceDelete as usersForceDelete,
    index as usersIndex,
    restore as usersRestore,
    show as usersShow,
} from '@/routes/admin/super/users';
import type { UserFilter, UserRow } from './types';
import { TYPE_BADGE_CLASSES, TYPE_LABELS } from './types';

const props = defineProps<{
    users: UserRow[];
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        filter: UserFilter;
        search: string;
    };
    filterCounts: Record<UserFilter, number>;
}>();

const FILTERS: { value: UserFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'admins', label: 'Admins' },
    { value: 'customers', label: 'Customers' },
    { value: 'deleted', label: 'Deleted' },
];

const search = ref<string>(props.filters.search ?? '');
const processing = ref(false);

function visitWithFilters(params: {
    filter?: UserFilter;
    search?: string;
    page?: number;
}): void {
    const query: Record<string, string> = {};
    const nextFilter = params.filter ?? props.filters.filter;
    const nextSearch = params.search ?? props.filters.search ?? '';

    if (nextFilter !== 'all') {
        query.filter = nextFilter;
    }

    if (nextSearch) {
        query.search = nextSearch;
    }

    if (params.page && params.page > 1) {
        query.page = String(params.page);
    }

    router.get(usersIndex.url(), query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

let searchTimer: number | undefined;
watch(search, (value) => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        visitWithFilters({ search: value ?? '', page: 1 });
    }, 300);
});

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

const deleteTarget = ref<UserRow | null>(null);

const soleAdminOf = computed(() =>
    (deleteTarget.value?.restaurants ?? []).filter((r) => r.isSoleAdmin),
);

function confirmDelete(): void {
    if (!deleteTarget.value) {
        return;
    }

    processing.value = true;
    router.delete(usersDestroy.url(deleteTarget.value.id), {
        onFinish: () => {
            processing.value = false;
            deleteTarget.value = null;
        },
    });
}

function restore(row: UserRow): void {
    processing.value = true;
    router.post(
        usersRestore.url(row.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

// Permanent delete wipes the account for good, so it stays behind its own
// dialog — and the server refuses outright once there is order history.
const hardDeleteTarget = ref<UserRow | null>(null);

function confirmHardDelete(): void {
    if (!hardDeleteTarget.value) {
        return;
    }

    processing.value = true;
    router.delete(usersForceDelete.url(hardDeleteTarget.value.id), {
        onFinish: () => {
            processing.value = false;
            hardDeleteTarget.value = null;
        },
    });
}

defineOptions({ layout: SuperAdminLayout });
</script>

<template>
    <div>
        <Head title="Users" />

        <div class="space-y-6">
            <PageHeader
                title="Users"
                :description="`${pagination.total} ${pagination.total === 1 ? 'account' : 'accounts'}`"
            />

            <div class="flex flex-wrap items-center gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="tab in FILTERS"
                        :key="tab.value"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                        :class="
                            filters.filter === tab.value
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted text-muted-foreground hover:bg-muted/80'
                        "
                        @click="
                            visitWithFilters({ filter: tab.value, page: 1 })
                        "
                    >
                        {{ tab.label }}
                        <span
                            class="ml-1 rounded bg-background/40 px-1 text-[10px] tabular-nums"
                        >
                            {{ filterCounts[tab.value] }}
                        </span>
                    </button>
                </div>

                <div class="max-w-sm flex-1">
                    <Input
                        v-model="search"
                        type="search"
                        placeholder="Search by name or email…"
                    />
                </div>
            </div>

            <EmptyState
                v-if="users.length === 0"
                :icon="Users"
                title="No accounts match"
                description="Try a different filter or search term."
            />

            <div
                v-else
                class="overflow-x-auto rounded-lg border border-border bg-card"
            >
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 font-medium">Restaurants</th>
                            <th class="px-4 py-3 font-medium">Orders</th>
                            <th class="px-4 py-3 font-medium">
                                {{
                                    filters.filter === 'deleted'
                                        ? 'Deleted'
                                        : 'Joined'
                                }}
                            </th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="row in users"
                            :key="row.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="usersShow.url(row.id)"
                                    class="font-medium text-foreground hover:underline"
                                >
                                    {{ row.name }}
                                </Link>
                                <div class="text-xs text-muted-foreground">
                                    {{ row.email }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="TYPE_BADGE_CLASSES[row.type]"
                                    >
                                        {{ TYPE_LABELS[row.type] }}
                                    </span>
                                    <span
                                        v-if="row.isDeleted"
                                        class="inline-flex rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive"
                                    >
                                        Deleted
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                <span v-if="row.restaurants.length === 0"
                                    >—</span
                                >
                                <span v-else>
                                    {{
                                        row.restaurants
                                            .map((r) => r.name)
                                            .join(', ')
                                    }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-3 text-muted-foreground tabular-nums"
                            >
                                {{ row.ordersCount }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{
                                    formatDate(
                                        row.isDeleted
                                            ? row.deletedAt
                                            : row.createdAt,
                                    )
                                }}
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <template v-if="row.isDeleted">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            :disabled="processing"
                                            @click="restore(row)"
                                        >
                                            Restore
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            :disabled="
                                                processing ||
                                                row.ordersCount > 0
                                            "
                                            :title="
                                                row.ordersCount > 0
                                                    ? 'Has order history — permanent delete is disabled'
                                                    : undefined
                                            "
                                            @click="hardDeleteTarget = row"
                                        >
                                            Delete permanently
                                        </Button>
                                    </template>
                                    <template v-else>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            :disabled="
                                                processing ||
                                                row.deleteBlockedReason !== null
                                            "
                                            :title="
                                                row.deleteBlockedReason ??
                                                undefined
                                            "
                                            @click="deleteTarget = row"
                                        >
                                            Delete
                                        </Button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="pagination.lastPage > 1"
                class="flex items-center justify-between text-sm text-muted-foreground"
            >
                <span>
                    Showing {{ pagination.from ?? 0 }}–{{
                        pagination.to ?? 0
                    }}
                    of {{ pagination.total }}
                </span>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-border bg-card px-3 py-1.5 text-xs disabled:opacity-50"
                        :disabled="pagination.currentPage <= 1"
                        @click="
                            visitWithFilters({
                                page: pagination.currentPage - 1,
                            })
                        "
                    >
                        Previous
                    </button>
                    <span class="text-xs tabular-nums">
                        Page {{ pagination.currentPage }} of
                        {{ pagination.lastPage }}
                    </span>
                    <button
                        type="button"
                        class="rounded-md border border-border bg-card px-3 py-1.5 text-xs disabled:opacity-50"
                        :disabled="
                            pagination.currentPage >= pagination.lastPage
                        "
                        @click="
                            visitWithFilters({
                                page: pagination.currentPage + 1,
                            })
                        "
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>

        <Dialog
            :open="deleteTarget !== null"
            @update:open="(open: boolean) => !open && (deleteTarget = null)"
        >
            <DialogContent>
                <DialogHeader class="space-y-3">
                    <DialogTitle>Delete this account?</DialogTitle>
                    <DialogDescription>
                        <span class="font-medium text-foreground">{{
                            deleteTarget?.name
                        }}</span>
                        ({{ deleteTarget?.email }}) will no longer be able to
                        sign in, and the email address is freed for a new
                        account. Nothing is destroyed — you can restore this
                        account from the Deleted filter.
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
                        @click="deleteTarget = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="processing"
                        @click="confirmDelete"
                    >
                        {{ processing ? 'Deleting…' : 'Delete account' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="hardDeleteTarget !== null"
            @update:open="(open: boolean) => !open && (hardDeleteTarget = null)"
        >
            <DialogContent>
                <DialogHeader class="space-y-3">
                    <DialogTitle>Permanently delete this account?</DialogTitle>
                    <DialogDescription>
                        This erases
                        <span class="font-medium text-foreground">{{
                            hardDeleteTarget?.name
                        }}</span>
                        ({{ hardDeleteTarget?.email }}) and everything attached
                        to it — addresses, carts, and loyalty points. This
                        cannot be undone.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <Button
                        variant="secondary"
                        :disabled="processing"
                        @click="hardDeleteTarget = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="processing"
                        @click="confirmHardDelete"
                    >
                        {{ processing ? 'Deleting…' : 'Permanently delete' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
