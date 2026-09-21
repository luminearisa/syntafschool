<?php

namespace App\Http\Requests\Teaching;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
/**
 * Validasi RPP / modul ajar.
 *
 * `teacher_id` boleh dikosongkan oleh guru — controller akan mengisinya dengan
 * pegawai yang tertaut ke akun pemanggil.
 */
class LessonPlanRequest extends FormRequest
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
            'teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where('is_teacher', true),
            ],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'code' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:200'],
            'learning_objectives' => ['nullable', 'array'],
            'learning_objectives.*' => ['string', 'max:500'],
            'activities' => ['nullable', 'array'],
            'activities.*' => ['string', 'max:500'],
            'assessment_plan' => ['nullable', 'string'],
            'media' => ['nullable', 'string'],
            'references' => ['nullable', 'string'],
            'duration_jp' => ['required', 'integer', 'min:1', 'max:99'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'teacher_id' => 'guru penyusun',
            'subject_id' => 'mata pelajaran',
            'education_level_id' => 'jenjang',
            'class_id' => 'kelas',
            'semester_id' => 'semester',
            'code' => 'kode RPP',
            'title' => 'judul RPP',
            'learning_objectives' => 'tujuan pembelajaran',
            'activities' => 'kegiatan pembelajaran',
            'assessment_plan' => 'rencana penilaian',
            'media' => 'media pembelajaran',
            'references' => 'sumber belajar',
            'duration_jp' => 'durasi JP',
            'attachment_path' => 'lampiran',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'teacher_id.exists' => 'Guru penyusun harus pegawai yang berstatus pengajar.',
            'duration_jp.min' => 'Durasi JP minimal 1 jam pelajaran.',
        ];
    }
}
