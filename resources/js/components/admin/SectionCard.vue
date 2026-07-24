<script setup lang="ts">
defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <section class="rounded-lg border border-border bg-card p-6">
        <h2 v-if="title" class="text-base font-semibold text-foreground">
            {{ title }}
        </h2>
        <p v-if="description" class="mt-1 text-sm text-muted-foreground">
            {{ description }}
        </p>
        <div :class="title || description ? 'mt-4' : ''">
            <slot />
        </div>
    </section>
</template>
