<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Image as ImageIcon, LoaderCircle, Sparkles, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    discard as menuImportDiscard,
    review as menuImportReview,
    store as menuImportStore,
} from '@/routes/admin/restaurant/menuImport';

type MenuImportState = {
    id: number;
    status: 'queued' | 'processing' | 'needs_review' | 'failed';
    error: string | null;
    itemCount: number;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    menuImport: MenuImportState | null;
    menuImportLimits: { maxFiles: number; maxFileKb: number };
    hasExistingMenu: boolean;
}>();

const expanded = ref(false);

const fileInput = ref<HTMLInputElement | null>(null);
const uploadForm = useForm<{ files: File[] }>({ files: [] });

const onFilesPicked = (event: Event): void => {
    const picked = Array.from((event.target as HTMLInputElement).files ?? []);
    uploadForm.files = [...uploadForm.files, ...picked].slice(
        0,
        props.menuImportLimits.maxFiles,
    );

    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const removeFile = (index: number): void => {
    uploadForm.files = uploadForm.files.filter((_, i) => i !== index);
};

const startImport = (): void => {
    uploadForm.post(menuImportStore.url(props.restaurant.subdomain), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    });
};

const isExtracting = computed(
    () =>
        props.menuImport?.status === 'queued' ||
        props.menuImport?.status === 'processing',
);

let poller: ReturnType<typeof setInterval> | null = null;

watch(
    isExtracting,
    (active) => {
        if (active && !poller) {
            poller = setInterval(() => {
                router.reload({ only: ['menuImport'] });
            }, 3000);
        } else if (!active && poller) {
            clearInterval(poller);
            poller = null;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (poller) {
        clearInterval(poller);
    }
});

const discardImport = (): void => {
    if (!props.menuImport) {
        return;
    }

    router.post(
        menuImportDiscard.url({
            restaurant: props.restaurant.subdomain,
            menuImport: props.menuImport.id,
        }),
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <div
        class="rounded-lg border border-border bg-card p-4"
        data-test="menu-import-card"
    >
        <!-- Extraction running -->
        <div
            v-if="isExtracting"
            class="flex items-center gap-3"
            data-test="menu-import-progress"
        >
            <LoaderCircle class="size-5 animate-spin text-primary" />
            <div>
                <p class="text-sm font-medium">Reading your menu…</p>
                <p class="text-sm text-muted-foreground">
                    This usually takes about half a minute. You can leave this
                    page — we'll keep working.
                </p>
            </div>
        </div>

        <!-- Draft ready for review -->
        <div
            v-else-if="menuImport?.status === 'needs_review'"
            class="flex flex-wrap items-center justify-between gap-3"
            data-test="menu-import-ready"
        >
            <div class="flex items-start gap-2">
                <Sparkles class="mt-0.5 size-5 text-primary" />
                <div>
                    <p class="text-sm font-medium">
                        We read {{ menuImport.itemCount }} items from your menu.
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Review the draft —
                        {{
                            hasExistingMenu
                                ? 'your current menu is only replaced when you confirm it.'
                                : 'nothing is imported until you confirm it.'
                        }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="text-sm text-muted-foreground underline hover:text-foreground"
                    @click="discardImport"
                >
                    Discard
                </button>
                <Button as-child data-test="review-import-button">
                    <a
                        :href="
                            menuImportReview.url({
                                restaurant: restaurant.subdomain,
                                menuImport: menuImport.id,
                            })
                        "
                    >
                        Review {{ menuImport.itemCount }} items
                    </a>
                </Button>
            </div>
        </div>

        <!-- Collapsed / upload / failure -->
        <template v-else>
            <div
                v-if="menuImport?.status === 'failed'"
                class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                data-test="menu-import-failed"
            >
                {{
                    menuImport.error ??
                    'Something went wrong while reading your menu.'
                }}
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-start gap-2">
                    <Sparkles class="mt-0.5 size-5 text-primary" />
                    <div>
                        <p class="text-sm font-medium">
                            {{
                                hasExistingMenu
                                    ? 'Re-import your menu'
                                    : 'Import your menu'
                            }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Upload photos or a PDF of your latest menu and we'll
                            read the items, prices, and options.
                            <template v-if="hasExistingMenu">
                                Your current menu is replaced only after you
                                review and confirm the new one.
                            </template>
                        </p>
                    </div>
                </div>
                <Button
                    v-if="!expanded"
                    variant="outline"
                    data-test="menu-import-expand"
                    @click="expanded = true"
                >
                    Upload menu files
                </Button>
            </div>

            <div v-if="expanded" class="mt-3 space-y-3">
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf"
                    class="hidden"
                    data-test="menu-import-file-input"
                    @change="onFilesPicked"
                />

                <div
                    v-if="uploadForm.files.length"
                    class="flex flex-wrap gap-2"
                >
                    <button
                        v-for="(file, index) in uploadForm.files"
                        :key="`${file.name}-${index}`"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2 py-1 text-xs"
                        :aria-label="`Remove ${file.name}`"
                        @click="removeFile(index)"
                    >
                        <ImageIcon class="size-3 text-muted-foreground" />
                        {{ file.name }}
                        <X class="size-3 text-muted-foreground" />
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        @click="fileInput?.click()"
                    >
                        {{
                            uploadForm.files.length
                                ? 'Add more files'
                                : 'Choose files'
                        }}
                    </Button>
                    <Button
                        v-if="uploadForm.files.length"
                        type="button"
                        :disabled="uploadForm.processing"
                        data-test="start-menu-import-button"
                        @click="startImport"
                    >
                        <LoaderCircle
                            v-if="uploadForm.processing"
                            class="size-4 animate-spin"
                        />
                        Read my menu
                    </Button>
                </div>
                <p
                    v-if="uploadForm.progress"
                    class="text-xs text-muted-foreground"
                >
                    Uploading… {{ uploadForm.progress.percentage }}%
                </p>
                <p
                    v-if="uploadForm.errors.files"
                    class="text-sm text-destructive"
                >
                    {{ uploadForm.errors.files }}
                </p>
            </div>
        </template>
    </div>
</template>
