<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
    restaurant: App.Data.RestaurantData;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const buildInitial = () => ({
    about_body: props.restaurant.aboutBody ?? '',
    image: null as File | null,
    remove_image: false as boolean,
});

const form = useForm(buildInitial());

const newImagePreview = ref<string | null>(null);
const currentImage = computed(
    () =>
        props.restaurant.aboutImageMediumUrl ??
        props.restaurant.aboutImageUrl ??
        null,
);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            Object.assign(form, buildInitial());
            form.clearErrors();

            if (newImagePreview.value) {
                URL.revokeObjectURL(newImagePreview.value);
                newImagePreview.value = null;
            }
        }
    },
);

const onImageChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    form.image = file;
    form.remove_image = false;

    if (newImagePreview.value) {
        URL.revokeObjectURL(newImagePreview.value);
    }

    newImagePreview.value = file ? URL.createObjectURL(file) : null;
};

const markRemoveImage = (): void => {
    form.remove_image = true;
    form.image = null;

    if (newImagePreview.value) {
        URL.revokeObjectURL(newImagePreview.value);
        newImagePreview.value = null;
    }
};

const undoRemoveImage = (): void => {
    form.remove_image = false;
};

const close = (): void => emit('update:open', false);

const submit = (): void => {
    form.post('/admin/site/about', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: close,
    });
};
</script>

<template>
    <Sheet :open="open" @update:open="(v) => emit('update:open', v)">
        <SheetContent class="w-full max-w-xl overflow-y-auto sm:max-w-xl">
            <SheetHeader>
                <SheetTitle>Edit about</SheetTitle>
            </SheetHeader>

            <form class="space-y-5 px-4 py-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="about-body">Your story</Label>
                    <textarea
                        id="about-body"
                        v-model="form.about_body"
                        rows="8"
                        maxlength="5000"
                        placeholder="Tell customers who you are, what you make, and what makes your place special."
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:border-ring focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                    <p class="text-xs text-muted-foreground">
                        Separate paragraphs with a blank line.
                    </p>
                    <InputError :message="form.errors.about_body" />
                </div>

                <div class="grid gap-2">
                    <Label>Photo</Label>
                    <div class="space-y-2">
                        <div
                            class="aspect-[4/3] w-full overflow-hidden rounded-md border border-dashed border-border bg-muted/30"
                        >
                            <img
                                v-if="newImagePreview"
                                :src="newImagePreview"
                                class="size-full object-cover"
                                alt=""
                            />
                            <img
                                v-else-if="currentImage && !form.remove_image"
                                :src="currentImage"
                                class="size-full object-cover"
                                alt=""
                            />
                            <div
                                v-else
                                class="flex size-full items-center justify-center text-xs text-muted-foreground"
                            >
                                No photo yet
                            </div>
                        </div>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                            @change="onImageChange"
                        />
                        <button
                            v-if="currentImage && !form.remove_image"
                            type="button"
                            class="text-xs text-destructive hover:opacity-80"
                            @click="markRemoveImage"
                        >
                            Remove photo
                        </button>
                        <p
                            v-if="form.remove_image"
                            class="text-xs text-amber-600"
                        >
                            Will remove on save.
                            <button
                                type="button"
                                class="underline"
                                @click="undoRemoveImage"
                            >
                                Undo
                            </button>
                        </p>
                        <InputError :message="form.errors.image" />
                    </div>
                </div>

                <SheetFooter
                    class="flex-row items-center justify-end gap-2 pt-2"
                >
                    <Button type="button" variant="outline" @click="close"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="form.processing"
                        >Save changes</Button
                    >
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
