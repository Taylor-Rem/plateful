<?php

namespace App\Http\Requests\Api\V1\Operator\Platform;

use App\Enums\RevenueRole;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EarningsLedgerRequest extends FormRequest
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
            'restaurant' => ['nullable', 'string', 'exists:restaurants,subdomain'],
            'user' => ['nullable', 'string'],
            'order' => ['nullable', 'string'],
            'role' => ['nullable', Rule::enum(RevenueRole::class)],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_refunded' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * `user` accepts an id or an email. `month` expands to a from/to pair
     * unless an explicit range was given.
     *
     * @return array{restaurant: ?Restaurant, user: ?User, order: ?string, role: ?RevenueRole, from: ?CarbonImmutable, to: ?CarbonImmutable, include_refunded: bool}
     */
    public function filters(): array
    {
        $user = null;

        if ($this->filled('user')) {
            $value = (string) $this->input('user');
            $user = User::withTrashed()
                ->when(is_numeric($value), fn ($q) => $q->whereKey((int) $value), fn ($q) => $q->where('email', $value))
                ->first();
        }

        $from = $this->filled('from') ? CarbonImmutable::parse($this->input('from')) : null;
        $to = $this->filled('to') ? CarbonImmutable::parse($this->input('to')) : null;

        if ($from === null && $to === null && $this->filled('month')) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $this->input('month').'-01');
            $from = $month->startOfMonth();
            $to = $month->endOfMonth();
        }

        return [
            'restaurant' => $this->filled('restaurant')
                ? Restaurant::query()->where('subdomain', $this->input('restaurant'))->first()
                : null,
            'user' => $user,
            'order' => $this->filled('order') ? (string) $this->input('order') : null,
            'role' => $this->filled('role') ? RevenueRole::from((string) $this->input('role')) : null,
            'from' => $from,
            'to' => $to,
            'include_refunded' => (bool) $this->boolean('include_refunded'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 50);
    }

    public function page(): int
    {
        return (int) $this->input('page', 1);
    }
}
