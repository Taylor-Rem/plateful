<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Button } from '@/components/ui/button';

type RestaurantRow = App.Data.RestaurantData & {
    adminsCount: number;
};

defineProps<{
    restaurants: RestaurantRow[];
}>();

function formatDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return '—';
    }
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Restaurants" />
        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link href="/" class="text-sm text-muted-foreground hover:text-foreground">←</Link>
                    <h1 class="text-lg font-semibold text-foreground">Restaurants</h1>
                </div>
                <div class="flex items-center gap-4">
                    <AppearanceTabs />
                    <Link href="/super/earnings" class="text-sm text-muted-foreground hover:text-foreground">
                        Earnings
                    </Link>
                    <Link href="/super/admins" class="text-sm text-muted-foreground hover:text-foreground">
                        Admins
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-6 px-6 py-8">
            <div class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    {{ restaurants.length }}
                    {{ restaurants.length === 1 ? 'restaurant' : 'restaurants' }}
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
                <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
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
                    <tr v-for="r in restaurants" :key="r.id" class="hover:bg-muted/30">
                        <td class="px-4 py-3 font-medium text-foreground">
                            <Link :href="`/super/restaurants/${r.subdomain}`" class="hover:underline">
                                {{ r.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">{{ r.subdomain }}</td>
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
                        <td class="px-4 py-3 text-muted-foreground">{{ r.adminsCount }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ formatDate(r.createdAt) }}</td>
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
        </main>
    </div>
</template>
