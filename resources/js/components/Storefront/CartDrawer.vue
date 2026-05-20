<script setup lang="ts">
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetFooter,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Minus, Plus, Trash2 } from 'lucide-vue-next';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type PageProps = {
    restaurant?: App.Data.RestaurantData;
    cart?: App.Data.CartData | null;
};

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const page = usePage<PageProps>();
const cart = computed(() => page.props.cart ?? null);
const restaurant = computed(() => page.props.restaurant);
const items = computed(() => cart.value?.items ?? []);
const hasUnavailable = computed(() =>
    items.value.some((i) => !i.isAvailable),
);
const isClosed = computed(() => restaurant.value?.isOpen === false);
const canCheckout = computed(
    () => items.value.length > 0 && !hasUnavailable.value && !isClosed.value,
);

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

const close = (): void => emit('update:open', false);

const updateQty = (item: App.Data.CartItemData, qty: number): void => {
    const next = Math.max(0, Math.min(50, qty));
    router.patch(
        `/cart/items/${item.id}`,
        { quantity: next },
        { preserveScroll: true, preserveState: true },
    );
};

const removeLine = (item: App.Data.CartItemData): void => {
    router.delete(`/cart/items/${item.id}`, {
        preserveScroll: true,
        preserveState: true,
    });
};

const clearCart = (): void => {
    if (!window.confirm('Remove all items from cart?')) return;
    router.delete('/cart', { preserveScroll: true, preserveState: true });
};
</script>

<template>
    <Sheet :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <SheetContent side="right" class="flex w-full flex-col p-0 sm:max-w-md">
            <SheetHeader class="border-b border-border px-5 py-4">
                <SheetTitle class="text-left">Your Cart</SheetTitle>
                <p
                    v-if="restaurant"
                    class="text-left text-sm text-muted-foreground"
                >
                    {{ restaurant.name }}
                </p>
            </SheetHeader>

            <div class="flex-1 overflow-y-auto px-5 py-4">
                <div
                    v-if="!cart || items.length === 0"
                    class="flex h-full flex-col items-center justify-center gap-3 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        Your cart is empty.
                    </p>
                    <Button type="button" variant="outline" @click="close">
                        Browse menu
                    </Button>
                </div>

                <ul v-else class="space-y-4">
                    <li
                        v-for="item in items"
                        :key="item.id"
                        class="flex gap-3 rounded-md border border-border bg-card p-3"
                    >
                        <div class="size-16 shrink-0 overflow-hidden rounded-md bg-muted">
                            <img
                                v-if="item.imageThumbUrl"
                                :src="item.imageThumbUrl"
                                :alt="item.menuItemName"
                                class="size-full object-cover"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-foreground">
                                        {{ item.menuItemName }}
                                    </p>
                                    <p
                                        v-if="item.selectionSummary"
                                        class="mt-0.5 line-clamp-2 text-xs text-muted-foreground"
                                    >
                                        {{ item.selectionSummary }}
                                    </p>
                                    <span
                                        v-if="!item.isAvailable"
                                        class="mt-1 inline-block rounded bg-destructive/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-destructive"
                                    >
                                        No longer available
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                    :aria-label="`Remove ${item.menuItemName}`"
                                    @click="removeLine(item)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>

                            <div class="mt-2 flex items-center justify-between">
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="grid size-7 place-items-center rounded border border-border text-foreground hover:bg-muted disabled:opacity-50"
                                        :disabled="item.quantity <= 1"
                                        :aria-label="`Decrease ${item.menuItemName}`"
                                        @click="updateQty(item, item.quantity - 1)"
                                    >
                                        <Minus class="size-3" />
                                    </button>
                                    <span class="min-w-6 text-center text-sm tabular-nums">
                                        {{ item.quantity }}
                                    </span>
                                    <button
                                        type="button"
                                        class="grid size-7 place-items-center rounded border border-border text-foreground hover:bg-muted disabled:opacity-50"
                                        :disabled="item.quantity >= 50"
                                        :aria-label="`Increase ${item.menuItemName}`"
                                        @click="updateQty(item, item.quantity + 1)"
                                    >
                                        <Plus class="size-3" />
                                    </button>
                                </div>
                                <div class="text-right text-sm">
                                    <div class="text-xs text-muted-foreground">
                                        {{ formatPrice(item.unitPriceCents) }} ea
                                    </div>
                                    <div class="font-semibold text-foreground">
                                        {{ formatPrice(item.lineTotalCents) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>

            <SheetFooter
                v-if="cart && items.length > 0"
                class="border-t border-border px-5 py-4"
            >
                <div class="w-full space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-muted-foreground">Subtotal</span>
                        <span class="font-semibold text-foreground">
                            {{ formatPrice(cart.subtotalCents) }}
                        </span>
                    </div>
                    <Link
                        v-if="canCheckout"
                        href="/checkout"
                        class="block w-full rounded-md px-4 py-2 text-center text-sm font-medium"
                        :style="{
                            backgroundColor: 'var(--brand-primary)',
                            color: 'var(--brand-primary-foreground)',
                        }"
                        @click="close"
                    >
                        Checkout
                    </Link>
                    <Button
                        v-else
                        type="button"
                        class="w-full"
                        :style="{
                            backgroundColor: 'var(--brand-primary)',
                            color: 'var(--brand-primary-foreground)',
                        }"
                        disabled
                    >
                        Checkout
                    </Button>
                    <p
                        v-if="hasUnavailable"
                        class="text-center text-xs text-destructive"
                    >
                        Remove unavailable items to continue.
                    </p>
                    <p
                        v-else-if="isClosed"
                        class="text-center text-xs text-amber-700"
                    >
                        We're closed — come back after
                        {{ restaurant?.nextOpenLabel?.replace(/^Opens\s*/, '') }} to check out.
                    </p>
                    <button
                        type="button"
                        class="block w-full text-center text-xs text-muted-foreground underline hover:text-foreground"
                        @click="clearCart"
                    >
                        Clear cart
                    </button>
                </div>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
