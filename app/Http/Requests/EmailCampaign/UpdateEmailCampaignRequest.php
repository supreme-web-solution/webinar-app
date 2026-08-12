<?php

namespace App\Http\Requests\EmailCampaign;

use App\Models\EmailCampaign;
use App\Rules\PersonDisplayName;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var EmailCampaign|null $campaign */
        $campaign = $this->route('campaign');

        return $this->user() !== null
            && $campaign !== null
            && $campaign->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'title_prefix' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'sender_name' => ['required', 'string', 'max:255', new PersonDisplayName],
            'body' => ['nullable', 'string'],
            'cta_label' => ['required', 'string', 'max:80'],
            'cta_url' => ['required', 'url', 'max:2048'],
            'settings' => ['nullable', 'array'],
            'settings.send_on_import' => ['nullable', 'boolean'],
        ];
    }
}
