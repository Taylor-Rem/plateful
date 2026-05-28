<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class RestaurantImageService
{
    public const DISK = 'restaurant_assets';

    public const MENU_ITEM_MEDIUM = 800;

    public const MENU_ITEM_THUMB = 200;

    public const LOGO_MEDIUM = 400;

    public const LOGO_THUMB = 100;

    public const HERO_MEDIUM = 1200;

    public const HERO_THUMB = 400;

    public const ABOUT_MEDIUM = 800;

    public const ABOUT_THUMB = 300;

    public const ORIGINAL_CAP = 2000;

    public const WEBP_QUALITY = 85;

    protected ImageManager $manager;

    public function __construct()
    {
        $driver = extension_loaded('gd') ? new GdDriver : new ImagickDriver;
        $this->manager = new ImageManager($driver);
    }

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

        $disk = Storage::disk(self::DISK);

        foreach ($this->variantPaths($diskPath) as $variant) {
            if ($disk->exists($variant)) {
                $disk->delete($variant);
            }
        }
    }

    public function deleteDirectoryForLogo(Restaurant $restaurant): void
    {
        Storage::disk(self::DISK)->deleteDirectory("restaurants/{$restaurant->id}/logo");
    }

    public function deleteDirectoryForMenuItem(MenuItem $item): void
    {
        Storage::disk(self::DISK)
            ->deleteDirectory("restaurants/{$item->restaurant_id}/menu-items/{$item->id}");
    }

    public function deleteDirectoryForRestaurant(Restaurant $restaurant): void
    {
        Storage::disk(self::DISK)->deleteDirectory("restaurants/{$restaurant->id}");
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
        $disk = Storage::disk(self::DISK);
        $uuid = (string) Str::uuid();

        $basePath = "{$directory}/{$uuid}.webp";
        $mediumPath = "{$directory}/{$uuid}-medium.webp";
        $thumbPath = "{$directory}/{$uuid}-thumb.webp";

        $sourcePath = $file->getRealPath();

        $disk->put($basePath, $this->resizeToWebp($this->manager->decodePath($sourcePath), self::ORIGINAL_CAP));
        $disk->put($mediumPath, $this->resizeToWebp($this->manager->decodePath($sourcePath), $mediumMax));
        $disk->put($thumbPath, $this->resizeToWebp($this->manager->decodePath($sourcePath), $thumbMax));

        return $basePath;
    }

    protected function resizeToWebp(ImageInterface $image, int $max): string
    {
        $image->scaleDown(width: $max, height: $max);

        return (string) $image->encode(new WebpEncoder(quality: self::WEBP_QUALITY));
    }
}
