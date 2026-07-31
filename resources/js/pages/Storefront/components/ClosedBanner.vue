<script setup lang="ts">
import { Clock } from 'lucide-vue-next';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();
</script>

<template>
    <div
        v-if="restaurant.isOpen === false"
        class="border-b border-amber-300 bg-amber-100 text-amber-900"
        role="status"
    >
        <div
            class="mx-auto flex max-w-5xl items-center gap-2 px-6 py-3 text-sm"
        >
            <Clock class="size-4 shrink-0" />
            <span>
                <strong class="font-semibold">We're currently closed.</strong>
                {{ restaurant.nextOpenLabel }}
            </span>
        </div>
    </div>
</template>
