<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['category_id' => ['required', 'integer', 'exists:competition_categories,id'], 'code' => ['nullable', 'string', 'max:60'], 'name' => ['required', 'string', 'max:200'], 'institution_name' => ['nullable', 'string', 'max:200'], 'campus_id' => ['nullable', 'integer'], 'coach_employee_id' => ['nullable', 'integer'], 'mentor_employee_id' => ['nullable', 'integer'], 'manager_name' => ['nullable', 'string', 'max:200'], 'manager_contact' => ['nullable', 'string', 'max:60'], 'members' => ['required', 'array', 'min:1'], 'members.*.student_id' => ['required', 'integer', 'min:1'], 'members.*.role' => ['nullable', 'string', 'max:40'], 'members.*.position' => ['nullable', 'string', 'max:60'], 'members.*.jersey_number' => ['nullable', 'string', 'max:20'], 'members.*.is_captain' => ['sometimes', 'boolean'], 'members.*.is_substitute' => ['sometimes', 'boolean']];
    }
}
