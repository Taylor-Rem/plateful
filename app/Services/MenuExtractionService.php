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
        - If an item lists multiple sizes or prices, use the LARGEST size's price for price_cents and record the full printed pricing in price_note, e.g. "Small $9 / Large $13 — imported the Large price".
        - If an item has no readable price, set price_cents to 0, set price_note to "No price found — needs a price", and add a warning.
        - description is the item's printed description, or null if there is none. Do not write your own descriptions.
        - Group items under the menu's printed section headings. If a menu has no headings, use a single category named "Menu".
        - Ignore everything that is not a menu item: hours, addresses, phone numbers, slogans, allergy disclaimers.
        - Use warnings for anything a restaurant owner should double-check: unreadable sections, guessed prices, cut-off pages.
        PROMPT;

    /**
     * @param  array<int, array{media_type: string, data: string}>  $files  raw binary + mime, images or PDFs
     * @return array{categories: array<int, mixed>, warnings: array<int, string>, model: string, input_tokens: int, output_tokens: int}
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
                                    ],
                                    'required' => ['name', 'description', 'price_cents', 'price_note'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['name', 'items'],
                        'additionalProperties' => false,
                    ],
                ],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['categories', 'warnings'],
            'additionalProperties' => false,
        ];
    }
}
