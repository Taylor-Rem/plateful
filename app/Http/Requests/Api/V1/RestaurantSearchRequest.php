<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Cuisines;
use App\Support\Marketplace\RestaurantSearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestaurantSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxRadius = (float) config('platform.marketplace.max_radius_km', 100);

        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:'.$maxRadius],
            'open_now' => ['nullable', 'boolean'],
            'cuisine' => ['nullable', 'string', Rule::in(Cuisines::slugs())],
            'q' => ['nullable', 'string', 'max:100'],
            'fulfilment' => ['nullable', 'string', Rule::in(['pickup', 'delivery'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function toSearch(): RestaurantSearch
    {
        return new RestaurantSearch(
            latitude: $this->filled('lat') ? (float) $this->input('lat') : null,
            longitude: $this->filled('lng') ? (float) $this->input('lng') : null,
            radiusKm: $this->filled('radius_km') ? (float) $this->input('radius_km') : null,
            openNow: $this->boolean('open_now'),
            cuisine: $this->filled('cuisine') ? (string) $this->input('cuisine') : null,
            query: $this->filled('q') ? (string) $this->input('q') : null,
            fulfilment: $this->filled('fulfilment') ? (string) $this->input('fulfilment') : null,
        );
    }
}
