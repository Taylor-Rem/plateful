<script setup lang="ts">
defineProps<{
    label: string;
    value?: string | number | null;
    hint?: string;
}>();
</script>

<template>
    <div class="rounded-lg border border-border bg-card p-4">
        <div class="text-xs tracking-wide text-muted-foreground uppercase">
            {{ label }}
        </div>
        <div class="mt-2 text-2xl font-semibold text-foreground">
            <slot>{{ value ?? '—' }}</slot>
        </div>
        <div v-if="hint" class="mt-1 text-xs text-muted-foreground">
            {{ hint }}
        </div>
    </div>
</template>
