<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Konversi calon menjadi siswa.
 *
 * Semua field bersifat opsional: NIS dibangkitkan oleh penomoran bila dikosongkan,
 * dan kelas hanya dimasukkan bila rombelnya benar-benar cocok dengan tahun ajaran
 * terpilih (lihat `PpdbConversionService::enrollInClass()`).
 */
class PpdbConvertToStudentRequest extends FormRequest
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
            'nis' => ['nullable', 'string', 'max:30'],
            'school_id' => ['nullable', 'integer', 'exists:schools,id'],
            'status_id' => ['nullable', 'integer', 'exists:student_statuses,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nis.max' => 'NIS maksimal 30 karakter.',
            'school_id.exists' => 'Sekolah terpilih tidak ada.',
            'status_id.exists' => 'Status siswa terpilih tidak ada di master data.',
            'school_class_id.exists' => 'Rombel terpilih tidak ada.',
            'academic_year_id.exists' => 'Tahun ajaran terpilih tidak ada.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nis' => 'NIS',
            'school_id' => 'sekolah',
            'status_id' => 'status siswa',
            'school_class_id' => 'rombel',
            'academic_year_id' => 'tahun ajaran',
        ];
    }
}
