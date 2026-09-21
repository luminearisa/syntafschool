<?php

namespace App\Http\Requests\Teaching;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penyelesaian jurnal mengajar — menandai jam pelajaran benar-benar terlaksana
 * dan mencatat realisasi JP.
 */
class CompleteJournalRequest extends FormRequest
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
            'realized_jp' => ['required', 'integer', 'min:1', 'max:99'],
            'student_present_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'activities' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'realized_jp' => 'JP terealisasi',
            'student_present_count' => 'jumlah siswa hadir',
            'activities' => 'kegiatan',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'realized_jp.required' => 'JP terealisasi wajib diisi.',
            'realized_jp.min' => 'JP terealisasi minimal 1 jam pelajaran.',
        ];
    }
}
