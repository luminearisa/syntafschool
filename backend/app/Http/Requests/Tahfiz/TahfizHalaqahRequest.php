<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Data halaqah (kelompok belajar Tahfiz).
 */
class TahfizHalaqahRequest extends FormRequest
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
            'program_id' => ['required', 'integer', 'exists:tahfiz_programs,id'],
            'level_id' => ['nullable', 'integer', 'exists:tahfiz_levels,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'unique:tahfiz_halaqahs,code'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'instructor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'assistant_id' => ['nullable', 'integer', 'exists:employees,id'],
            'musyrif_id' => ['nullable', 'integer', 'exists:employees,id'],
            'schedule_summary' => ['nullable', 'string', 'max:150'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'status' => ['nullable', 'string', 'in:active,inactive,merged'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'program_id' => 'program',
            'level_id' => 'level',
            'capacity' => 'kapasitas',
            'room_id' => 'ruangan',
            'instructor_id' => 'instruktur',
            'assistant_id' => 'asisten',
            'musyrif_id' => 'musyrif',
        ];
    }
}
