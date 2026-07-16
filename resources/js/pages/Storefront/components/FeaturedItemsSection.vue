<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, UtensilsCrossed } from 'lucide-vue-next';

defineProps<{
    items: App.Data.MenuItemData[];
}>();

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;
</script>

<template>
    <section class="mx-auto max-w-5xl px-6 py-12">
        <div v-if="items.length > 0">
            <div class="mb-6 flex items-end justify-between gap-4">
                <h2
                    class="inline-block border-b-2 pb-1 text-2xl font-semibold text-foreground"
                    :style="{ borderColor: 'var(--brand-primary)' }"
                >
                    Customer favorites
                </h2>
                <Link
                    href="/menu"
                    class="hidden items-center gap-1 text-sm font-medium hover:underline sm:inline-flex"
                    :style="{ color: 'var(--brand-primary)' }"
                >
                    See full menu <ArrowRight class="size-4" />
                </Link>
            </div>

            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="group overflow-hidden rounded-lg border border-border bg-card shadow-sm transition hover:shadow-md"
                >
                    <Link href="/menu" class="block">
                        <div
                            v-if="item.imageMediumUrl"
                            class="aspect-[4/3] w-full overflow-hidden bg-muted"
                        >
                            <img
                                :src="item.imageMediumUrl"
                                :alt="item.name"
                                class="size-full object-cover transition group-hover:scale-105"
                                loading="lazy"
                            />
                        </div>
                        <div class="flex items-start justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <h3
                                    class="truncate font-medium text-foreground"
                                >
                                    {{ item.name }}
                                </h3>
                                <p
                                    v-if="item.description"
                                    class="mt-1 line-clamp-2 text-sm text-muted-foreground"
                                >
                                    {{ item.description }}
                                </p>
                            </div>
                            <span
                                class="font-semibold whitespace-nowrap"
                                :style="{ color: 'var(--brand-primary)' }"
                            >
                                {{ formatPrice(item.priceCents) }}
                            </span>
                        </div>
                    </Link>
                </li>
            </ul>

            <div class="mt-6 text-center sm:hidden">
                <Link
                    href="/menu"
                    class="inline-flex items-center gap-1.5 text-sm font-medium hover:underline"
                    :style="{ color: 'var(--brand-primary)' }"
                >
                    See full menu <ArrowRight class="size-4" />
                </Link>
            </div>
        </div>

        <!-- Empty / fallback state: no featured items → just the menu CTA. -->
        <div v-else class="text-center">
            <Link
                href="/menu"
                class="inline-flex items-center justify-center gap-2 rounded-md px-6 py-3 text-base font-semibold shadow-sm transition hover:brightness-110 focus:ring-2 focus:ring-offset-2 focus:outline-none"
                :style="{
                    backgroundColor: 'var(--brand-primary)',
                    color: 'var(--brand-primary-foreground)',
                }"
            >
                <UtensilsCrossed class="size-5" />
                View the full menu
            </Link>
        </div>
    </section>
</template>
