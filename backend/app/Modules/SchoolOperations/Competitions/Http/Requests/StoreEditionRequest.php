<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2000,2200'],
            'code' => ['nullable', 'string', 'max:60', 'unique:competition_editions,code'],
            'name' => ['nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220', 'unique:competition_editions,slug'],
            'level_id' => ['nullable', 'integer', 'exists:competition_levels,id'],
            'campus_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'],
            'theme' => ['nullable', 'string', 'max:200'],
            'organizer_name' => ['nullable', 'string', 'max:200'],
            'host_name' => ['nullable', 'string', 'max:200'],
            'event_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:20000'],
            'terms' => ['nullable', 'string', 'max:20000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'registration_opens' => ['nullable', 'date'],
            'registration_closes' => ['nullable', 'date', 'after:registration_opens'],
            'participant_type' => ['nullable', Rule::in(['STUDENT', 'TEAM', 'EMPLOYEE', 'TEACHER', 'EXTERNAL', 'MIXED'])],
            'registration_mode' => ['nullable', Rule::in(['OPEN', 'INVITATION', 'NOMINATION', 'ADMIN'])],
            'max_participants' => ['nullable', 'integer', 'min:1'],
            'max_teams' => ['nullable', 'integer', 'min:1'],
            'allows_external' => ['sometimes', 'boolean'],
            'allows_internal' => ['sometimes', 'boolean'],
            'requires_parent_consent' => ['sometimes', 'boolean'],
            'requires_payment' => ['sometimes', 'boolean'],
            'requires_eligibility' => ['sometimes', 'boolean'],
            'requires_selection' => ['sometimes', 'boolean'],
            'requires_documents' => ['sometimes', 'boolean'],
            'timezone' => ['nullable', 'timezone'],
            'public_visibility' => ['nullable', Rule::in(['INTERNAL', 'PRIVATE', 'PUBLIC'])],
            'settings' => ['nullable', 'array'],
        ];
    }
}
