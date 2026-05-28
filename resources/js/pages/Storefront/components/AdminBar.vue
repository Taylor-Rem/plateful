<script setup lang="ts">
import { Plus, Pencil, Eye } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

defineProps<{
    editMode: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:editMode', value: boolean): void;
    (e: 'add-item'): void;
}>();
</script>

<template>
    <div class="sticky top-0 z-50 border-b border-amber-400 bg-amber-50 text-amber-900 shadow-sm dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-6 py-2 text-sm">
            <div class="flex items-center gap-2 font-medium">
                <span class="rounded bg-amber-200 px-1.5 py-0.5 text-xs uppercase tracking-wide text-amber-900 dark:bg-amber-800 dark:text-amber-100">
                    Admin
                </span>
                <span v-if="editMode">Edit mode is on — changes are visible to customers immediately.</span>
                <span v-else>Viewing as a customer.</span>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    v-if="editMode"
                    size="sm"
                    variant="outline"
                    class="gap-1"
                    @click="emit('add-item')"
                >
                    <Plus class="size-4" /> Add item
                </Button>
                <Button
                    size="sm"
                    :variant="editMode ? 'default' : 'outline'"
                    class="gap-1"
                    @click="emit('update:editMode', !editMode)"
                >
                    <component :is="editMode ? Eye : Pencil" class="size-4" />
                    {{ editMode ? 'View as customer' : 'Edit mode' }}
                </Button>
            </div>
        </div>
    </div>
</template>
