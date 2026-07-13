<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Imagick;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Single conversion path for every photo upload: decode whatever the customer
 * gives us (including iPhone HEIC when Imagick supports it), cap dimensions,
 * and re-encode as compressed webp. One format in the bucket keeps storage
 * cheap and downstream consumers (browsers, the menu-extraction API) simple.
 */
class PhotoConversionService
{
    public const WEBP_QUALITY = 85;

    protected ImageManager $manager;

    public function __construct()
    {
        // Imagick first: it decodes HEIC/HEIF (the iPhone default); GD cannot.
        $this->manager = new ImageManager(
            extension_loaded('imagick') ? new ImagickDriver : new GdDriver,
        );
    }

    /**
     * Can this environment decode HEIC/HEIF uploads?
     */
    public static function supportsHeic(): bool
    {
        return extension_loaded('imagick')
            && Imagick::queryFormats('HEIC') !== [];
    }

    /**
     * The `mimes:` validation rule for photo uploads, sized to what this
     * environment can actually decode. Use instead of the `image` rule —
     * that rule rejects HEIC outright.
     */
    public static function acceptedPhotoMimes(): string
    {
        $mimes = 'jpeg,jpg,png,webp';

        if (self::supportsHeic()) {
            $mimes .= ',heic,heif';
        }

        return 'mimes:'.$mimes;
    }

    /**
     * Decode once, then produce several sizes via toWebp() without re-reading
     * the file.
     */
    public function decode(UploadedFile $file): ImageInterface
    {
        return $this->manager->decodePath($file->getRealPath());
    }

    /**
     * Compressed webp binary, scaled down to fit within $maxDimension.
     * Never upscales.
     */
    public function toWebp(
        ImageInterface|UploadedFile $image,
        int $maxDimension,
        int $quality = self::WEBP_QUALITY,
    ): string {
        if ($image instanceof UploadedFile) {
            $image = $this->decode($image);
        }

        $image->scaleDown(width: $maxDimension, height: $maxDimension);

        return (string) $image->encode(new WebpEncoder(quality: $quality));
    }
}
