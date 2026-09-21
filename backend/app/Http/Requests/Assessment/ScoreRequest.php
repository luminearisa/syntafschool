<?php

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input nilai satu siswa.
 *
 * Batas atas nilai (skor maksimum penilaian) tidak divalidasi di sini karena
 * bergantung pada penilaian tujuan; itu diperiksa ScoreRecorder agar pesan
 * errornya memakai angka yang benar.
 */
class ScoreRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'score' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'siswa',
            'score' => 'nilai',
            'notes' => 'catatan',
            'reason' => 'alasan perubahan',
        ];
    }
}
