<?php

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input nilai massal untuk satu penilaian (bulk grading).
 *
 * Bersifat upsert: baris yang sudah ada diperbarui, yang belum dibuat. Guru
 * boleh menekan "Simpan" dua kali tanpa menghasilkan data ganda, dan boleh
 * menyimpan sebagian (nilai `null` berarti belum dinilai).
 */
class BulkScoreRequest extends FormRequest
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
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.student_id' => ['required', 'integer'],
            'entries.*.score' => ['nullable', 'numeric', 'min:0'],
            'entries.*.notes' => ['nullable', 'string', 'max:500'],
            // Alasan dipakai bila penilaian sudah diserahkan (status bukan draft).
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'entries' => 'daftar nilai',
            'entries.*.student_id' => 'siswa',
            'entries.*.score' => 'nilai',
            'entries.*.notes' => 'catatan',
            'reason' => 'alasan perubahan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entries.required' => 'Daftar nilai wajib diisi.',
            'entries.min' => 'Daftar nilai tidak boleh kosong.',
        ];
    }
}
