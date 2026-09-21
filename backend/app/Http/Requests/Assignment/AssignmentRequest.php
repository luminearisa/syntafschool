<?php

namespace App\Http\Requests\Assignment;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignmentRequest extends FormRequest
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
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'teacher_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where('is_teacher', true),
            ],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string'],
            'instructions' => ['nullable', 'string'],
            'type' => ['required', Rule::in(Assignment::TYPES)],
            // Skor maksimum harus lebih besar dari nol.
            'max_score' => ['required', 'numeric', 'gt:0', 'max:9999.99'],
            // Bobot terhadap nilai akhir: persen 0–100.
            'weight' => ['required', 'numeric', 'between:0,100'],
            'assigned_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:assigned_date'],
            'allow_late_submission' => ['boolean'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(Assignment::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'class_id' => 'kelas',
            'subject_id' => 'mata pelajaran',
            'teacher_id' => 'guru',
            'semester_id' => 'semester',
            'title' => 'judul tugas',
            'description' => 'deskripsi',
            'instructions' => 'instruksi',
            'type' => 'jenis tugas',
            'max_score' => 'nilai maksimum',
            'weight' => 'bobot',
            'assigned_date' => 'tanggal diberikan',
            'due_date' => 'batas pengumpulan',
            'allow_late_submission' => 'izin pengumpulan terlambat',
            'attachment_path' => 'lampiran',
            'status' => 'status',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'Batas pengumpulan tidak boleh lebih awal dari tanggal diberikan.',
            'max_score.gt' => 'Nilai maksimum harus lebih besar dari 0.',
            'weight.between' => 'Bobot harus berada di antara 0 dan 100.',
            'teacher_id.exists' => 'Guru harus pegawai yang berstatus pengajar.',
        ];
    }
}
