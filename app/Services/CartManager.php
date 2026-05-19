<?php

namespace App\Services;

use App\Exceptions\InvalidCartSelectionException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ItemTemplate;
use App\Models\MenuItem;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartManager
{
    public const COOKIE_NAME = 'plateful_cart_token';

    public const COOKIE_DAYS = 30;

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
        if ($user instanceof User && $user->role?->value === 'customer') {
            $cart = Cart::query()
                ->where('restaurant_id', $tenantId)
                ->where('user_id', $user->id)
                ->latest('id')
                ->first();
            if ($cart) {
                return $cart;
            }
        }

        $token = $this->request->cookie(self::COOKIE_NAME);
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
            $userId = ($user instanceof User && $user->role?->value === 'customer') ? $user->id : null;

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
    public function addItem(MenuItem $item, int $quantity, array $optionIds): CartItem
    {
        if ($quantity < 1) {
            $quantity = 1;
        }
        if ($quantity > 50) {
            $quantity = 50;
        }

        $optionIds = collect($optionIds)
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        $template = null;
        if ($item->item_template_id) {
            $template = $item->relationLoaded('template') && $item->template
                ? $item->template
                : $item->template()->with('groups.options')->first();
        }

        $errors = [];

        if ($template) {
            $validIds = [];
            foreach ($template->groups as $group) {
                foreach ($group->options as $opt) {
                    $validIds[$opt->id] = $group;
                }
            }

            foreach ($optionIds as $oid) {
                if (! isset($validIds[$oid])) {
                    $errors['option_ids'][] = "Selection {$oid} is not valid for this item.";
                }
            }

            foreach ($template->groups as $group) {
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

        $unitPriceCents = $item->priceForSelectionsCents($optionIds);
        $signature = $this->signatureFor($item->id, $optionIds);
        $modifiers = $this->buildModifiersSnapshot($template, $optionIds);

        return DB::transaction(function () use ($item, $quantity, $unitPriceCents, $signature, $modifiers) {
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
            $line->save();

            return $line;
        });
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
     * @param  array<int, int>  $optionIds
     */
    public function signatureFor(int $menuItemId, array $optionIds): string
    {
        $sorted = collect($optionIds)
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return hash('sha256', $menuItemId.':'.implode(',', $sorted));
    }

    /**
     * @param  array<int, int>  $optionIds
     * @return array<string, mixed>|null
     */
    protected function buildModifiersSnapshot(?ItemTemplate $template, array $optionIds): ?array
    {
        if (! $template) {
            return null;
        }

        $selectedSet = collect($optionIds)->mapWithKeys(fn ($id) => [(int) $id => true]);

        $groups = [];
        foreach ($template->groups->sortBy('position')->values() as $group) {
            $selections = [];
            foreach ($group->options->sortBy('position')->values() as $opt) {
                if ($selectedSet->has((int) $opt->id)) {
                    $selections[] = [
                        'option_id' => (int) $opt->id,
                        'option_name' => (string) $opt->name,
                        'price_delta_cents' => (int) $opt->price_delta_cents,
                    ];
                }
            }
            if ($selections !== []) {
                $groups[] = [
                    'group_id' => (int) $group->id,
                    'group_name' => (string) $group->name,
                    'selections' => $selections,
                ];
            }
        }

        return [
            'template_id' => (int) $template->id,
            'template_name' => (string) $template->name,
            'groups' => $groups,
        ];
    }
}
