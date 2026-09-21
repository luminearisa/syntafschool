<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Data program Tahfiz.
 */
class TahfizProgramRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:30', 'unique:tahfiz_programs,code'],
            'description' => ['nullable', 'string'],
            'participant_type' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TahfizProgram::PARTICIPANT_TYPES)],
            'duration_value' => ['nullable', 'integer', 'min:1'],
            'duration_unit' => ['nullable', 'string', 'in:day,week,month,semester,year'],
            'target_type' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TahfizProgram::TARGET_TYPES)],
            'target_value' => ['nullable', 'string', 'max:100'],
            'minimum_attendance' => ['nullable', 'integer', 'min:0', 'max:100'],
            'assessment_scheme' => ['nullable', 'array'],
            'requires_placement_test' => ['nullable', 'boolean'],
            'self_registration' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TahfizProgram::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode program',
            'participant_type' => 'jenis peserta',
            'duration_value' => 'durasi',
            'duration_unit' => 'satuan durasi',
            'target_type' => 'bentuk target',
            'minimum_attendance' => 'kehadiran minimum',
            'assessment_scheme' => 'skema penilaian',
        ];
    }
}
