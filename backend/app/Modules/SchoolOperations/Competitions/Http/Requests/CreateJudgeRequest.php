<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateJudgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['display_name' => ['nullable', 'string', 'max:200'], 'employee_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer', 'exists:users,id'], 'campus_id' => ['nullable', 'integer'], 'expertise' => ['nullable', 'string', 'max:200'], 'qualification' => ['nullable', 'string', 'max:200'], 'organization_name' => ['nullable', 'string', 'max:200'], 'certification_number' => ['nullable', 'string', 'max:100'], 'is_certified' => ['sometimes', 'boolean'], 'max_concurrent_assignments' => ['nullable', 'integer', 'min:1'], 'conflict_declaration' => ['nullable', 'array']];
    }
}
