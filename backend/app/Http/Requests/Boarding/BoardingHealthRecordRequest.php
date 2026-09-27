<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingHealthRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Catatan kesehatan asrama (§39) — gejala singkat + tindakan, bukan rekam
 * medis. `condition` pendek (100 karakter sesuai kolom); uraian panjang lewat
 * `complaint`/`notes`.
 */
class BoardingHealthRecordRequest extends FormRequest
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
            'condition' => [$required, 'string', 'max:100'],
            'complaint' => ['nullable', 'string', 'max:2000'],
            'action' => ['nullable', 'string', 'max:2000'],
            'date' => [$required, 'date'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', BoardingHealthRecord::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'santri',
            'condition' => 'kondisi/gejala',
            'complaint' => 'keluhan',
            'action' => 'tindakan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Santri wajib dipilih.',
            'student_id.exists' => 'Santri tidak ditemukan pada data siswa sekolah ini.',
            'condition.required' => 'Kondisi/gejala wajib diisi — kolom intinya catatan kesehatan (§39).',
            'condition.max' => 'Ringkas saja: kondisi maksimal :max karakter. Uraian panjang tulis di keluhan.',
            'date.required' => 'Tanggal observation wajib diisi.',
            'status.in' => 'Status kesehatan harus salah satu dari: '.implode(', ', BoardingHealthRecord::STATUSES).'.',
        ];
    }
}
