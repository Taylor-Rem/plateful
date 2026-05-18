<?php

namespace App\Concerns;

use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $this->buildEmailUniqueRule($userId),
        ];
    }

    private function buildEmailUniqueRule(?int $userId): Unique
    {
        $restaurantId = app(CurrentTenant::class)->id();

        $rule = Rule::unique(User::class, 'email')
            ->where(fn ($q) => $q->where('restaurant_id', $restaurantId));

        return $userId === null ? $rule : $rule->ignore($userId);
    }
}
