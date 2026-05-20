<?php

use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Services\CartManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;

if (! function_exists('cartCookieFrom')) {
    /**
     * Returns the plain (decrypted) cart token from the response's Set-Cookie header,
     * suitable for passing back into a subsequent test request via withCookie().
     */
    function cartCookieFrom(TestResponse $response): ?string
    {
        $cookies = $response->headers->getCookies();
        foreach ($cookies as $c) {
            if ($c->getName() === CartManager::COOKIE_NAME) {
                $value = $c->getValue();
                try {
                    $decrypted = decrypt($value, false);
                } catch (Throwable $e) {
                    return $value;
                }

                return CookieValuePrefix::remove($decrypted);
            }
        }

        return null;
    }
}

if (! function_exists('cartFixture')) {
    /**
     * Build a Pizza-style fixture for a tenant with a Pepperoni Pizza item
     * with template (Size required 1-1; Toppings 0-2).
     *
     * @return array{restaurant: Restaurant, item: MenuItem, simple: MenuItem, size_small: ItemTemplateOption, size_medium: ItemTemplateOption, top_pepperoni: ItemTemplateOption, top_bacon: ItemTemplateOption, other_template_option: ItemTemplateOption}
     */
    function cartFixture(string $sub = 'marcos'): array
    {
        $r = Restaurant::create([
            'name' => "Marco's", 'subdomain' => $sub, 'email' => "$sub@m.test",
            'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
        ]);

        app(CurrentTenant::class)->set($r);

        $cat = MenuCategory::create([
            'restaurant_id' => $r->id, 'name' => 'P', 'slug' => 'p', 'position' => 0, 'is_active' => true,
        ]);

        $tpl = ItemTemplate::create([
            'restaurant_id' => $r->id, 'name' => 'Pizza', 'is_active' => true, 'position' => 0,
        ]);
        $sz = ItemTemplateGroup::create(['item_template_id' => $tpl->id, 'name' => 'Size', 'min_selections' => 1, 'max_selections' => 1, 'position' => 0]);
        $small = ItemTemplateOption::create(['item_template_group_id' => $sz->id, 'name' => 'Small', 'price_delta_cents' => -200, 'is_available' => true, 'position' => 0]);
        $medium = ItemTemplateOption::create(['item_template_group_id' => $sz->id, 'name' => 'Medium', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 1]);

        $tp = ItemTemplateGroup::create(['item_template_id' => $tpl->id, 'name' => 'Toppings', 'min_selections' => 0, 'max_selections' => 2, 'position' => 1]);
        $pepperoni = ItemTemplateOption::create(['item_template_group_id' => $tp->id, 'name' => 'Pepperoni', 'price_delta_cents' => 200, 'is_available' => true, 'position' => 0]);
        $bacon = ItemTemplateOption::create(['item_template_group_id' => $tp->id, 'name' => 'Bacon', 'price_delta_cents' => 300, 'is_available' => true, 'position' => 1]);

        $item = MenuItem::create([
            'restaurant_id' => $r->id, 'menu_category_id' => $cat->id, 'item_template_id' => $tpl->id,
            'name' => 'Pep', 'slug' => 'pep', 'price_cents' => 1400, 'is_available' => true, 'position' => 0,
        ]);
        $item->defaultSelections()->sync([$medium->id, $pepperoni->id]);

        $simple = MenuItem::create([
            'restaurant_id' => $r->id, 'menu_category_id' => $cat->id, 'item_template_id' => null,
            'name' => 'Soda', 'slug' => 'soda', 'price_cents' => 299, 'is_available' => true, 'position' => 1,
        ]);

        $otherTpl = ItemTemplate::create([
            'restaurant_id' => $r->id, 'name' => 'Salad', 'is_active' => true, 'position' => 1,
        ]);
        $otherGroup = ItemTemplateGroup::create(['item_template_id' => $otherTpl->id, 'name' => 'Dressing', 'min_selections' => 1, 'max_selections' => 1, 'position' => 0]);
        $other = ItemTemplateOption::create(['item_template_group_id' => $otherGroup->id, 'name' => 'Ranch', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 0]);

        app(CurrentTenant::class)->clear();

        return [
            'restaurant' => $r,
            'item' => $item,
            'simple' => $simple,
            'size_small' => $small,
            'size_medium' => $medium,
            'top_pepperoni' => $pepperoni,
            'top_bacon' => $bacon,
            'other_template_option' => $other,
        ];
    }
}
