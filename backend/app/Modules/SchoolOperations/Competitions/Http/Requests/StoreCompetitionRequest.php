<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompetitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'competition_type_id' => ['required', 'integer', 'exists:competition_types,id'],
            'competition_domain_id' => ['nullable', 'integer', 'exists:competition_domains,id'],
            'code' => ['nullable', 'string', 'max:60', 'unique:competitions,code'],
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220', 'unique:competitions,slug'],
            'short_name' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:20000'],
            'organizer_name' => ['nullable', 'string', 'max:200'],
            'organizer_contact_email' => ['nullable', 'email', 'max:180'],
            'organizer_contact_phone' => ['nullable', 'string', 'max:40'],
            'organizer_organization_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'],
            'campus_id' => ['nullable', 'integer'],
            'is_internal' => ['sometimes', 'boolean'],
            'is_recurring' => ['sometimes', 'boolean'],
            'recurrence_pattern' => ['nullable', 'string', 'max:40'],
            'default_visibility' => ['nullable', Rule::in(['INTERNAL', 'PRIVATE', 'PUBLIC'])],
            'default_participant_mode' => ['nullable', Rule::in(['INDIVIDUAL', 'TEAM', 'MIXED', 'ORGANIZATION'])],
            'settings' => ['nullable', 'array'],
        ];
    }
}
