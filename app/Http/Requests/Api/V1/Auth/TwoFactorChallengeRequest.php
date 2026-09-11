<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
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
        return [
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
        ];
    }

    public function code(): ?string
    {
        $code = trim((string) $this->input('code', ''));

        return $code === '' ? null : $code;
    }

    public function recoveryCode(): ?string
    {
        $code = trim((string) $this->input('recovery_code', ''));

        return $code === '' ? null : $code;
    }
}
