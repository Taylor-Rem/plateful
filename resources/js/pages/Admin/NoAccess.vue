<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-6">
        <Head title="No access" />
        <div class="max-w-md text-center">
            <h1 class="text-2xl font-semibold text-neutral-900">No restaurants assigned</h1>
            <p class="mt-3 text-sm text-neutral-600">
                Your account doesn't have access to any restaurants yet. Please contact your
                administrator to be assigned to a restaurant.
            </p>
            <Link
                href="/logout"
                method="post"
                as="button"
                class="mt-6 inline-block text-sm text-neutral-700 underline hover:text-neutral-900"
            >
                Log out
            </Link>
        </div>
    </div>
</template>
