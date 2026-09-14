<?php

namespace App\Support\Operator;

use App\Enums\RestaurantImageKind;
use App\Exceptions\InvalidImageException;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantPhoto;
use App\Services\PhotoConversionService;
use App\Services\RestaurantImageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Image writes for the operator API and the MCP tools: menu item photos,
 * the restaurant's logo / hero / about slots, and the gallery. Every path
 * runs through RestaurantImageService, so the bucket layout and the webp
 * variants are identical to what the web admin produces.
 *
 * Machines cannot always post multipart, so an image may also arrive as a
 * URL to fetch or as base64; both become an UploadedFile here and are
 * validated against the same formats the web forms accept.
 */
class OperatorImages
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string, string> */
    protected const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/avif' => 'avif',
    ];

    public function __construct(protected RestaurantImageService $images) {}

    public function setMenuItemImage(MenuItem $item, UploadedFile $file): MenuItem
    {
        $item->image_path = $this->images->storeMenuItemImage($item, $file);
        $item->save();

        return $item;
    }

    public function removeMenuItemImage(MenuItem $item): MenuItem
    {
        if ($item->image_path) {
            $this->images->deleteVariants($item->image_path);
            $item->image_path = null;
            $item->save();
        }

        return $item;
    }

    public function setRestaurantImage(Restaurant $restaurant, RestaurantImageKind $kind, UploadedFile $file): Restaurant
    {
        $restaurant->{$kind->column()} = $kind->store($this->images, $restaurant, $file);
        $restaurant->save();

        return $restaurant;
    }

    public function removeRestaurantImage(Restaurant $restaurant, RestaurantImageKind $kind): Restaurant
    {
        $column = $kind->column();

        if ($restaurant->{$column}) {
            $this->images->deleteVariants($restaurant->{$column});
            $restaurant->{$column} = null;
            $restaurant->save();
        }

        return $restaurant;
    }

    public function addGalleryPhoto(Restaurant $restaurant, UploadedFile $file, ?string $caption = null): RestaurantPhoto
    {
        return DB::transaction(function () use ($restaurant, $file, $caption): RestaurantPhoto {
            $position = (int) (RestaurantPhoto::withoutTenantScope()
                ->where('restaurant_id', $restaurant->id)
                ->max('position') ?? -1) + 1;

            $photo = RestaurantPhoto::create([
                'restaurant_id' => $restaurant->id,
                'caption' => $caption !== null && trim($caption) !== '' ? trim($caption) : null,
                'position' => $position,
            ]);

            $photo->image_path = $this->images->storeGalleryPhoto($photo, $file);
            $photo->save();

            return $photo;
        });
    }

    public function removeGalleryPhoto(RestaurantPhoto $photo): void
    {
        DB::transaction(function () use ($photo): void {
            $this->images->deleteDirectoryForGalleryPhoto($photo);
            $photo->delete();
        });
    }

    /**
     * Fetch an image over HTTPS into an UploadedFile.
     *
     * @throws InvalidImageException
     */
    public function fromUrl(string $url): UploadedFile
    {
        if (! Str::startsWith(strtolower($url), ['https://', 'http://'])) {
            throw new InvalidImageException('source_url must be an http(s) URL.');
        }

        try {
            $response = Http::timeout(20)->accept('image/*')->get($url);
        } catch (ConnectionException $e) {
            throw new InvalidImageException("Could not fetch {$url}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new InvalidImageException("Could not fetch {$url}: HTTP {$response->status()}.");
        }

        return $this->fromBytes($response->body(), basename(parse_url($url, PHP_URL_PATH) ?: '') ?: 'image');
    }

    /**
     * Decode a base64 payload (a bare string or a data: URI) into an
     * UploadedFile.
     *
     * @throws InvalidImageException
     */
    public function fromBase64(string $encoded, ?string $filename = null): UploadedFile
    {
        if (str_starts_with($encoded, 'data:')) {
            $encoded = Str::after($encoded, ',');
        }

        $bytes = base64_decode(preg_replace('/\s+/', '', $encoded) ?? '', true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidImageException('image_base64 is not valid base64.');
        }

        return $this->fromBytes($bytes, $filename ?: 'image');
    }

    /**
     * @throws InvalidImageException
     */
    public function fromBytes(string $bytes, string $filename): UploadedFile
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidImageException('Image is larger than 8 MB.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        $accepted = explode(',', Str::after(PhotoConversionService::acceptedPhotoMimes(), 'mimes:'));

        if ($extension === null || ! in_array($extension, $accepted, true)) {
            throw new InvalidImageException("Unsupported image type [{$mime}]; accepted: ".implode(', ', $accepted).'.');
        }

        $path = tempnam(sys_get_temp_dir(), 'pf-img-');
        file_put_contents($path, $bytes);

        $name = Str::beforeLast($filename, '.') ?: 'image';

        return new UploadedFile($path, "{$name}.{$extension}", $mime, null, true);
    }
}
