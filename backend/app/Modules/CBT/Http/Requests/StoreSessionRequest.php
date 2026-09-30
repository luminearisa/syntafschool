<?php

namespace App\Modules\CBT\Http\Requests;

use App\Modules\CBT\Domain\Enums\Difficulty;
use App\Modules\CBT\Domain\Enums\IncidentType;
use App\Modules\CBT\Domain\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'blueprint_id' => ['required', 'integer', 'exists:cbt_blueprints,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'proctor_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'settings' => ['nullable', 'array'],
            'settings.require_token' => ['nullable', 'boolean'],
            'settings.show_score' => ['nullable', 'boolean'],
            'settings.lock_navigation' => ['nullable', 'boolean'],
            'settings.max_blur' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }
}
