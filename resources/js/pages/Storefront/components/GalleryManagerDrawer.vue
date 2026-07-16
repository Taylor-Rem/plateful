<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Trash2, Upload } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

const props = defineProps<{
    open: boolean;
    photos: App.Data.RestaurantPhotoData[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const close = (): void => emit('update:open', false);

// ----- Upload -----
const uploadForm = useForm({
    image: null as File | null,
    caption: '' as string,
});

const newImagePreview = ref<string | null>(null);

const onImageChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    uploadForm.image = file;

    if (newImagePreview.value) {
        URL.revokeObjectURL(newImagePreview.value);
    }

    newImagePreview.value = file ? URL.createObjectURL(file) : null;
};

const clearUploadState = (): void => {
    uploadForm.reset();
    uploadForm.clearErrors();

    if (newImagePreview.value) {
        URL.revokeObjectURL(newImagePreview.value);
        newImagePreview.value = null;
    }
};

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            clearUploadState();
        }
    },
);

const uploadPhoto = (): void => {
    if (!uploadForm.image) {
        return;
    }

    uploadForm.post('/admin/site/photos', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            clearUploadState();
            toast.success('Photo added.');
        },
    });
};

// ----- Captions (inline edit) -----
const editingCaptions = ref<Record<number, string>>({});

const captionFor = (photo: App.Data.RestaurantPhotoData): string =>
    editingCaptions.value[photo.id] ?? photo.caption ?? '';

const setCaption = (
    photo: App.Data.RestaurantPhotoData,
    value: string,
): void => {
    editingCaptions.value[photo.id] = value;
};

const saveCaption = (photo: App.Data.RestaurantPhotoData): void => {
    const next = captionFor(photo);

    if ((photo.caption ?? '') === next) {
        return;
    }

    router.patch(
        `/admin/site/photos/${photo.id}`,
        { caption: next },
        {
            preserveScroll: true,
            onSuccess: () => {
                delete editingCaptions.value[photo.id];
                toast.success('Caption updated.');
            },
        },
    );
};

// ----- Reorder -----
const localOrder = ref<number[]>([]);

watch(
    () => props.photos,
    (list) => {
        localOrder.value = list.map((p) => p.id);
    },
    { immediate: true, deep: true },
);

const orderedPhotos = computed(() => {
    const byId = new Map(props.photos.map((p) => [p.id, p]));

    return localOrder.value
        .map((id) => byId.get(id))
        .filter((p): p is App.Data.RestaurantPhotoData => Boolean(p));
});

const move = (idx: number, delta: number): void => {
    const target = idx + delta;

    if (target < 0 || target >= localOrder.value.length) {
        return;
    }

    const copy = [...localOrder.value];
    const [moved] = copy.splice(idx, 1);
    copy.splice(target, 0, moved);
    localOrder.value = copy;

    router.post(
        '/admin/site/photos/reorder',
        { ids: copy },
        {
            preserveScroll: true,
            onError: () => {
                // Revert on failure.
                localOrder.value = props.photos.map((p) => p.id);
                toast.error('Could not reorder.');
            },
        },
    );
};

// ----- Delete -----
const destroy = (photo: App.Data.RestaurantPhotoData): void => {
    if (!window.confirm('Remove this photo?')) {
        return;
    }

    router.delete(`/admin/site/photos/${photo.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Photo removed.'),
    });
};
</script>

<template>
    <Sheet :open="open" @update:open="(v) => emit('update:open', v)">
        <SheetContent class="w-full max-w-xl overflow-y-auto sm:max-w-xl">
            <SheetHeader>
                <SheetTitle>Manage photos</SheetTitle>
            </SheetHeader>

            <div class="space-y-6 px-4 py-4">
                <!-- Upload -->
                <form
                    class="space-y-3 rounded-md border border-border bg-card p-3"
                    @submit.prevent="uploadPhoto"
                >
                    <Label class="text-sm font-medium">Add a photo</Label>
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-24 items-center justify-center overflow-hidden rounded-md border border-dashed border-border bg-muted/30"
                        >
                            <img
                                v-if="newImagePreview"
                                :src="newImagePreview"
                                class="size-full object-cover"
                                alt=""
                            />
                            <span
                                v-else
                                class="px-2 text-center text-xs text-muted-foreground"
                                >Pick an image</span
                            >
                        </div>
                        <div class="flex-1 space-y-2">
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                @change="onImageChange"
                            />
                            <Input
                                v-model="uploadForm.caption"
                                maxlength="140"
                                placeholder="Caption (optional)"
                            />
                            <InputError :message="uploadForm.errors.image" />
                            <InputError :message="uploadForm.errors.caption" />
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="
                                !uploadForm.image || uploadForm.processing
                            "
                        >
                            <Upload class="mr-1 size-4" /> Add photo
                        </Button>
                    </div>
                </form>

                <!-- Existing photos -->
                <div v-if="orderedPhotos.length > 0" class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        Use the arrows to reorder. Customers see them in this
                        order.
                    </p>
                    <ul class="space-y-2">
                        <li
                            v-for="(photo, idx) in orderedPhotos"
                            :key="photo.id"
                            class="flex items-start gap-3 rounded-md border border-border bg-card p-2"
                        >
                            <img
                                :src="
                                    photo.imageThumbUrl ??
                                    photo.imageMediumUrl ??
                                    ''
                                "
                                :alt="photo.caption ?? ''"
                                class="size-20 shrink-0 rounded object-cover"
                            />
                            <div class="flex-1 space-y-1">
                                <Input
                                    :model-value="captionFor(photo)"
                                    maxlength="140"
                                    placeholder="Caption (optional)"
                                    @update:model-value="
                                        (v) => setCaption(photo, String(v))
                                    "
                                    @blur="saveCaption(photo)"
                                />
                            </div>
                            <div class="flex flex-col gap-1">
                                <button
                                    type="button"
                                    class="rounded-md p-1 text-muted-foreground hover:bg-muted disabled:opacity-30"
                                    :disabled="idx === 0"
                                    aria-label="Move up"
                                    @click="move(idx, -1)"
                                >
                                    <ArrowUp class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md p-1 text-muted-foreground hover:bg-muted disabled:opacity-30"
                                    :disabled="idx === orderedPhotos.length - 1"
                                    aria-label="Move down"
                                    @click="move(idx, 1)"
                                >
                                    <ArrowDown class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md p-1 text-destructive hover:bg-destructive/10"
                                    aria-label="Delete"
                                    @click="destroy(photo)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No photos yet — add your first above.
                </p>
            </div>

            <SheetFooter class="flex-row items-center justify-end gap-2 pt-2">
                <Button type="button" variant="outline" @click="close"
                    >Done</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
