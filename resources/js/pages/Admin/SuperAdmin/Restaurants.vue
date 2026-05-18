<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    restaurants: App.Data.RestaurantData[];
}>();
</script>

<template>
    <div class="min-h-screen bg-neutral-50">
        <Head title="Restaurants" />
        <header class="border-b border-neutral-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link href="/" class="text-sm text-neutral-500 hover:text-neutral-900">←</Link>
                    <h1 class="text-lg font-semibold text-neutral-900">Restaurants</h1>
                </div>
                <Link href="/super/admins" class="text-sm text-neutral-600 hover:text-neutral-900">
                    Admins
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-8">
            <table class="w-full divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white">
                <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Subdomain</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 text-sm">
                    <tr v-for="r in restaurants" :key="r.id">
                        <td class="px-4 py-3 font-medium text-neutral-900">{{ r.name }}</td>
                        <td class="px-4 py-3 text-neutral-600">{{ r.subdomain }}</td>
                        <td class="px-4 py-3 text-right">
                            <Link
                                :href="`/${r.subdomain}/dashboard`"
                                class="text-sm text-indigo-600 hover:text-indigo-800"
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
