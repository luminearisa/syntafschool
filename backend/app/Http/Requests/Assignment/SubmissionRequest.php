<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pengumpulan / pembaruan pengumpulan tugas oleh siswa.
 *
 * `student_id` hanya wajib pada saat membuat pengumpulan (POST). Pada
 * pembaruan (PUT) siswa sudah ditentukan lewat pengumpulan yang dituju.
 */
class SubmissionRequest extends FormRequest
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
        $isCreate = $this->isMethod('post');

        return [
            'student_id' => $isCreate
                ? ['required', 'integer', 'exists:students,id']
                : ['sometimes', 'integer', 'exists:students,id'],
            'submitted_at' => ['nullable', 'date'],
            'content' => ['nullable', 'string'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'siswa',
            'submitted_at' => 'waktu pengumpulan',
            'content' => 'isi pengumpulan',
            'attachment_path' => 'lampiran',
        ];
    }
}
