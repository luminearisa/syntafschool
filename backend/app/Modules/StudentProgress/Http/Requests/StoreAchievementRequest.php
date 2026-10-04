<?php

namespace App\Modules\StudentProgress\Http\Requests;

use App\Modules\StudentProgress\Models\StudentAchievement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAchievementRequest extends FormRequest
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
            'achievement_type_id' => ['required', 'integer', 'min:1', 'exists:achievement_types,id'],
            'title' => ['required', 'string', 'max:150'],
            'date' => ['nullable', 'date'],
            'level' => ['nullable', Rule::in(StudentAchievement::LEVELS)],
            'rank' => ['nullable', 'string', 'max:50'],
            'organizer' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'certificate_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'siswa',
            'achievement_type_id' => 'jenis prestasi',
            'title' => 'judul prestasi',
            'date' => 'tanggal prestasi',
            'level' => 'tingkat',
            'rank' => 'peringkat',
            'organizer' => 'penyelenggara',
            'description' => 'keterangan',
            'certificate_path' => 'berkas sertifikat',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Siswa yang berprestasi wajib dipilih.',
            'student_id.exists' => 'Siswa tidak ditemukan.',
            'achievement_type_id.required' => 'Jenis prestasi wajib dipilih.',
            'achievement_type_id.exists' => 'Jenis prestasi tidak ditemukan pada master.',
            'title.required' => 'Judul prestasi wajib diisi.',
            'level.in' => 'Tingkat prestasi harus salah satu dari: '.implode(', ', StudentAchievement::LEVELS).'.',
        ];
    }
}
