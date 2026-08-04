<?php

namespace App\Services\Delivery;

/**
 * The technical bar of DoorDash's restricted-items requirement: integrations
 * must include safeguards against dispatching restricted items (alcohol,
 * tobacco, cannabis, weapons, explosives) through the courier network.
 *
 * Plateful delivery is food-only — the contractual bar is the restricted-items
 * attestation an owner accepts when enabling delivery. This guard is the
 * demonstrable technical bar behind it: a keyword screen over item names that
 * refuses to quote or dispatch a delivery containing a restricted item. It is
 * deliberately crude — a blocklist cannot understand a menu — so it errs
 * toward the small curated term list below and carves out common food usages
 * ("root beer", "beer-battered") rather than trying to be clever.
 */
class RestrictedItemsGuard
{
    /**
     * Dispatch failure reasons starting with this prefix are permanent —
     * retrying will not un-restrict the items.
     */
    public const FAILURE_PREFIX = 'restricted_items';

    /**
     * Food usages of otherwise-restricted words. Stripped from the name before
     * screening, so "beer-battered cod" passes while "cold beer" does not.
     */
    private const ALLOWED_PHRASES = [
        'root beer',
        'beer battered',
        'beer-battered',
        'beer cheese',
        'beer bread',
        'wine vinegar',
        'wine sauce',
        'wine reduction',
        'red wine reduction',
        'bourbon glaze',
        'bourbon glazed',
        'bourbon sauce',
        'rum cake',
        'rum raisin',
        'shrimp cocktail',
        'fruit cocktail',
        'sake glaze',
    ];

    /**
     * Matched on word boundaries against lowercased item names.
     */
    private const RESTRICTED_TERMS = [
        // Alcohol — needs a signed DoorDash addendum Plateful does not have.
        'alcohol', 'beer', 'wine', 'vodka', 'whiskey', 'whisky', 'tequila',
        'rum', 'bourbon', 'brandy', 'mezcal', 'soju', 'sake', 'liquor',
        'lager', 'ipa', 'cocktail', 'margarita', 'mojito', 'sangria',
        'mimosa', 'hard seltzer', 'hard cider',
        // Tobacco / nicotine.
        'tobacco', 'cigarette', 'cigarettes', 'cigar', 'cigars', 'vape',
        'vaping', 'nicotine', 'hookah', 'shisha',
        // Cannabis.
        'cannabis', 'marijuana', 'thc', 'cbd', 'kratom',
        // Weapons and explosives.
        'weapon', 'firearm', 'handgun', 'shotgun', 'rifle', 'pistol',
        'ammunition', 'ammo', 'taser', 'explosive', 'explosives',
        'firework', 'fireworks',
    ];

    /**
     * The restricted items found, with the term each one tripped on.
     *
     * @param  iterable<int, string>  $itemNames
     * @return array<int, array{item: string, term: string}>
     */
    public function violations(iterable $itemNames): array
    {
        $violations = [];

        foreach ($itemNames as $name) {
            $term = $this->matchedTerm((string) $name);

            if ($term !== null) {
                $violations[] = ['item' => (string) $name, 'term' => $term];
            }
        }

        return $violations;
    }

    /**
     * A dispatch failure reason naming the offending items, prefixed so the
     * dispatch job can recognise it as permanent.
     *
     * @param  array<int, array{item: string, term: string}>  $violations
     */
    public function failureReason(array $violations): string
    {
        $items = implode(', ', array_map(
            static fn (array $violation): string => "{$violation['item']} ({$violation['term']})",
            $violations,
        ));

        return self::FAILURE_PREFIX.': '.$items;
    }

    private function matchedTerm(string $name): ?string
    {
        $haystack = str_replace(self::ALLOWED_PHRASES, ' ', mb_strtolower($name));

        foreach (self::RESTRICTED_TERMS as $term) {
            if (preg_match('/\b'.preg_quote($term, '/').'\b/u', $haystack) === 1) {
                return $term;
            }
        }

        return null;
    }
}
