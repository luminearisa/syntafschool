<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:competition_categories,id'],
            'participant_type' => ['nullable', Rule::in(['STUDENT', 'TEAM', 'EMPLOYEE', 'TEACHER', 'EXTERNAL', 'ORGANIZATION'])],
            'student_id' => ['nullable', 'integer', 'min:1'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer', 'exists:competition_teams,id'],
            'form_id' => ['nullable', 'integer', 'exists:competition_registration_forms,id'],
            'institution_name' => ['nullable', 'string', 'max:200'],
            'external_participant_name' => ['nullable', 'string', 'max:200'],
            'external_participant_email' => ['nullable', 'email', 'max:180'],
            'external_participant_phone' => ['nullable', 'string', 'max:40'],
            'external_participant_id_number' => ['nullable', 'string', 'max:80'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'submitted_via' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', 'string', 'max:30'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
