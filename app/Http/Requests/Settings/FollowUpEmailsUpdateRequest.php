<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class FollowUpEmailsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $segmentRules = [
            'enabled' => ['required', 'boolean'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
        ];

        return [
            'segments' => ['required', 'array'],
            'segments.below_50' => ['required', 'array'],
            'segments.below_50.enabled' => $segmentRules['enabled'],
            'segments.below_50.subject' => $segmentRules['subject'],
            'segments.below_50.body' => $segmentRules['body'],
            'segments.above_50' => ['required', 'array'],
            'segments.above_50.enabled' => $segmentRules['enabled'],
            'segments.above_50.subject' => $segmentRules['subject'],
            'segments.above_50.body' => $segmentRules['body'],
            'segments.completed_no_click' => ['required', 'array'],
            'segments.completed_no_click.enabled' => $segmentRules['enabled'],
            'segments.completed_no_click.subject' => $segmentRules['subject'],
            'segments.completed_no_click.body' => $segmentRules['body'],
        ];
    }
}
