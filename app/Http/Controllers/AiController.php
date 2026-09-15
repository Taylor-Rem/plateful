<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiController extends Controller
{
    /**
     * Published prices for the "text us" tier, in cents. Constants rather
     * than config on purpose: credits are not purchasable inside Plateful
     * yet, so nothing else in the app reads these numbers. They mirror the
     * relay's pricing plan (sms-relay/plans/05-credits.md) and ride to the
     * page as props so the copy can never disagree with them.
     */
    private const MESSAGE_FLOOR_CENTS = 50;

    private const GENERATED_PHOTO_CENTS = 100;

    /**
     * @var array<int, int>
     */
    private const CREDIT_PACKS_CENTS = [2500, 10000];

    /**
     * The /ai page — what Claude can do for a restaurant through Plateful's
     * operator API, on both tiers: bring your own assistant (MCP, free) and
     * text us (prepaid credits).
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Ai', [
            'authUserName' => $user?->name,
            'hasAdminAccess' => (bool) $user?->isAdmin(),
            'adminUrl' => $request->getScheme().'://admin.'.config('platform.primary_domain'),
            'canBookCall' => (bool) config('platform.booking_url'),
            'mcpUrl' => $request->getScheme().'://'.config('platform.primary_domain').'/mcp/platform',
            'messageFloorCents' => self::MESSAGE_FLOOR_CENTS,
            'generatedPhotoCents' => self::GENERATED_PHOTO_CENTS,
            'creditPacksCents' => self::CREDIT_PACKS_CENTS,
        ]);
    }
}
