<?php

namespace App\Http\Controllers\Storefront\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AboutUpdateRequest;
use App\Http\Requests\Admin\HeroUpdateRequest;
use App\Http\Requests\Admin\SocialUpdateRequest;
use App\Models\Restaurant;
use App\Services\RestaurantImageService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{
    public function updateHero(
        HeroUpdateRequest $request,
        CurrentTenant $tenant,
        RestaurantImageService $images,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $this->authorize('updateSite', $restaurant);

        $validated = $request->validated();

        DB::transaction(function () use ($restaurant, $validated, $request, $images): void {
            $restaurant->update([
                'hero_tagline' => $validated['hero_tagline'] ?? null,
                'hero_cta_label' => $validated['hero_cta_label'] ?? null,
                'hero_cta_url' => $validated['hero_cta_url'] ?? null,
            ]);

            if ($request->boolean('remove_image') && $restaurant->hero_image_path) {
                $images->deleteVariants($restaurant->hero_image_path);
                $restaurant->hero_image_path = null;
                $restaurant->save();
            }

            if ($request->hasFile('image')) {
                $restaurant->hero_image_path = $images->storeHeroImage($restaurant, $request->file('image'));
                $restaurant->save();
            }
        });

        return back()->with('success', 'Hero updated.');
    }

    public function updateAbout(
        AboutUpdateRequest $request,
        CurrentTenant $tenant,
        RestaurantImageService $images,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $this->authorize('updateSite', $restaurant);

        $validated = $request->validated();

        DB::transaction(function () use ($restaurant, $validated, $request, $images): void {
            $restaurant->update([
                'about_body' => $validated['about_body'] ?? null,
            ]);

            if ($request->boolean('remove_image') && $restaurant->about_image_path) {
                $images->deleteVariants($restaurant->about_image_path);
                $restaurant->about_image_path = null;
                $restaurant->save();
            }

            if ($request->hasFile('image')) {
                $restaurant->about_image_path = $images->storeAboutImage($restaurant, $request->file('image'));
                $restaurant->save();
            }
        });

        return back()->with('success', 'About updated.');
    }

    public function updateSocial(
        SocialUpdateRequest $request,
        CurrentTenant $tenant,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $this->authorize('updateSite', $restaurant);

        $raw = $request->validated()['social_links'] ?? [];

        // Whitelist to known platforms and drop blanks.
        $clean = [];
        foreach (Restaurant::SOCIAL_PLATFORMS as $platform) {
            $value = isset($raw[$platform]) ? trim((string) $raw[$platform]) : '';
            if ($value !== '') {
                $clean[$platform] = $value;
            }
        }

        $restaurant->update([
            'social_links' => $clean === [] ? null : $clean,
        ]);

        return back()->with('success', 'Social links updated.');
    }
}
