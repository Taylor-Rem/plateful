<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Enums\RestaurantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnboardingBasicsRequest;
use App\Models\MenuImport;
use App\Models\Restaurant;
use App\Services\RestaurantImageService;
use App\Support\Menus\MenuBuilder;
use App\Support\Menus\MenuPresets;
use App\Support\Onboarding\OnboardingSteps;
use App\Support\SalesTaxRates;
use App\Support\StorefrontLoginHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class OnboardingController extends Controller
{
    public function __construct(private OnboardingSteps $steps) {}

    /**
     * Render the setup wizard for an approved restaurant. Each step's status
     * is computed from existing data so the UI always reflects reality — the
     * owner can leave and come back and the wizard resumes correctly.
     */
    public function show(Restaurant $restaurant): Response
    {
        return Inertia::render('Admin/TenantAdmin/Onboarding', [
            'restaurant' => RestaurantData::fromModel($restaurant->load('hours')),
            'onboarding' => [
                'status' => $restaurant->status?->value,
                'pendingCustomDomain' => $restaurant->pending_custom_domain,
                'customDomainRequestedAt' => $restaurant->custom_domain_requested_at?->toIso8601String(),
                'onboardingCompletedAt' => $restaurant->onboarding_completed_at?->toIso8601String(),
                'stripeStatus' => $restaurant->stripe_account_status,
            ],
            'steps' => $this->steps->steps($restaurant),
            'canGoLive' => $this->steps->canGoLive($restaurant),
            'menuPresets' => array_map(
                fn (string $cuisine): array => [
                    'value' => $cuisine,
                    'label' => Str::headline($cuisine),
                ],
                MenuPresets::cuisines(),
            ),
            'menuSummary' => [
                'categories' => $restaurant->menuCategories()->count(),
                'items' => $restaurant->menuItems()->count(),
            ],
            'menuImport' => MenuImport::activeStateFor($restaurant),
            'menuImportLimits' => [
                'maxFiles' => (int) config('menu_import.max_files'),
                'maxFileKb' => (int) config('menu_import.max_file_kb'),
            ],
            // Handed to the client whole so the Basics form can re-suggest a
            // rate as the owner types their state, without a round trip.
            'taxRateEstimates' => SalesTaxRates::all(),
            'primaryDomain' => config('platform.primary_domain'),
        ]);
    }

    /**
     * Wizard "Basics" step: identity, branding, contact, and address in one
     * inline save. The full settings page remains the post-live home for
     * everything else (tax rate, delivery fee, ...).
     */
    public function updateBasics(
        OnboardingBasicsRequest $request,
        Restaurant $restaurant,
        RestaurantImageService $images,
    ): RedirectResponse {
        $validated = $request->validated();

        $restaurant->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'primary_color' => $validated['primary_color'] ?? null,
            'secondary_color' => $validated['secondary_color'] ?? null,
            'street' => $validated['street'] ?? '',
            'city' => $validated['city'] ?? '',
            'state' => $validated['state'] ?? '',
            'postal_code' => $validated['postal_code'] ?? '',
            // Absent means the field was left empty, not that the owner chose
            // 0% — fall back to the location estimate rather than silently
            // shipping a storefront that charges no tax.
            'tax_rate_percent' => $validated['tax_rate_percent']
                ?? SalesTaxRates::estimateFor($validated['state'] ?? null)
                ?? $restaurant->tax_rate_percent,
        ]);

        if ($request->hasFile('logo')) {
            $restaurant->logo_path = $images->storeLogo($restaurant, $request->file('logo'));
        }

        $restaurant->save();

        return back()->with('success', 'Basics saved.');
    }

    /**
     * Wizard "Menu" step: seed a starter menu from a cuisine preset. Only
     * allowed while the menu is empty — once items exist, editing happens in
     * the menu builder.
     */
    public function applyMenuPreset(Request $request, Restaurant $restaurant, MenuBuilder $menuBuilder): RedirectResponse
    {
        $validated = $request->validate([
            'preset' => ['required', 'string', Rule::in(MenuPresets::cuisines())],
        ], [
            'preset.in' => 'Choose one of the available starter menus.',
        ]);

        if ($restaurant->menuItems()->exists()) {
            throw ValidationException::withMessages([
                'preset' => 'Your menu already has items — edit it in the menu builder instead.',
            ]);
        }

        $menuBuilder->build($restaurant, $validated['preset']);

        return back()->with('success', 'Starter menu added. Every item is yours to rename, re-price, or delete.');
    }

    /**
     * Wizard "Refund policy" step: two independent food-refund toggles (pickup
     * and delivery), both off by default. Saving stamps `refund_policy_reviewed_at`
     * so the step reads as complete even when the owner deliberately leaves both
     * off — the delivery fee itself is always refunded when recoverable, so this
     * only governs the food (DoorDash plan Session 5).
     */
    public function updateRefundPolicy(Request $request, Restaurant $restaurant): RedirectResponse
    {
        $validated = $request->validate([
            'pickup_refunds_enabled' => ['required', 'boolean'],
            'delivery_refunds_enabled' => ['required', 'boolean'],
        ]);

        $restaurant->update([
            'pickup_refunds_enabled' => $validated['pickup_refunds_enabled'],
            'delivery_refunds_enabled' => $validated['delivery_refunds_enabled'],
            'refund_policy_reviewed_at' => now(),
        ]);

        return back()->with('success', 'Refund policy saved.');
    }

    /**
     * Hand the owner to their (possibly not-yet-live) storefront. Sessions are
     * host-scoped, so being logged in on the admin host means nothing on the
     * storefront host — a single-use token carries the login across, where
     * ResolveTenant lets restaurant admins through the pre-live wall.
     */
    public function preview(Request $request, Restaurant $restaurant, StorefrontLoginHandoff $handoff): SymfonyRedirectResponse
    {
        $host = parse_url($restaurant->publicUrl(), PHP_URL_HOST);

        return redirect()->away(
            $request->getScheme().'://'.$host.'/preview/enter?'.http_build_query([
                'token' => $handoff->issue($request->user(), $host),
            ])
        );
    }

    /**
     * Owner records a custom-domain request. We do NOT update `custom_domain`
     * directly — the platform configures DNS/TLS manually and flips it after
     * verification.
     */
    public function requestCustomDomain(Request $request, Restaurant $restaurant): RedirectResponse
    {
        $validated = $request->validate([
            'pending_custom_domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
        ], [
            'pending_custom_domain.regex' => 'Enter a valid domain (e.g. pizzajoint.com).',
        ]);

        $restaurant->update([
            'pending_custom_domain' => strtolower(trim($validated['pending_custom_domain'])),
            'custom_domain_requested_at' => now(),
        ]);

        return back()->with('success', 'Custom domain request submitted. We’ll be in touch once DNS is set up.');
    }

    /**
     * Transition the restaurant from `approved` to `active` and mark
     * onboarding complete. Requires required steps to pass. Returns to the
     * wizard, which renders the "you're live" celebration once isLive flips.
     */
    public function goLive(Restaurant $restaurant): RedirectResponse
    {
        if ($restaurant->status !== RestaurantStatus::Approved) {
            return back()->with('error', 'This restaurant is not in the approved state.');
        }

        if (! $this->steps->canGoLive($restaurant)) {
            throw ValidationException::withMessages([
                'go_live' => 'Finish the required onboarding steps before going live.',
            ]);
        }

        $restaurant->update([
            'status' => RestaurantStatus::Active,
            'is_active' => true,
            'onboarding_completed_at' => now(),
        ]);

        return redirect()
            ->route('admin.restaurant.onboarding.show', ['restaurant' => $restaurant->subdomain])
            ->with('success', "{$restaurant->name} is live!");
    }
}
