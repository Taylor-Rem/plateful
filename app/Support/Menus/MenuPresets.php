<?php

namespace App\Support\Menus;

class MenuPresets
{
    /**
     * Cuisine that builds the configurable pizza ItemTemplate (sizes, crusts,
     * toppings) rather than a flat menu. Handled specially by MenuBuilder and
     * reused by the demo seeder. This is the only preset that exercises the
     * item-customization path.
     */
    public const TEMPLATED = 'italian';

    /**
     * Flat cuisine presets keyed by cuisine name.
     *
     * Shape: cuisine => [categoryName => [[name, priceCents, description|null, featured], ...]].
     *
     * Add a new cuisine by adding a key here — `MakeRestaurantCommand` and the
     * `--menu` option pick it up automatically.
     *
     * @var array<string, array<string, array<int, array{0: string, 1: int, 2: ?string, 3: bool}>>>
     */
    public const FLAT = [
        'mexican' => [
            'Tacos' => [
                ['Carne Asada Taco', 399, 'Grilled steak, onion, cilantro.', true],
                ['Al Pastor Taco', 399, 'Marinated pork, pineapple.', false],
                ['Carnitas Taco', 399, 'Slow-braised pork.', false],
                ['Baja Fish Taco', 499, 'Crispy fish, slaw, lime crema.', false],
            ],
            'Burritos' => [
                ['Carne Asada Burrito', 1099, 'Steak, rice, beans, salsa.', false],
                ['California Burrito', 1199, 'Steak, fries, cheese, guac.', false],
                ['Bean & Cheese Burrito', 799, 'Refried beans, melted cheese.', false],
            ],
            'Quesadillas' => [
                ['Cheese Quesadilla', 699, 'Flour tortilla, three-cheese blend.', false],
                ['Chicken Quesadilla', 999, 'Grilled chicken, peppers, cheese.', false],
            ],
            'Sides' => [
                ['Chips & Salsa', 399, 'House-made tortilla chips.', false],
                ['Guacamole', 599, 'Fresh-mashed avocado, lime.', false],
                ['Mexican Rice', 349, null, false],
            ],
            'Drinks' => [
                ['Horchata', 399, 'Cinnamon rice milk.', false],
                ['Jarritos', 299, 'Mexican fruit soda.', false],
                ['Agua Fresca', 349, null, false],
            ],
        ],
        'american' => [
            'Burgers' => [
                ['Classic Cheeseburger', 1099, 'Beef patty, American cheese, lettuce, tomato.', true],
                ['Bacon Burger', 1299, 'Beef patty, bacon, cheddar.', false],
                ['Double Smash', 1399, 'Two smashed patties, special sauce.', false],
            ],
            'Sandwiches' => [
                ['Crispy Chicken Sandwich', 1199, 'Fried chicken, pickles, mayo.', false],
                ['BLT', 999, 'Bacon, lettuce, tomato, toasted sourdough.', false],
            ],
            'Sides' => [
                ['French Fries', 499, null, false],
                ['Onion Rings', 599, 'Beer-battered, crispy.', false],
                ['Mac & Cheese', 699, 'Three-cheese, baked.', false],
            ],
            'Drinks' => [
                ['Fountain Soda', 299, null, false],
                ['Milkshake', 599, 'Vanilla, chocolate, or strawberry.', false],
                ['Iced Tea', 349, null, false],
            ],
            'Desserts' => [
                ['Apple Pie', 599, 'Warm, with a flaky crust.', false],
                ['Brownie Sundae', 699, 'Fudge brownie, vanilla ice cream.', false],
            ],
        ],
        'sushi' => [
            'Nigiri' => [
                ['Salmon Nigiri', 599, 'Two pieces.', true],
                ['Tuna Nigiri', 649, 'Two pieces.', false],
                ['Shrimp Nigiri', 549, 'Two pieces.', false],
            ],
            'Rolls' => [
                ['California Roll', 799, 'Crab, avocado, cucumber.', false],
                ['Spicy Tuna Roll', 999, 'Tuna, spicy mayo, scallion.', false],
                ['Dragon Roll', 1399, 'Eel, avocado, eel sauce.', false],
                ['Veggie Roll', 699, 'Avocado, cucumber, carrot.', false],
            ],
            'Appetizers' => [
                ['Edamame', 499, 'Steamed, sea salt.', false],
                ['Miso Soup', 399, null, false],
                ['Gyoza', 699, 'Pan-fried pork dumplings.', false],
            ],
            'Drinks' => [
                ['Green Tea', 299, null, false],
                ['Ramune', 399, 'Japanese marble soda.', false],
            ],
        ],
        'thai' => [
            'Curries' => [
                ['Green Curry', 1399, 'Coconut, bamboo, Thai basil.', true],
                ['Massaman Curry', 1499, 'Peanut, potato, tender beef.', false],
                ['Panang Curry', 1399, 'Rich red curry, kaffir lime.', false],
            ],
            'Noodles' => [
                ['Pad Thai', 1299, 'Rice noodles, tamarind, peanut.', false],
                ['Drunken Noodles', 1349, 'Wide noodles, Thai basil, chili.', false],
                ['Pad See Ew', 1299, 'Wide noodles, soy, broccoli.', false],
            ],
            'Appetizers' => [
                ['Spring Rolls', 699, 'Crispy vegetable rolls.', false],
                ['Chicken Satay', 899, 'Grilled skewers, peanut sauce.', false],
                ['Tom Yum Soup', 799, 'Hot-and-sour shrimp soup.', false],
            ],
            'Drinks' => [
                ['Thai Iced Tea', 399, 'Sweet, creamy.', false],
                ['Coconut Water', 349, null, false],
            ],
        ],
    ];

    /**
     * All selectable cuisine keys, including the templated one.
     *
     * @return array<int, string>
     */
    public static function cuisines(): array
    {
        return array_merge([self::TEMPLATED], array_keys(self::FLAT));
    }

    public static function has(string $cuisine): bool
    {
        return in_array($cuisine, self::cuisines(), true);
    }

    /**
     * Flat catalog for a cuisine, or an empty array if it is not a flat preset.
     *
     * @return array<string, array<int, array{0: string, 1: int, 2: ?string, 3: bool}>>
     */
    public static function flat(string $cuisine): array
    {
        return self::FLAT[$cuisine] ?? [];
    }
}
