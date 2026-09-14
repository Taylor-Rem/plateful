<?php

namespace App\Http\Requests\Api\V1\Operator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerListRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'ordered' => ['nullable', Rule::in([30, 90, '30', '90'])],
            'marketing' => ['nullable', Rule::in(['opted_in'])],
            'sort' => ['nullable', 'string'],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{search: string, ordered: ?int, marketing: ?string}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->input('search', '')),
            'ordered' => $this->filled('ordered') ? (int) $this->input('ordered') : null,
            'marketing' => $this->input('marketing') === 'opted_in' ? 'opted_in' : null,
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
