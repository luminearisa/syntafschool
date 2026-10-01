<?php

namespace App\Modules\Asset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('facility.book') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'purpose' => ['nullable', 'string'],
            'attendee_count' => ['nullable', 'integer', 'min:1'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'source' => ['nullable', 'string', 'max:24'],
            'event_reference' => ['nullable', 'string', 'max:80'],
            'activity_reference' => ['nullable', 'string', 'max:80'],
            'academic_reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
