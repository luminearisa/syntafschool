<?php

namespace App\Modules\Promotion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePromotionRuleRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:150'],
            'education_level_id' => ['sometimes', 'nullable', 'integer', 'exists:education_levels,id'],
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,id'],
            'min_average_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'min_attendance_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'max_unpassed_subjects' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'min_passing_grade' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'violation_point_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
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
