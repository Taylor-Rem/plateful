<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Check,
    ExternalLink,
    FileText,
    Image as ImageIcon,
    LoaderCircle,
    Sparkles,
    UtensilsCrossed,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

type MenuImportState = {
    id: number;
    status: 'queued' | 'processing' | 'needs_review' | 'failed';
    error: string | null;
    itemCount: number;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    menuPresets: { value: string; label: string }[];
    menuSummary: { categories: number; items: number };
    menuImport: MenuImportState | null;
    menuImportLimits: { maxFiles: number; maxFileKb: number };
}>();

const emit = defineEmits<{ advance: [] }>();

// ----- Path choice -----
const path = ref<'import' | 'preset' | null>(
    props.menuImport ? 'import' : null,
);

// ----- Import: file selection -----
const fileInput = ref<HTMLInputElement | null>(null);
const uploadForm = useForm<{ files: File[] }>({ files: [] });

const onFilesPicked = (event: Event): void => {
    const picked = Array.from((event.target as HTMLInputElement).files ?? []);
    uploadForm.files = [...uploadForm.files, ...picked].slice(
        0,
        props.menuImportLimits.maxFiles,
    );
    if (fileInput.value) fileInput.value.value = '';
};

const removeFile = (index: number): void => {
    uploadForm.files = uploadForm.files.filter((_, i) => i !== index);
};

const startImport = (): void => {
    uploadForm.post(`/${props.restaurant.subdomain}/onboarding/menu-import`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    });
};

// ----- Import: progress polling -----
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
                router.reload({ only: ['menuImport', 'steps', 'menuSummary'] });
            }, 3000);
        } else if (!active && poller) {
            clearInterval(poller);
            poller = null;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (poller) clearInterval(poller);
});

const discardImport = (): void => {
    if (!props.menuImport) return;
    router.post(
        `/${props.restaurant.subdomain}/menu-import/${props.menuImport.id}/discard`,
        {},
        { preserveScroll: true },
    );
};

// ----- Presets -----
const presetForm = useForm<{ preset: string }>({ preset: '' });
const applying = ref<string | null>(null);

const applyPreset = (preset: string): void => {
    applying.value = preset;
    presetForm.preset = preset;
    presetForm.post(`/${props.restaurant.subdomain}/onboarding/menu-preset`, {
        preserveScroll: true,
        onFinish: () => {
            applying.value = null;
        },
    });
};
</script>

<template>
    <div class="space-y-4">
        <!-- ============ Menu exists: complete state ============ -->
        <template v-if="menuSummary.items > 0">
            <div
                class="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950"
                data-test="menu-step-complete"
            >
                <span
                    class="mt-0.5 flex size-6 items-center justify-center rounded-full bg-green-100 text-green-700"
                >
                    <Check class="size-4" />
                </span>
                <div>
                    <p class="text-sm font-medium">
                        Your menu has {{ menuSummary.items }}
                        {{ menuSummary.items === 1 ? 'item' : 'items' }} in
                        {{ menuSummary.categories }}
                        {{ menuSummary.categories === 1 ? 'category' : 'categories' }}.
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Rename, re-price, or reorganize anytime in the menu
                        builder — it's your menu now.
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <a
                    :href="`/${restaurant.subdomain}/menu`"
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-80"
                >
                    Open the menu builder
                    <ExternalLink class="size-3.5" />
                </a>
                <Button type="button" data-test="menu-continue-button" @click="emit('advance')">
                    Continue
                </Button>
            </div>
        </template>

        <!-- ============ Import in flight ============ -->
        <template v-else-if="isExtracting">
            <div
                class="flex flex-col items-center gap-3 rounded-lg border border-border bg-background p-8 text-center"
                data-test="menu-import-progress"
            >
                <LoaderCircle class="size-8 animate-spin text-primary" />
                <p class="text-sm font-medium">Reading your menu…</p>
                <p class="max-w-sm text-sm text-muted-foreground">
                    We're pulling out every item, price, and description. This
                    usually takes about half a minute — feel free to work on
                    another step.
                </p>
            </div>
        </template>

        <!-- ============ Extraction ready for review ============ -->
        <template v-else-if="menuImport?.status === 'needs_review'">
            <div
                class="flex items-start gap-3 rounded-lg border border-primary/30 bg-primary/5 p-4"
                data-test="menu-import-ready"
            >
                <Sparkles class="mt-0.5 size-5 text-primary" />
                <div>
                    <p class="text-sm font-medium">
                        We read {{ menuImport.itemCount }} items from your menu.
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Give them a quick once-over — check the prices — and
                        your menu is done.
                    </p>
                </div>
            </div>
            <div class="flex items-center justify-between">
                <button
                    type="button"
                    class="text-sm text-muted-foreground underline hover:text-foreground"
                    @click="discardImport"
                >
                    Discard and start over
                </button>
                <Button as-child data-test="review-import-button">
                    <a :href="`/${restaurant.subdomain}/menu-import/${menuImport.id}/review`">
                        Review {{ menuImport.itemCount }} items
                    </a>
                </Button>
            </div>
        </template>

        <!-- ============ Choice / upload / failure ============ -->
        <template v-else>
            <div
                v-if="menuImport?.status === 'failed'"
                class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                data-test="menu-import-failed"
            >
                {{ menuImport.error ?? 'Something went wrong while reading your menu.' }}
            </div>

            <p class="text-sm text-muted-foreground">
                How do you want to build your menu?
            </p>

            <div class="grid gap-3 sm:grid-cols-3">
                <button
                    type="button"
                    :class="[
                        'flex flex-col items-start gap-2 rounded-lg border p-4 text-left transition',
                        path === 'import'
                            ? 'border-primary bg-primary/5'
                            : 'border-border bg-card hover:border-primary/50',
                    ]"
                    data-test="menu-path-import"
                    @click="path = 'import'"
                >
                    <Sparkles class="size-5 text-primary" />
                    <span class="text-sm font-semibold">Import my menu</span>
                    <span class="text-xs text-muted-foreground">
                        Snap photos or upload a PDF — we'll build it for you.
                    </span>
                    <span
                        class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
                    >
                        Recommended
                    </span>
                </button>

                <button
                    type="button"
                    :class="[
                        'flex flex-col items-start gap-2 rounded-lg border p-4 text-left transition',
                        path === 'preset'
                            ? 'border-primary bg-primary/5'
                            : 'border-border bg-card hover:border-primary/50',
                    ]"
                    data-test="menu-path-preset"
                    @click="path = 'preset'"
                >
                    <UtensilsCrossed class="size-5 text-muted-foreground" />
                    <span class="text-sm font-semibold">Start from a template</span>
                    <span class="text-xs text-muted-foreground">
                        A full sample menu for your cuisine, ready to edit.
                    </span>
                </button>

                <a
                    :href="`/${restaurant.subdomain}/menu`"
                    class="flex flex-col items-start gap-2 rounded-lg border border-border bg-card p-4 text-left transition hover:border-primary/50"
                    data-test="menu-path-scratch"
                >
                    <FileText class="size-5 text-muted-foreground" />
                    <span class="text-sm font-semibold">Build from scratch</span>
                    <span class="text-xs text-muted-foreground">
                        Add categories and items one by one in the builder.
                    </span>
                </a>
            </div>

            <!-- Import: upload panel -->
            <div v-if="path === 'import'" class="space-y-3 rounded-lg border border-border bg-background p-4">
                <p class="text-sm text-muted-foreground">
                    Upload up to {{ menuImportLimits.maxFiles }} menu photos or
                    a PDF. Clear, well-lit, straight-on photos work best.
                </p>

                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf"
                    class="hidden"
                    data-test="menu-import-file-input"
                    @change="onFilesPicked"
                />

                <div class="flex flex-wrap gap-2">
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
                        {{ uploadForm.files.length ? 'Add more files' : 'Choose files' }}
                    </Button>
                    <Button
                        v-if="uploadForm.files.length"
                        type="button"
                        :disabled="uploadForm.processing"
                        data-test="start-menu-import-button"
                        @click="startImport"
                    >
                        <LoaderCircle v-if="uploadForm.processing" class="size-4 animate-spin" />
                        Read my menu
                    </Button>
                </div>
                <p v-if="uploadForm.progress" class="text-xs text-muted-foreground">
                    Uploading… {{ uploadForm.progress.percentage }}%
                </p>
                <p v-if="uploadForm.errors.files" class="text-sm text-destructive">
                    {{ uploadForm.errors.files }}
                </p>
            </div>

            <!-- Presets panel -->
            <div v-if="path === 'preset'" class="space-y-3 rounded-lg border border-border bg-background p-4">
                <p class="text-sm text-muted-foreground">
                    Pick a cuisine — we'll build a full menu with categories
                    and prices you can edit item by item.
                </p>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <button
                        v-for="preset in menuPresets"
                        :key="preset.value"
                        type="button"
                        class="flex flex-col items-center gap-2 rounded-lg border border-border bg-card p-4 text-sm font-medium transition hover:border-primary hover:bg-primary/5 disabled:opacity-50"
                        :disabled="presetForm.processing"
                        :data-test="`menu-preset-${preset.value}`"
                        @click="applyPreset(preset.value)"
                    >
                        <UtensilsCrossed class="size-5 text-muted-foreground" />
                        {{ applying === preset.value ? 'Adding…' : preset.label }}
                    </button>
                </div>
                <p v-if="presetForm.errors.preset" class="text-sm text-destructive">
                    {{ presetForm.errors.preset }}
                </p>
            </div>
        </template>
    </div>
</template>
