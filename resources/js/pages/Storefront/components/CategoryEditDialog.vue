<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    open: boolean;
    /** null means "create a new category". */
    category: App.Data.MenuCategoryData | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'delete-requested', category: App.Data.MenuCategoryData): void;
}>();

const form = useForm({
    name: '',
    description: '' as string | null,
});

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            form.name = props.category?.name ?? '';
            form.description = props.category?.description ?? '';
            form.clearErrors();
        }
    },
);

const submit = (): void => {
    if (props.category) {
        form.put(`/admin/menu/categories/${props.category.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                emit('update:open', false);
                toast.success('Category updated.');
            },
        });

        return;
    }

    form.post('/admin/menu/categories', {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:open', false);
            toast.success('Category added.');
        },
    });
};
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    category ? `Edit "${category.name}"` : 'Add category'
                }}</DialogTitle>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="category-name">Name</Label>
                    <Input
                        id="category-name"
                        v-model="form.name"
                        type="text"
                        required
                        placeholder="Appetizers"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="category-description"
                        >Description
                        <span class="font-normal text-muted-foreground"
                            >— optional</span
                        ></Label
                    >
                    <textarea
                        id="category-description"
                        v-model="form.description"
                        rows="2"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        placeholder="Shown under the category name on your menu."
                    ></textarea>
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter
                    class="flex-row items-center gap-2"
                    :class="
                        category ? 'justify-between sm:justify-between' : ''
                    "
                >
                    <Button
                        v-if="category"
                        type="button"
                        variant="destructive"
                        size="sm"
                        @click="emit('delete-requested', category)"
                    >
                        Delete
                    </Button>
                    <div class="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="emit('update:open', false)"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Saving…'
                                    : category
                                      ? 'Save'
                                      : 'Add category'
                            }}
                        </Button>
                    </div>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
