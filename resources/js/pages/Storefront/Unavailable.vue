<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    restaurantName?: string | null;
}>();
</script>

<template>
    <div
        class="flex min-h-screen items-center justify-center bg-neutral-50 px-6 py-10 text-neutral-900"
    >
        <Head title="Unavailable" />
        <div class="max-w-md text-center">
            <h1 class="text-2xl font-semibold">
                {{ restaurantName ?? 'This restaurant' }} is currently
                unavailable
            </h1>
            <p class="mt-3 text-sm text-neutral-600">
                Please check back later.
            </p>
        </div>
    </div>
</template>
