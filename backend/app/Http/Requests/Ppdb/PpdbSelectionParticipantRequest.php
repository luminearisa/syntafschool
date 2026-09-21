<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pendaftar peserta seleksi.
 *
 * Sengaja tidak memakai rule `exists`: id yang tidak dikenal atau pendaftaran
 * yang belum lolos verifikasi harus tetap dikirim ke service agar dilaporkan
 * sebagai baris yang dilewati (`skipped`), bukan membuat seluruh permintaan
 * gagal berstatus 422.
 */
class PpdbSelectionParticipantRequest extends FormRequest
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
            'registration_ids' => ['required', 'array', 'min:1', 'max:500'],
            'registration_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'registration_ids.required' => 'Pilih minimal satu pendaftaran untuk dijadikan peserta.',
            'registration_ids.array' => 'Daftar pendaftaran harus berupa array.',
            'registration_ids.max' => 'Maksimal 500 pendaftaran didaftarkan sekaligus.',
            'registration_ids.*.integer' => 'Setiap pendaftaran harus berupa id numerik.',
        ];
    }

    /**
     * @return list<int>
     */
    public function registrationIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('registration_ids', [])));
    }
}
