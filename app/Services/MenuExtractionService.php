<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;
use RuntimeException;

/**
 * Turns menu photos / PDFs into structured menu data with a single
 * schema-constrained Claude call. This class is the only place the app talks
 * to the Anthropic API; tests swap it out at the container level.
 */
class MenuExtractionService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You extract restaurant menus from photos and PDF scans into structured data for an online ordering system.

        Rules:
        - Extract every menu item you can actually read. Never invent items, prices, or descriptions that are not visible in the files.
        - Prices are integer US cents: "$12.99" becomes 1299, "12" becomes 1200.
        - If an item has no readable price, set price_cents to 0, set price_note to "No price found — needs a price", and add a warning.
        - description is the item's printed description, or null if there is none. Do not write your own descriptions.
        - Group items under the menu's printed section headings. If a menu has no headings, use a single category named "Menu".
        - Ignore everything that is not a menu item: hours, addresses, phone numbers, slogans, allergy disclaimers.
        - Use warnings for anything a restaurant owner should double-check: unreadable sections, guessed prices, cut-off pages.

        Options and customizations:
        - Menus often print choices and paid add-ons: sizes, milk alternatives, syrups, "choice of bacon or avocado", "GF bread +2", "add salsa .50". Capture these as option_sets so customers can customize items when ordering.
        - Only capture options the menu actually prints. Never invent customizations (milk amounts, sugar levels, cooking preferences) the menu does not mention.
        - An option_set is reusable. When the same printed choices apply to several items (for example milk alternatives and syrups printed once under a coffee section), define ONE option_set and reference it by name from every item it applies to via the item's option_set field. Items with no printed options use null.
        - Each option_set contains groups. A group is one decision. Set min_selections and max_selections from the printed rule: "choice of X or Y" means min 1 and max 1; optional add-ons mean min 0; max_selections null means no limit.
        - Each option's price_delta_cents is the printed surcharge in cents on top of the item's base price: 0 when included in the price, positive for paid upgrades and add-ons.
        - Mark is_default true for options included in the item's printed price (the standard milk, the base size, the default side). A group with min 1 and max 1 must have exactly one default option.
        - If an item lists multiple sizes or prices, set price_cents to the SMALLEST printed price and add a size group (min 1, max 1) whose default is that size, with price_delta_cents on larger sizes covering the difference. Record the full printed pricing in price_note.
        - Keep option_set names short and reusable, e.g. "Espresso drink options", "Sandwich options".
        PROMPT;

    /**
     * @param  array<int, array{media_type: string, data: string}>  $files  raw binary + mime, images or PDFs
     * @return array{categories: array<int, mixed>, option_sets: array<int, mixed>, warnings: array<int, string>, model: string, input_tokens: int, output_tokens: int}
     */
    public function extract(array $files): array
    {
        $client = new Client(apiKey: (string) config('services.anthropic.api_key'));

        $content = [];
        foreach ($files as $file) {
            $content[] = $file['media_type'] === 'application/pdf'
                ? [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => base64_encode($file['data']),
                    ],
                ]
                : [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $file['media_type'],
                        'data' => base64_encode($file['data']),
                    ],
                ];
        }
        $content[] = [
            'type' => 'text',
            'text' => 'Extract the complete menu from the attached files.',
        ];

        $message = $client->messages->create(
            model: (string) config('menu_import.model'),
            maxTokens: (int) config('menu_import.max_output_tokens'),
            system: self::SYSTEM_PROMPT,
            messages: [['role' => 'user', 'content' => $content]],
            outputConfig: OutputConfig::with(format: JSONOutputFormat::with(schema: self::schema())),
        );

        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('The menu is too large to extract in one pass.');
        }
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The extraction was declined — the files may not be a menu.');
        }

        $text = collect($message->content)
            ->firstWhere('type', 'text')
            ?->text;

        $decoded = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($decoded) || ! isset($decoded['categories'])) {
            throw new RuntimeException('The extraction returned no usable menu data.');
        }

        return [
            'categories' => $decoded['categories'],
            'option_sets' => is_array($decoded['option_sets'] ?? null) ? $decoded['option_sets'] : [],
            'warnings' => array_values(array_filter($decoded['warnings'] ?? [], 'is_string')),
            'model' => $message->model,
            'input_tokens' => (int) $message->usage->inputTokens,
            'output_tokens' => (int) $message->usage->outputTokens,
        ];
    }

    /**
     * Structured-outputs schema: the API guarantees the response validates
     * against this, so parsing failures are limited to transport problems.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'categories' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Menu section heading as printed.'],
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'description' => ['type' => ['string', 'null']],
                                        'price_cents' => ['type' => 'integer', 'description' => 'Price in US cents. 0 when unreadable.'],
                                        'price_note' => ['type' => ['string', 'null'], 'description' => 'Only for multi-size pricing or price problems.'],
                                        'option_set' => ['type' => ['string', 'null'], 'description' => 'Name of the option_set that applies to this item, or null.'],
                                    ],
                                    'required' => ['name', 'description', 'price_cents', 'price_note', 'option_set'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['name', 'items'],
                        'additionalProperties' => false,
                    ],
                ],
                'option_sets' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Short reusable name items reference via option_set.'],
                            'groups' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string', 'description' => 'One decision, e.g. "Size", "Milk", "Add-ons".'],
                                        'min_selections' => ['type' => 'integer', 'description' => '0 when optional.'],
                                        'max_selections' => ['type' => ['integer', 'null'], 'description' => 'null when unlimited.'],
                                        'options' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'name' => ['type' => 'string'],
                                                    'price_delta_cents' => ['type' => 'integer', 'description' => 'Printed surcharge in US cents; 0 when included.'],
                                                    'is_default' => ['type' => 'boolean', 'description' => 'true when included in the printed base price.'],
                                                ],
                                                'required' => ['name', 'price_delta_cents', 'is_default'],
                                                'additionalProperties' => false,
                                            ],
                                        ],
                                    ],
                                    'required' => ['name', 'min_selections', 'max_selections', 'options'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['name', 'groups'],
                        'additionalProperties' => false,
                    ],
                ],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['categories', 'option_sets', 'warnings'],
            'additionalProperties' => false,
        ];
    }
}
