<?php

namespace App\Enums;

use App\Models\Restaurant;
use App\Services\RestaurantImageService;
use Illuminate\Http\UploadedFile;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The three single-slot branding images a restaurant has. Gallery photos
 * are rows, not slots, and live on RestaurantPhoto.
 */
#[TypeScript]
enum RestaurantImageKind: string
{
    case Logo = 'logo';
    case Hero = 'hero';
    case About = 'about';

    public function column(): string
    {
        return match ($this) {
            self::Logo => 'logo_path',
            self::Hero => 'hero_image_path',
            self::About => 'about_image_path',
        };
    }

    public function store(RestaurantImageService $images, Restaurant $restaurant, UploadedFile $file): string
    {
        return match ($this) {
            self::Logo => $images->storeLogo($restaurant, $file),
            self::Hero => $images->storeHeroImage($restaurant, $file),
            self::About => $images->storeAboutImage($restaurant, $file),
        };
    }
}
