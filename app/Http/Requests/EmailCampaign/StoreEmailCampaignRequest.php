<?php

namespace App\Http\Requests\EmailCampaign;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmailCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title_prefix' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'sender_name' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'cta_label' => ['required', 'string', 'max:80'],
            'cta_url' => ['required', 'url', 'max:2048'],
            'settings' => ['nullable', 'array'],
            'settings.send_on_import' => ['nullable', 'boolean'],
        ];
    }
}
