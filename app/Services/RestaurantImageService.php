<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RestaurantImageService
{
    /**
     * The filesystem disk used for all restaurant media. Resolves to the
     * configured media disk, which defaults to the application's default
     * disk (the bucket Laravel Cloud injects in production).
     */
    public static function disk(): string
    {
        return config('media.disk', config('filesystems.default'));
    }

    public const MENU_ITEM_MEDIUM = 800;

    public const MENU_ITEM_THUMB = 200;

    public const LOGO_MEDIUM = 400;

    public const LOGO_THUMB = 100;

    public const HERO_MEDIUM = 1200;

    public const HERO_THUMB = 400;

    public const ABOUT_MEDIUM = 800;

    public const ABOUT_THUMB = 300;

    public const GALLERY_MEDIUM = 1000;

    public const GALLERY_THUMB = 300;

    public const ORIGINAL_CAP = 2000;

    public function __construct(protected PhotoConversionService $conversion) {}

    public function storeLogo(Restaurant $restaurant, UploadedFile $file): string
    {
        $previous = $restaurant->logo_path;

        $path = $this->processAndStore(
            $file,
            "restaurants/{$restaurant->id}/logo",
            self::LOGO_MEDIUM,
            self::LOGO_THUMB,
        );

        if ($previous && $previous !== $path) {
            $this->deleteVariants($previous);
        }

        return $path;
    }

    public function storeHeroImage(Restaurant $restaurant, UploadedFile $file): string
    {
        $previous = $restaurant->hero_image_path;

        $path = $this->processAndStore(
            $file,
            "restaurants/{$restaurant->id}/hero",
            self::HERO_MEDIUM,
            self::HERO_THUMB,
        );

        if ($previous && $previous !== $path) {
            $this->deleteVariants($previous);
        }

        return $path;
    }

    public function storeAboutImage(Restaurant $restaurant, UploadedFile $file): string
    {
        $previous = $restaurant->about_image_path;

        $path = $this->processAndStore(
            $file,
            "restaurants/{$restaurant->id}/about",
            self::ABOUT_MEDIUM,
            self::ABOUT_THUMB,
        );

        if ($previous && $previous !== $path) {
            $this->deleteVariants($previous);
        }

        return $path;
    }

    public function storeGalleryPhoto(RestaurantPhoto $photo, UploadedFile $file): string
    {
        $previous = $photo->image_path;

        $path = $this->processAndStore(
            $file,
            "restaurants/{$photo->restaurant_id}/gallery/{$photo->id}",
            self::GALLERY_MEDIUM,
            self::GALLERY_THUMB,
        );

        if ($previous && $previous !== $path) {
            $this->deleteVariants($previous);
        }

        return $path;
    }

    public function deleteDirectoryForGalleryPhoto(RestaurantPhoto $photo): void
    {
        Storage::disk(self::disk())
            ->deleteDirectory("restaurants/{$photo->restaurant_id}/gallery/{$photo->id}");
    }

    public function storeMenuItemImage(MenuItem $item, UploadedFile $file): string
    {
        $previous = $item->image_path;

        $path = $this->processAndStore(
            $file,
            "restaurants/{$item->restaurant_id}/menu-items/{$item->id}",
            self::MENU_ITEM_MEDIUM,
            self::MENU_ITEM_THUMB,
        );

        if ($previous && $previous !== $path) {
            $this->deleteVariants($previous);
        }

        return $path;
    }

    /**
     * Deletes the original + medium + thumb variants for the given base path.
     */
    public function deleteVariants(?string $diskPath): void
    {
        if (! $diskPath) {
            return;
        }

        $disk = Storage::disk(self::disk());

        foreach ($this->variantPaths($diskPath) as $variant) {
            if ($disk->exists($variant)) {
                $disk->delete($variant);
            }
        }
    }

    public function deleteDirectoryForLogo(Restaurant $restaurant): void
    {
        Storage::disk(self::disk())->deleteDirectory("restaurants/{$restaurant->id}/logo");
    }

    public function deleteDirectoryForMenuItem(MenuItem $item): void
    {
        Storage::disk(self::disk())
            ->deleteDirectory("restaurants/{$item->restaurant_id}/menu-items/{$item->id}");
    }

    public function deleteDirectoryForRestaurant(Restaurant $restaurant): void
    {
        Storage::disk(self::disk())->deleteDirectory("restaurants/{$restaurant->id}");
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    public function variantPaths(string $basePath): array
    {
        $dir = trim((string) Str::beforeLast($basePath, '/'), '/');
        $file = Str::afterLast($basePath, '/');
        $name = Str::beforeLast($file, '.');
        $prefix = $dir === '' ? '' : $dir.'/';

        return [
            $basePath,
            "{$prefix}{$name}-medium.webp",
            "{$prefix}{$name}-thumb.webp",
        ];
    }

    protected function processAndStore(
        UploadedFile $file,
        string $directory,
        int $mediumMax,
        int $thumbMax,
    ): string {
        $disk = Storage::disk(self::disk());
        $uuid = (string) Str::uuid();

        $basePath = "{$directory}/{$uuid}.webp";
        $mediumPath = "{$directory}/{$uuid}-medium.webp";
        $thumbPath = "{$directory}/{$uuid}-thumb.webp";

        $image = $this->conversion->decode($file);

        $disk->put($basePath, $this->conversion->toWebp($image, self::ORIGINAL_CAP), 'public');
        $disk->put($mediumPath, $this->conversion->toWebp($image, $mediumMax), 'public');
        $disk->put($thumbPath, $this->conversion->toWebp($image, $thumbMax), 'public');

        unset($image);

        return $basePath;
    }
}
