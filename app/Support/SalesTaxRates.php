<?php

namespace App\Support;

use App\Services\OrderPlacement;

/**
 * Population-weighted average combined state + local sales tax rate, by state.
 *
 * This exists ONLY to prefill a suggestion while a restaurant is onboarding. No
 * order is ever priced from it: {@see OrderPlacement} always uses
 * the restaurant's own stored `tax_rate_percent`, which the owner confirms or
 * overrides. Treating these numbers as authoritative would be wrong — they are
 * a starting point that beats the 0.00 default, nothing more.
 *
 * They are averages across an entire state, so they are wrong for most specific
 * addresses in three separate ways: local rates vary by county and city, many
 * states tax prepared food on a different schedule than general sales (often
 * higher, via a meals tax), and the rates themselves change over time. The
 * onboarding UI says as much next to the field.
 *
 * Source: Tax Foundation, "State and Local Sales Tax Rates", as of 2026-07-01.
 */
final class SalesTaxRates
{
    /**
     * Combined state + average local rate, keyed by USPS state code.
     *
     * @var array<string, float>
     */
    private const COMBINED_BY_STATE = [
        'AL' => 9.46,
        'AK' => 1.82,
        'AZ' => 8.54,
        'AR' => 9.48,
        'CA' => 9.03,
        'CO' => 7.89,
        'CT' => 6.35,
        'DC' => 6.00,
        'DE' => 0.00,
        'FL' => 6.98,
        'GA' => 7.56,
        'HI' => 4.50,
        'IA' => 6.94,
        'ID' => 6.03,
        'IL' => 8.98,
        'IN' => 7.00,
        'KS' => 8.71,
        'KY' => 6.00,
        'LA' => 10.13,
        'MA' => 6.25,
        'MD' => 6.00,
        'ME' => 5.50,
        'MI' => 6.00,
        'MN' => 8.14,
        'MO' => 8.44,
        'MS' => 7.06,
        'MT' => 0.00,
        'NC' => 7.10,
        'ND' => 7.09,
        'NE' => 6.98,
        'NH' => 0.00,
        'NJ' => 6.60,
        'NM' => 7.68,
        'NV' => 8.24,
        'NY' => 8.54,
        'OH' => 7.29,
        'OK' => 9.06,
        'OR' => 0.00,
        'PA' => 6.34,
        'RI' => 7.00,
        'SC' => 7.49,
        'SD' => 6.11,
        'TN' => 9.61,
        'TX' => 8.20,
        'UT' => 7.42,
        'VA' => 5.77,
        'VT' => 6.43,
        'WA' => 9.57,
        'WI' => 5.72,
        'WV' => 6.60,
        'WY' => 5.39,
    ];

    /**
     * The suggested rate for a state, or null when the code isn't one we know.
     *
     * Null and 0.00 mean different things here: null is "we have no guess",
     * while 0.00 is a real answer for the five states with no sales tax at all.
     */
    public static function estimateFor(?string $state): ?float
    {
        $code = strtoupper(trim((string) $state));

        return self::COMBINED_BY_STATE[$code] ?? null;
    }

    /**
     * The whole table, for handing to the client so the onboarding form can
     * update its suggestion as the owner types their address.
     *
     * @return array<string, float>
     */
    public static function all(): array
    {
        return self::COMBINED_BY_STATE;
    }
}
