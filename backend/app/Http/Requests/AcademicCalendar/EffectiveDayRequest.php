<?php

namespace App\Http\Requests\AcademicCalendar;

use Illuminate\Foundation\Http\FormRequest;

class EffectiveDayRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'is_school_day' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:150'],
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
            'date' => 'tanggal',
            'is_school_day' => 'status hari sekolah',
            'note' => 'catatan',
        ];
    }
}
