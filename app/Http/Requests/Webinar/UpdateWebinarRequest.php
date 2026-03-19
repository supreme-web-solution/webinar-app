<?php

namespace App\Http\Requests\Webinar;

use App\Models\Webinar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebinarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Webinar|null $webinar */
        $webinar = $this->route('webinar');

        return $this->user() !== null && $webinar !== null && $this->user()->is($webinar->user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'host_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'scheduled_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'scheduled_timezone' => ['required', 'string', 'timezone:all'],
            'video_source' => ['required', Rule::in(['youtube', 'vimeo', 'direct'])],
            'video_url' => ['required', 'url', 'max:2048'],
            'video_duration_seconds' => ['nullable', 'integer', 'min:1'],
            'thumbnail_path' => ['nullable', 'string', 'max:255'],
            'min_viewers' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_viewers' => ['required', 'integer', 'gte:min_viewers', 'max:100000'],
            'is_published' => ['sometimes', 'boolean'],
            'email_settings' => ['nullable', 'array'],
            'email_settings.send_confirmation' => ['nullable', 'boolean'],
            'email_settings.send_reminder' => ['nullable', 'boolean'],
            'email_settings.send_follow_up' => ['nullable', 'boolean'],
            'playback_settings' => ['nullable', 'array'],
            'registration_settings' => ['nullable', 'array'],
            'registration_settings.buttons' => ['nullable', 'array', 'size:3'],
            'registration_settings.buttons.*.label' => ['required_with:registration_settings.buttons', 'string', 'max:80'],
            'registration_settings.buttons.*.enabled' => ['required_with:registration_settings.buttons', 'boolean'],
            'registration_settings.buttons.*.is_primary' => ['required_with:registration_settings.buttons', 'boolean'],
            'registration_settings.buttons.*.urgency_mode' => ['required_with:registration_settings.buttons', Rule::in(['none', 'minutes', 'live'])],
            'registration_settings.buttons.*.urgency_minutes' => ['nullable', 'integer', 'min:1', 'max:999'],
            'offers' => ['nullable', 'array'],
            'offers.*.title' => ['required_with:offers', 'string', 'max:255'],
            'offers.*.description' => ['nullable', 'string'],
            'offers.*.trigger_second' => ['required_with:offers', 'integer', 'min:1'],
            'offers.*.button_text' => ['nullable', 'string', 'max:80'],
            'offers.*.button_url' => ['required_with:offers', 'url', 'max:2048'],
            'offers.*.display_mode' => ['nullable', Rule::in(['chat', 'popup', 'pinned'])],
        ];
    }
}
