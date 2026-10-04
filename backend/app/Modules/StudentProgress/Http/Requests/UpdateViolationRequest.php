<?php

namespace App\Modules\StudentProgress\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penyuntingan pelanggaran siswa. `status` sengaja tidak diterima di sini —
 * perpindahan status hanya lewat endpoint verify/resolve.
 */
class UpdateViolationRequest extends FormRequest
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
            'student_id' => ['sometimes', 'integer', 'min:1', 'exists:students,id'],
            'violation_type_id' => ['sometimes', 'integer', 'min:1', 'exists:violation_types,id'],
            'date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'point' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'reported_by' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:employees,id'],
            'action_taken' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'siswa',
            'violation_type_id' => 'jenis pelanggaran',
            'date' => 'tanggal kejadian',
            'description' => 'uraian kejadian',
            'point' => 'poin',
            'reported_by' => 'pelapor',
            'action_taken' => 'tindakan',
        ];
    }
}
