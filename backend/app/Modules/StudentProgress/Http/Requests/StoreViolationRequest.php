<?php

namespace App\Modules\StudentProgress\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pencatatan pelanggaran siswa. `point` opsional — bila tidak dikirim, service
 * menyalin bobot dari `violation_types.point` sebagai snapshot.
 */
class StoreViolationRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'min:1', 'exists:students,id'],
            'violation_type_id' => ['required', 'integer', 'min:1', 'exists:violation_types,id'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'point' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'reported_by' => ['nullable', 'integer', 'min:1', 'exists:employees,id'],
            'action_taken' => ['nullable', 'string', 'max:5000'],
            // Ambang khusus untuk evaluasi alert; default 100.
            'threshold' => ['nullable', 'integer', 'min:1', 'max:1000'],
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
            'threshold' => 'ambang poin',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Siswa yang melanggar wajib dipilih.',
            'student_id.exists' => 'Siswa tidak ditemukan.',
            'violation_type_id.required' => 'Jenis pelanggaran wajib dipilih — bobot poinnya diambil dari master.',
            'violation_type_id.exists' => 'Jenis pelanggaran tidak ditemukan.',
            'date.required' => 'Tanggal kejadian wajib diisi.',
            'point.min' => 'Poin tidak boleh negatif.',
            'reported_by.exists' => 'Pegawai pelapor tidak ditemukan.',
        ];
    }
}
