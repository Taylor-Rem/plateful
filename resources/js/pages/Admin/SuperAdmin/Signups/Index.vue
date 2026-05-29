<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';

type SignupRow = {
    id: number;
    restaurantName: string;
    subdomain: string;
    city: string | null;
    state: string | null;
    cuisineType: string | null;
    ownerName: string | null;
    ownerEmail: string | null;
    status: 'pending' | 'approved' | 'rejected';
    submittedAt: string | null;
    reviewedAt: string | null;
    restaurantSubdomain: string | null;
};

const props = defineProps<{
    signups: SignupRow[];
    status: string;
    counts: { pending: number; approved: number; rejected: number };
}>();

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return '—';
    }
}

function setStatus(status: string) {
    router.visit('/super/signups', {
        method: 'get',
        data: { status },
        preserveScroll: true,
    });
}

const tabs: { key: 'pending' | 'approved' | 'rejected'; label: string }[] = [
    { key: 'pending', label: 'Pending' },
    { key: 'approved', label: 'Approved' },
    { key: 'rejected', label: 'Rejected' },
];
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Restaurant signups" />

        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link href="/" class="text-sm text-muted-foreground hover:text-foreground">←</Link>
                    <h1 class="text-lg font-semibold text-foreground">Restaurant signups</h1>
                </div>
                <div class="flex items-center gap-4">
                    <AppearanceTabs />
                    <Link href="/super/restaurants" class="text-sm text-muted-foreground hover:text-foreground">Restaurants</Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-6 px-6 py-8">
            <div class="flex items-center gap-2">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    @click="setStatus(t.key)"
                    :class="[
                        'rounded-md px-3 py-1.5 text-sm',
                        props.status === t.key
                            ? 'bg-foreground text-background'
                            : 'border border-border text-muted-foreground hover:bg-muted/50',
                    ]"
                >
                    {{ t.label }}
                    <span class="ml-1 text-xs opacity-70">{{ props.counts[t.key] }}</span>
                </button>
            </div>

            <div
                v-if="signups.length === 0"
                class="rounded-lg border border-dashed border-border bg-card p-10 text-center text-sm text-muted-foreground"
            >
                No {{ status }} signups.
            </div>

            <table
                v-else
                class="w-full divide-y divide-border overflow-hidden rounded-lg border border-border bg-card"
            >
                <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3">Restaurant</th>
                        <th class="px-4 py-3">Owner</th>
                        <th class="px-4 py-3">Location</th>
                        <th class="px-4 py-3">Submitted</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-sm">
                    <tr v-for="s in signups" :key="s.id" class="hover:bg-muted/30">
                        <td class="px-4 py-3 font-medium text-foreground">
                            <Link :href="`/super/signups/${s.id}`" class="hover:underline">
                                {{ s.restaurantName }}
                            </Link>
                            <div class="text-xs text-muted-foreground">{{ s.subdomain }}</div>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <div>{{ s.ownerName }}</div>
                            <div class="text-xs">{{ s.ownerEmail }}</div>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <span v-if="s.city || s.state">{{ [s.city, s.state].filter(Boolean).join(', ') }}</span>
                            <span v-else>—</span>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">{{ formatDate(s.submittedAt) }}</td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="`/super/signups/${s.id}`" class="text-sm text-primary hover:opacity-80">Review →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </main>
    </div>
</template>
