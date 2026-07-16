<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
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

const PLATFORMS: Array<{ key: string; label: string; placeholder: string }> = [
    {
        key: 'instagram',
        label: 'Instagram',
        placeholder: 'https://instagram.com/your-handle',
    },
    {
        key: 'facebook',
        label: 'Facebook',
        placeholder: 'https://facebook.com/your-page',
    },
    {
        key: 'twitter',
        label: 'Twitter / X',
        placeholder: 'https://twitter.com/your-handle',
    },
    {
        key: 'tiktok',
        label: 'TikTok',
        placeholder: 'https://tiktok.com/@your-handle',
    },
    {
        key: 'youtube',
        label: 'YouTube',
        placeholder: 'https://youtube.com/@your-channel',
    },
    {
        key: 'website',
        label: 'Website',
        placeholder: 'https://your-other-website.com',
    },
];

const props = defineProps<{
    open: boolean;
    restaurant: App.Data.RestaurantData;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const buildInitial = () => {
    const out: Record<string, string> = {};
    const links = props.restaurant.socialLinks ?? {};

    for (const { key } of PLATFORMS) {
        out[key] = (links as Record<string, string>)[key] ?? '';
    }

    return { social_links: out };
};

const form = useForm(buildInitial());

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            Object.assign(form, buildInitial());
            form.clearErrors();
        }
    },
);

const close = (): void => emit('update:open', false);

const submit = (): void => {
    form.post('/admin/site/social', {
        preserveScroll: true,
        onSuccess: close,
    });
};

const errorFor = (key: string): string | undefined =>
    (form.errors as Record<string, string>)[`social_links.${key}`];
</script>

<template>
    <Sheet :open="open" @update:open="(v) => emit('update:open', v)">
        <SheetContent class="w-full max-w-xl overflow-y-auto sm:max-w-xl">
            <SheetHeader>
                <SheetTitle>Social links</SheetTitle>
            </SheetHeader>

            <form class="space-y-5 px-4 py-4" @submit.prevent="submit">
                <p class="text-sm text-muted-foreground">
                    Paste the full URL (starting with https://) for each
                    platform you use. Leave the rest blank.
                </p>

                <div
                    v-for="platform in PLATFORMS"
                    :key="platform.key"
                    class="grid gap-2"
                >
                    <Label :for="`social-${platform.key}`">{{
                        platform.label
                    }}</Label>
                    <Input
                        :id="`social-${platform.key}`"
                        v-model="form.social_links[platform.key]"
                        type="url"
                        :placeholder="platform.placeholder"
                        maxlength="255"
                    />
                    <InputError :message="errorFor(platform.key)" />
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
