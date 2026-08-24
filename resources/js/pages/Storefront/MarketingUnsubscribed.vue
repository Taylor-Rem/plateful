<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { MailX } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    email: string;
    resubscribeUrl: string;
}>();

const resubscribing = ref<boolean>(false);

const undo = (): void => {
    resubscribing.value = true;
    router.post(
        props.resubscribeUrl,
        {},
        {
            onFinish: () => {
                resubscribing.value = false;
            },
        },
    );
};
</script>

<template>
    <div>
        <Head title="Unsubscribed" />

        <main class="mx-auto max-w-xl px-4 py-16 text-center sm:px-6">
            <MailX class="mx-auto mb-4 size-10 text-muted-foreground" />
            <h1
                class="mb-2 text-2xl font-bold tracking-tight"
                :style="{ color: 'var(--brand-primary)' }"
            >
                You're unsubscribed
            </h1>
            <p class="text-sm text-muted-foreground">
                <span class="font-medium text-foreground">{{ email }}</span>
                won't receive marketing emails from
                {{ restaurant.name }} anymore. Order confirmations and receipts
                are unaffected.
            </p>
            <p class="mt-6 text-sm text-muted-foreground">Changed your mind?</p>
            <button
                type="button"
                class="mt-2 rounded-md px-4 py-2 text-sm font-semibold disabled:opacity-60"
                :style="{
                    backgroundColor: 'var(--brand-primary)',
                    color: 'var(--brand-primary-foreground)',
                }"
                :disabled="resubscribing"
                @click="undo"
            >
                {{ resubscribing ? 'Resubscribing…' : 'Resubscribe' }}
            </button>
        </main>
    </div>
</template>
