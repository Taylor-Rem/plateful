<?php

namespace App\Services;

use App\Exceptions\InvalidCartSelectionException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ItemTemplateGroup;
use App\Models\MenuItem;
use App\Models\User;
use App\Support\Menus\ModifierSummary;
use App\Tenancy\CurrentTenant;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartManager
{
    public const COOKIE_NAME = 'plateful_cart_token';

    public const COOKIE_DAYS = 30;

    /**
     * The mobile app has no cookie jar: it stores the cart token it is handed
     * and sends it back here. Header wins over cookie when both are present.
     */
    public const HEADER_NAME = 'X-Cart-Token';

    public function __construct(
        protected CurrentTenant $tenant,
        protected CookieJar $cookies,
        protected Request $request,
    ) {}

    public function current(): ?Cart
    {
        $tenantId = $this->tenant->id();
        if (! $tenantId) {
            return null;
        }

        $user = $this->request->user();
        if ($user instanceof User) {
            $cart = Cart::query()
                ->where('restaurant_id', $tenantId)
                ->where('user_id', $user->id)
                ->latest('id')
                ->first();
            if ($cart) {
                return $cart;
            }
        }

        $token = $this->tokenFromRequest();
        if (! $token) {
            return null;
        }

        $cart = Cart::query()
            ->where('token', $token)
            ->first();

        if (! $cart || $cart->restaurant_id !== $tenantId) {
            return null;
        }

        return $cart;
    }

    public function currentOrCreate(): Cart
    {
        $cart = $this->current();

        if ($cart === null) {
            $tenantId = $this->tenant->id();
            $user = $this->request->user();
            $userId = ($user instanceof User) ? $user->id : null;

            $cart = new Cart;
            $cart->restaurant_id = $tenantId;
            $cart->user_id = $userId;
            $cart->token = (string) Str::uuid();
            $cart->expires_at = now()->addDays(self::COOKIE_DAYS);
            $cart->save();

            $this->queueCookie($cart->token);
        } else {
            $cart->expires_at = now()->addDays(self::COOKIE_DAYS);
            $cart->save();
            if ($cart->token) {
                $this->queueCookie($cart->token);
            }
        }

        return $cart;
    }

    /**
     * The guest cart token the request carries, header first, cookie second.
     */
    public function tokenFromRequest(): ?string
    {
        $header = $this->request->header(self::HEADER_NAME);
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $cookie = $this->request->cookie(self::COOKIE_NAME);

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    /**
     * Attach the request's guest cart (by header token) to a user who just
     * signed in through the API — the header-token twin of
     * MergeGuestCartOnLogin, which only knows the cookie.
     */
    public function mergeHeaderCartIntoUser(User $user): void
    {
        $header = $this->request->header(self::HEADER_NAME);
        if (! is_string($header) || $header === '') {
            return;
        }

        $guestCart = Cart::query()->where('token', $header)->first();

        if ($guestCart !== null && $guestCart->user_id === null) {
            $this->mergeGuestCartIntoUser($guestCart, $user);
        }
    }

    protected function queueCookie(string $token): void
    {
        $this->cookies->queue($this->cookies->make(
            name: self::COOKIE_NAME,
            value: $token,
            minutes: 60 * 24 * self::COOKIE_DAYS,
            path: '/',
            domain: null,
            secure: app()->environment('production'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }

    /**
     * @param  array<int, int>  $optionIds
     */
    public function addItem(MenuItem $item, int $quantity, array $optionIds, ?string $notes = null): CartItem
    {
        $quantity = $this->clampQuantity($quantity);
        $optionIds = $this->normalizeOptionIds($optionIds);
        $groups = $this->validatedGroupsFor($item, $optionIds);

        $unitPriceCents = $item->priceForSelectionsCents($optionIds);
        $signature = $this->signatureFor($item->id, $optionIds, $notes);
        $modifiers = $this->buildModifiersSnapshot($item, $groups, $optionIds);

        return DB::transaction(function () use ($item, $quantity, $unitPriceCents, $signature, $modifiers, $notes) {
            $cart = $this->currentOrCreate();

            $existing = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('menu_item_id', $item->id)
                ->where('selection_signature', $signature)
                ->first();

            if ($existing) {
                $existing->quantity = min(50, $existing->quantity + $quantity);
                $existing->save();

                return $existing;
            }

            $line = new CartItem;
            $line->cart_id = $cart->id;
            $line->menu_item_id = $item->id;
            $line->quantity = $quantity;
            $line->unit_price_cents = $unitPriceCents;
            $line->modifiers = $modifiers;
            $line->selection_signature = $signature;
            $line->notes = $notes;
            $line->save();

            return $line;
        });
    }

    /**
     * Re-configure an existing line in place: new selections, notes, and
     * quantity, re-priced from the current menu. If the new configuration
     * matches another line already in the cart, the two merge (quantities
     * added, capped) and the edited line is removed — the same stacking rule
     * addItem applies. Returns the line that survived.
     *
     * @param  array<int, int>  $optionIds
     */
    public function replaceItem(CartItem $line, int $quantity, array $optionIds, ?string $notes = null): CartItem
    {
        $item = $line->menuItem;

        if (! $item instanceof MenuItem) {
            throw InvalidCartSelectionException::withErrors([
                'menu_item' => ['This item is no longer on the menu.'],
            ]);
        }

        $quantity = $this->clampQuantity($quantity);
        $optionIds = $this->normalizeOptionIds($optionIds);
        $groups = $this->validatedGroupsFor($item, $optionIds);

        $unitPriceCents = $item->priceForSelectionsCents($optionIds);
        $signature = $this->signatureFor($item->id, $optionIds, $notes);
        $modifiers = $this->buildModifiersSnapshot($item, $groups, $optionIds);

        return DB::transaction(function () use ($line, $item, $quantity, $unitPriceCents, $signature, $modifiers, $notes) {
            $twin = CartItem::query()
                ->where('cart_id', $line->cart_id)
                ->where('menu_item_id', $item->id)
                ->where('selection_signature', $signature)
                ->whereKeyNot($line->id)
                ->first();

            if ($twin) {
                $twin->quantity = min(50, $twin->quantity + $quantity);
                $twin->save();
                $line->delete();

                return $twin;
            }

            $line->quantity = $quantity;
            $line->unit_price_cents = $unitPriceCents;
            $line->modifiers = $modifiers;
            $line->selection_signature = $signature;
            $line->notes = $notes;
            $line->save();

            return $line;
        });
    }

    protected function clampQuantity(int $quantity): int
    {
        return max(1, min(50, $quantity));
    }

    /**
     * @param  array<int, mixed>  $optionIds
     * @return array<int, int>
     */
    protected function normalizeOptionIds(array $optionIds): array
    {
        return collect($optionIds)
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Loads every option group on the item (attached templates + compiled
     * ingredient groups) and checks the selections against them: every id
     * must belong to the item and each group's min/max must be honored.
     * Throws a 422-shaped exception otherwise.
     *
     * @param  array<int, int>  $optionIds
     * @return Collection<int, ItemTemplateGroup>
     */
    protected function validatedGroupsFor(MenuItem $item, array $optionIds): Collection
    {
        $groups = $item->optionGroups();

        $errors = [];

        if ($groups->isNotEmpty()) {
            $validIds = [];
            foreach ($groups as $group) {
                foreach ($group->options as $opt) {
                    $validIds[$opt->id] = $group;
                }
            }

            foreach ($optionIds as $oid) {
                if (! isset($validIds[$oid])) {
                    $errors['option_ids'][] = "Selection {$oid} is not valid for this item.";
                }
            }

            foreach ($groups as $group) {
                $countInGroup = collect($optionIds)->filter(
                    fn ($oid) => isset($validIds[$oid]) && $validIds[$oid]->id === $group->id
                )->count();

                $min = (int) ($group->min_selections ?? 0);
                $max = $group->max_selections === null ? null : (int) $group->max_selections;

                if ($countInGroup < $min) {
                    $errors['option_ids'][] = "{$group->name}: pick at least {$min}.";
                }
                if ($max !== null && $countInGroup > $max) {
                    $errors['option_ids'][] = "{$group->name}: pick at most {$max}.";
                }
            }
        } else {
            if ($optionIds !== []) {
                $errors['option_ids'][] = 'This item does not accept selections.';
            }
        }

        if ($errors !== []) {
            throw InvalidCartSelectionException::withErrors($errors);
        }

        return $groups;
    }

    public function updateQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->removeItem($item);

            return;
        }

        $item->quantity = min(50, $quantity);
        $item->save();
    }

    public function removeItem(CartItem $item): void
    {
        $item->delete();
    }

    public function clear(): void
    {
        $cart = $this->current();
        if ($cart) {
            $cart->items()->delete();
        }
    }

    public function mergeGuestCartIntoUser(Cart $guestCart, User $user): void
    {
        if ($guestCart->user_id !== null) {
            return;
        }

        $userCart = Cart::query()
            ->where('restaurant_id', $guestCart->restaurant_id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if (! $userCart) {
            $guestCart->user_id = $user->id;
            $guestCart->save();

            return;
        }

        DB::transaction(function () use ($guestCart, $userCart) {
            foreach ($guestCart->items as $guestItem) {
                $match = CartItem::query()
                    ->where('cart_id', $userCart->id)
                    ->where('menu_item_id', $guestItem->menu_item_id)
                    ->where('selection_signature', $guestItem->selection_signature)
                    ->first();

                if ($match) {
                    $match->quantity = min(50, $match->quantity + $guestItem->quantity);
                    $match->save();
                    $guestItem->delete();
                } else {
                    $guestItem->cart_id = $userCart->id;
                    $guestItem->save();
                }
            }

            $guestCart->delete();
        });
    }

    /**
     * Lines only merge when the whole configuration matches, notes included —
     * two "no onions" adds stack, but never onto a plain line. Notes-less
     * signatures keep the historical shape so existing cart lines still match.
     *
     * @param  array<int, int>  $optionIds
     */
    public function signatureFor(int $menuItemId, array $optionIds, ?string $notes = null): string
    {
        $sorted = collect($optionIds)
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $base = $menuItemId.':'.implode(',', $sorted);
        if ($notes !== null && $notes !== '') {
            $base .= ':'.$notes;
        }

        return hash('sha256', $base);
    }

    /**
     * Snapshot v2. Every group the item has, with what was selected and —
     * for defaults the customer turned off — what was removed. `is_default`
     * on a selection and `removed` per group are what let tickets show
     * deviations only ({@see ModifierSummary}).
     *
     * @param  Collection<int, ItemTemplateGroup>  $groups
     * @param  array<int, int>  $optionIds
     * @return array<string, mixed>|null
     */
    protected function buildModifiersSnapshot(MenuItem $item, Collection $groups, array $optionIds): ?array
    {
        if ($groups->isEmpty()) {
            return null;
        }

        $selectedSet = collect($optionIds)->mapWithKeys(fn ($id) => [(int) $id => true]);

        $defaultIds = ($item->relationLoaded('defaultSelections')
            ? $item->defaultSelections->pluck('id')
            : $item->defaultSelections()->pluck('item_template_options.id'))
            ->map(fn ($id) => (int) $id);
        $defaultSet = $defaultIds->mapWithKeys(fn ($id) => [$id => true]);

        $snapshotGroups = [];
        foreach ($groups as $group) {
            $selections = [];
            $removed = [];
            foreach ($group->options->sortBy('position')->values() as $opt) {
                $isDefault = $defaultSet->has((int) $opt->id);

                if ($selectedSet->has((int) $opt->id)) {
                    $selections[] = [
                        'option_id' => (int) $opt->id,
                        'option_name' => (string) $opt->name,
                        'price_delta_cents' => (int) $opt->price_delta_cents,
                        'is_default' => $isDefault,
                    ];
                } elseif ($isDefault) {
                    $removed[] = [
                        'option_id' => (int) $opt->id,
                        'option_name' => (string) $opt->name,
                    ];
                }
            }
            if ($selections !== [] || $removed !== []) {
                $snapshotGroups[] = [
                    'group_id' => (int) $group->id,
                    'group_name' => (string) $group->name,
                    'kind' => (string) ($group->kind ?? ItemTemplateGroup::KIND_CHOICE),
                    'single_select' => $group->isSingleSelect(),
                    'selections' => $selections,
                    'removed' => $removed,
                ];
            }
        }

        return [
            'version' => 2,
            'groups' => $snapshotGroups,
        ];
    }
}
