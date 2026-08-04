<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Trash2, Upload, X } from 'lucide-vue-next';
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
const MAX_BATCH = 12;

const uploadForm = useForm({
    images: [] as File[],
});

const previews = ref<{ file: File; url: string }[]>([]);

const onFilesPicked = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const picked = Array.from(target.files ?? []);

    for (const file of picked) {
        if (uploadForm.images.length >= MAX_BATCH) {
            break;
        }

        uploadForm.images = [...uploadForm.images, file];
        previews.value.push({ file, url: URL.createObjectURL(file) });
    }

    // Allow re-picking the same file after a remove.
    target.value = '';
};

const removePicked = (index: number): void => {
    URL.revokeObjectURL(previews.value[index].url);
    previews.value.splice(index, 1);
    uploadForm.images = uploadForm.images.filter((_, i) => i !== index);
};

const clearUploadState = (): void => {
    uploadForm.reset();
    uploadForm.clearErrors();

    for (const preview of previews.value) {
        URL.revokeObjectURL(preview.url);
    }

    previews.value = [];
};

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            clearUploadState();
        }
    },
);

// Per-file failures come back keyed `images.N`, which the form's error type
// doesn't know about.
const uploadError = computed(() => {
    const errors = uploadForm.errors as Record<string, string>;

    return (
        errors.images ??
        Object.entries(errors).find(([key]) => key.startsWith('images.'))?.[1]
    );
});

const uploadPhotos = (): void => {
    const count = uploadForm.images.length;

    if (count === 0) {
        return;
    }

    uploadForm.post('/admin/site/photos', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            clearUploadState();
            toast.success(
                count === 1 ? 'Photo added.' : `${count} photos added.`,
            );
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
                    @submit.prevent="uploadPhotos"
                >
                    <Label class="text-sm font-medium">Add photos</Label>
                    <input
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp,image/avif"
                        class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                        @change="onFilesPicked"
                    />
                    <p class="text-xs text-muted-foreground">
                        Select up to {{ MAX_BATCH }} photos at a time. You can
                        add captions below once they're uploaded.
                    </p>
                    <div
                        v-if="previews.length > 0"
                        class="flex flex-wrap gap-2"
                    >
                        <div
                            v-for="(preview, idx) in previews"
                            :key="preview.url"
                            class="relative"
                        >
                            <img
                                :src="preview.url"
                                class="size-20 rounded-md border border-border object-cover"
                                alt=""
                            />
                            <button
                                type="button"
                                class="absolute -top-1.5 -right-1.5 rounded-full bg-foreground/80 p-0.5 text-background hover:bg-foreground"
                                aria-label="Remove photo from selection"
                                @click="removePicked(idx)"
                            >
                                <X class="size-3" />
                            </button>
                        </div>
                    </div>
                    <InputError :message="uploadError" />
                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="
                                uploadForm.images.length === 0 ||
                                uploadForm.processing
                            "
                        >
                            <Upload class="mr-1 size-4" />
                            {{
                                uploadForm.processing
                                    ? 'Uploading…'
                                    : uploadForm.images.length > 1
                                      ? `Add ${uploadForm.images.length} photos`
                                      : 'Add photo'
                            }}
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
