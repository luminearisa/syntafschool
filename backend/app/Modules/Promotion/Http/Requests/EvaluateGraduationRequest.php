<?php

namespace App\Modules\Promotion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EvaluateGraduationRequest extends FormRequest
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
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'academic_year_id' => 'tahun ajaran',
            'education_level_id' => 'jenjang',
            'class_id' => 'kelas',
            'semester_id' => 'semester',
        ];
    }
}
