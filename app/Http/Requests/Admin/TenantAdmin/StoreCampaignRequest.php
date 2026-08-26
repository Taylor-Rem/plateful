<?php

namespace App\Http\Requests\Admin\TenantAdmin;

use Illuminate\Validation\Rule;

class StoreCampaignRequest extends CampaignFieldsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'action' => ['required', Rule::in(['save', 'send', 'schedule'])],
            // Interpreted in the restaurant's timezone; the future check
            // happens in the controller after the timezone-aware parse.
            'scheduled_at' => ['nullable', 'required_if:action,schedule', 'date'],
        ]);
    }
}
