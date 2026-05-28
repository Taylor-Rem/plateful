<script setup lang="ts">
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    open: boolean;
    item: App.Data.MenuItemData | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'deleted'): void;
}>();

const processing = ref(false);

const confirm = (): void => {
    if (!props.item) return;
    processing.value = true;
    router.delete(`/admin/menu/items/${props.item.id}`, {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
        },
        onSuccess: () => {
            emit('deleted');
            emit('update:open', false);
        },
    });
};
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete "{{ item?.name }}"?</DialogTitle>
            </DialogHeader>
            <p class="text-sm text-muted-foreground">
                Customers will no longer see this item. Past order history is preserved.
            </p>
            <DialogFooter>
                <Button type="button" variant="outline" @click="emit('update:open', false)">Cancel</Button>
                <Button type="button" variant="destructive" :disabled="processing" @click="confirm">Delete</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
