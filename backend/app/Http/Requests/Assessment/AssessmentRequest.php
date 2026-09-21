<?php

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi pembuatan/perubahan sebuah penilaian (A7).
 *
 * Status tidak boleh diubah lewat field ini — transisi nilai punya aturan
 * sendiri (submit, verifikasi, finalisasi, buka kembali) dan tercatat di log.
 */
class AssessmentRequest extends FormRequest
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
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'assessment_type_id' => ['required', 'integer', 'exists:assessment_types,id'],
            'teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where('is_teacher', true),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'max_score' => ['nullable', 'numeric', 'gt:0', 'max:9999.99'],
            // Bobot khusus ini menimpa bobot jenis penilaian bila diisi.
            'weight' => ['nullable', 'numeric', 'between:0,100'],
            'date' => ['nullable', 'date'],
            'grading_formula_id' => ['nullable', 'integer', 'exists:grading_formulas,id'],
            'extra' => ['nullable', 'array'],
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
            'semester_id' => 'semester',
            'assessment_type_id' => 'jenis penilaian',
            'teacher_id' => 'guru',
            'name' => 'nama penilaian',
            'description' => 'deskripsi',
            'max_score' => 'nilai maksimum',
            'weight' => 'bobot',
            'date' => 'tanggal penilaian',
            'grading_formula_id' => 'formula penilaian',
            'extra' => 'metadata tambahan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_score.gt' => 'Nilai maksimum harus lebih besar dari 0.',
            'weight.between' => 'Bobot harus berada di antara 0 dan 100.',
            'teacher_id.exists' => 'Guru harus pegawai yang berstatus pengajar.',
        ];
    }

    /**
     * Data siap simpan: nama kolom API dipetakan ke nama kolom tabel.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('class_id', $data)) {
            $data['school_class_id'] = $data['class_id'];
            unset($data['class_id']);
        }

        if (array_key_exists('teacher_id', $data)) {
            $data['employee_id'] = $data['teacher_id'];
            unset($data['teacher_id']);
        }

        return $data;
    }
}
