<script setup lang="ts">
import type { LucideIcon } from 'lucide-vue-next';

defineProps<{
    title: string;
    description?: string;
    icon?: LucideIcon;
}>();
</script>

<template>
    <div
        class="rounded-lg border border-dashed border-border bg-card p-10 text-center"
    >
        <component
            :is="icon"
            v-if="icon"
            class="mx-auto mb-3 size-8 text-muted-foreground"
        />
        <p class="text-base font-medium text-foreground">{{ title }}</p>
        <p v-if="description" class="mt-1 text-sm text-muted-foreground">
            {{ description }}
        </p>
        <div v-if="$slots.actions" class="mt-4">
            <slot name="actions" />
        </div>
    </div>
</template>
