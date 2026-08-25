<?php

namespace App\Http\Requests\Admin\TenantAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The composable campaign fields (structured template + audience recipe).
 * Used directly by preview/test — which act on unsaved compose state — and
 * extended by StoreCampaignRequest, which adds the save/send/schedule action.
 */
class CampaignFieldsRequest extends FormRequest
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
            'subject' => ['required', 'string', 'max:150'],
            'preheader' => ['nullable', 'string', 'max:150'],
            'headline' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'offer_callout' => ['nullable', 'string', 'max:200'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'cta_url' => ['nullable', 'url', 'max:255'],
            'audience.type' => ['required', Rule::in(['all', 'lapsed', 'regulars'])],
            'audience.days' => ['nullable', 'required_if:audience.type,lapsed', 'integer', 'min:1', 'max:365'],
            'audience.min_orders' => ['nullable', 'required_if:audience.type,regulars', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The normalized audience_filter recipe: only the knob relevant to the
     * chosen type is kept.
     *
     * @return array{type: string, days?: int, min_orders?: int}
     */
    public function audienceFilter(): array
    {
        $type = (string) $this->input('audience.type');

        return match ($type) {
            'lapsed' => ['type' => 'lapsed', 'days' => (int) $this->input('audience.days')],
            'regulars' => ['type' => 'regulars', 'min_orders' => (int) $this->input('audience.min_orders')],
            default => ['type' => 'all'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function campaignFields(): array
    {
        return [
            'subject' => $this->string('subject')->toString(),
            'preheader' => $this->input('preheader') ?: null,
            'headline' => $this->string('headline')->toString(),
            'body' => $this->string('body')->toString(),
            'offer_callout' => $this->input('offer_callout') ?: null,
            'cta_label' => $this->input('cta_label') ?: null,
            'cta_url' => $this->input('cta_url') ?: null,
            'audience_filter' => $this->audienceFilter(),
        ];
    }
}
