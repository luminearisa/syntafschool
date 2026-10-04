<?php

namespace App\Modules\Promotion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePromotionRuleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'min_average_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_attendance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_unpassed_subjects' => ['nullable', 'integer', 'min:0'],
            'min_passing_grade' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'violation_point_limit' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama aturan',
            'education_level_id' => 'jenjang',
            'academic_year_id' => 'tahun ajaran',
            'min_average_score' => 'nilai rata-rata minimum',
            'min_attendance_percent' => 'persentase kehadiran minimum',
            'max_unpassed_subjects' => 'maksimal mata pelajaran tidak tuntas',
            'min_passing_grade' => 'nilai ketuntasan minimum',
            'violation_point_limit' => 'batas poin pelanggaran',
            'is_active' => 'status aktif',
        ];
    }
}
