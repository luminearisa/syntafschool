<?php

namespace App\Http\Requests\AcademicCalendar;

use Illuminate\Foundation\Http\FormRequest;

class GenerateEffectiveDaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
            'overwrite' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'semester_id' => 'semester',
            'education_level_id' => 'tingkat',
            'overwrite' => 'timpa data lama',
        ];
    }
}
