<?php

namespace App\Http\Requests\Api\V1\Operator;

use Illuminate\Foundation\Http\FormRequest;

class OrderListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable'],
            'status.*' => ['string'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{status: mixed, search: string, from: ?string, to: ?string, since: ?string}
     */
    public function filters(): array
    {
        return [
            'status' => $this->input('status'),
            'search' => (string) $this->input('search', ''),
            'from' => $this->input('from'),
            'to' => $this->input('to'),
            'since' => $this->input('since'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }

    public function page(): int
    {
        return (int) $this->input('page', 1);
    }
}
