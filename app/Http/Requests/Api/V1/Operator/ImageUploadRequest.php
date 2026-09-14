<?php

namespace App\Http\Requests\Api\V1\Operator;

use App\Exceptions\InvalidImageException;
use App\Services\PhotoConversionService;
use App\Support\Operator\OperatorImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * An image arrives as a multipart `image` file, or as `source_url` for a
 * client that cannot post multipart. Exactly one of the two.
 */
class ImageUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'image' => ['required_without:source_url', 'prohibits:source_url', 'file', PhotoConversionService::acceptedPhotoMimes(), 'max:8192'],
            'source_url' => ['required_without:image', 'nullable', 'url:http,https'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @throws InvalidImageException
     */
    public function uploadedImage(OperatorImages $images): UploadedFile
    {
        if ($this->hasFile('image')) {
            return $this->file('image');
        }

        return $images->fromUrl((string) $this->input('source_url'));
    }
}
