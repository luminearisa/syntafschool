<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingAchievement;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Prestasi santri (§41). `level` boleh kosong (prestasi kamar tidak punya
 * jenjang); kalau diisi harus salah satu jenjang yang dikenal agar laporan
 * bisa mengurutkannya.
 */
class BoardingAchievementRequest extends FormRequest
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
            'title' => [$required, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => [$required, 'string', 'in:'.implode(',', BoardingAchievement::CATEGORIES)],
            'level' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', BoardingAchievement::LEVELS)],
            'date' => [$required, 'date'],
            'evidence_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'santri',
            'title' => 'nama prestasi',
            'category' => 'kategori',
            'level' => 'jenjang',
            'date' => 'tanggal',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Santri berprestasi wajib dipilih.',
            'student_id.exists' => 'Santri tidak ditemukan pada data siswa sekolah ini.',
            'title.required' => 'Nama prestasi wajib diisi.',
            'category.required' => 'Kategori prestasi wajib dipilih (§41).',
            'category.in' => 'Kategori harus salah satu dari: '.implode(', ', BoardingAchievement::CATEGORIES).'.',
            'level.in' => 'Jenjang harus salah satu dari: '.implode(', ', BoardingAchievement::LEVELS).', atau kosongkan.',
            'date.required' => 'Tanggal prestasi wajib diisi.',
        ];
    }
}
