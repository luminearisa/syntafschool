<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Buat pendaftaran baru: calon + periode + program + gelombang.
 *
 * Keabsahan scope (calon milik pemanggil, periode dibuka, gelombang aktif,
 * program thuộc periode, satu pendaftaran per calon per periode) diperiksa di
 * controller karena butuh data relasi.
 */
class PpdbRegistrationStoreRequest extends FormRequest
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
            'applicant_id' => ['required', 'integer', 'exists:ppdb_applicants,id'],
            'period_id' => ['required', 'integer', 'exists:ppdb_periods,id'],
            'program_id' => ['required', 'integer', 'exists:ppdb_programs,id'],
            'wave_id' => ['nullable', 'integer', 'exists:ppdb_waves,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'applicant_id' => 'calon peserta didik',
            'period_id' => 'periode PPDB',
            'program_id' => 'program yang dipilih',
            'wave_id' => 'gelombang',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'applicant_id.required' => 'Pilih calon (anak) yang akan didaftarkan.',
            'applicant_id.exists' => 'Calon tersebut tidak ditemukan.',
            'period_id.required' => 'Pilih periode PPDB.',
            'period_id.exists' => 'Periode PPDB tidak ditemukan.',
            'program_id.required' => 'Pilih program yang dituju.',
            'program_id.exists' => 'Program tidak ditemukan.',
            'wave_id.exists' => 'Gelombang tidak ditemukan.',
        ];
    }
}
