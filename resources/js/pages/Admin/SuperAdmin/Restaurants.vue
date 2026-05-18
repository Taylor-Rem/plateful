<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';

defineProps<{
    restaurants: App.Data.RestaurantData[];
}>();
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
                    <Link href="/super/admins" class="text-sm text-muted-foreground hover:text-foreground">
                        Admins
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-8">
            <table class="w-full divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
                <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Subdomain</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-sm">
                    <tr v-for="r in restaurants" :key="r.id">
                        <td class="px-4 py-3 font-medium text-foreground">{{ r.name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ r.subdomain }}</td>
                        <td class="px-4 py-3 text-right">
                            <Link
                                :href="`/${r.subdomain}/dashboard`"
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
