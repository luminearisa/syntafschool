<?php

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pencatatan pelanggaran santri (§36). Poin TIDAK dikirim klien — service
 * menyalinnya dari master sebagai snapshot saat kejadian.
 */
class BoardingViolationRequest extends FormRequest
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
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'student_id' => [$required, 'integer', 'min:1', 'exists:students,id'],
            'violation_type_id' => [$required, 'integer', 'min:1', 'exists:boarding_violation_types,id'],
            'date' => [$required, 'date'],
            'narrative' => ['nullable', 'string', 'max:5000'],
            'evidence_path' => ['nullable', 'string', 'max:255'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'string', 'in:open,actioned,closed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'santri',
            'violation_type_id' => 'jenis pelanggaran',
            'date' => 'tanggal kejadian',
            'narrative' => 'uraian kejadian',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Santri yang melanggar wajib dipilih.',
            'student_id.exists' => 'Santri tidak ditemukan pada data siswa sekolah ini.',
            'violation_type_id.required' => 'Jenis pelanggaran wajib dipilih — bobot poinnya diambil dari master (§36).',
            'violation_type_id.exists' => 'Jenis pelanggaran tidak ditemukan pada master asrama.',
            'date.required' => 'Tanggal kejadian wajib diisi.',
            'date.date' => 'Format tanggal tidak dikenali. Contoh: 2026-09-25.',
            'status.in' => 'Status pelanggaran harus salah satu dari: open, actioned, closed.',
        ];
    }
}
