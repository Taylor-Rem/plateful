<?php

namespace App\Mcp\Tools\Concerns;

use App\Exceptions\InvalidImageException;
use App\Support\Operator\OperatorImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * MCP tools cannot post multipart, so an image arrives as `source_url`
 * (fetched server-side) or `image_base64`.
 */
trait ResolvesImageSource
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws InvalidImageException
     */
    protected function imageFromInput(OperatorImages $images, array $input): UploadedFile
    {
        $url = trim((string) ($input['source_url'] ?? ''));
        $base64 = (string) ($input['image_base64'] ?? '');

        if (($url === '') === ($base64 === '')) {
            throw ValidationException::withMessages(['source_url' => 'Pass exactly one of source_url or image_base64.']);
        }

        return $url !== ''
            ? $images->fromUrl($url)
            : $images->fromBase64($base64, $input['filename'] ?? null);
    }
}
