import { inject, provide } from 'vue';
import type { InjectionKey, Ref } from 'vue';

export type CartLineEditor = (line: App.Data.CartItemData) => void;

/**
 * The storefront layout owns the cart drawer. It provides this handle so
 * pages can open the drawer (e.g. from a "View cart" toast action) and so
 * the menu page can offer "Edit" on a cart line by registering an editor —
 * only the menu page has the item data the configurator needs, so the
 * drawer shows Edit only while an editor is registered.
 */
export type StorefrontCart = {
    open: () => void;
    close: () => void;
    lineEditor: Ref<CartLineEditor | null>;
};

const key: InjectionKey<StorefrontCart> = Symbol('storefrontCart');

export function provideStorefrontCart(cart: StorefrontCart): void {
    provide(key, cart);
}

export function useStorefrontCart(): StorefrontCart | null {
    return inject(key, null);
}
