<?php

namespace App\Modules\StudentProgress\Http\Requests;

use App\Modules\StudentProgress\Models\StudentAchievement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAchievementRequest extends FormRequest
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
            'student_id' => ['sometimes', 'integer', 'min:1', 'exists:students,id'],
            'achievement_type_id' => ['sometimes', 'integer', 'min:1', 'exists:achievement_types,id'],
            'title' => ['sometimes', 'string', 'max:150'],
            'date' => ['sometimes', 'nullable', 'date'],
            'level' => ['sometimes', 'nullable', Rule::in(StudentAchievement::LEVELS)],
            'rank' => ['sometimes', 'nullable', 'string', 'max:50'],
            'organizer' => ['sometimes', 'nullable', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'certificate_path' => ['sometimes', 'nullable', 'string', 'max:255'],
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
}
