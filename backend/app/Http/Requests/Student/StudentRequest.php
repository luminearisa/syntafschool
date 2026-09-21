<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
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
        $studentId = $this->route('student')?->id;

        return [
            'school_id' => ['nullable', 'exists:schools,id'],
            'nis' => [
                'required',
                'string',
                'max:30',
                Rule::unique('students', 'nis')->ignore($studentId),
            ],
            'nisn' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('students', 'nisn')->ignore($studentId),
            ],
            'full_name' => ['required', 'string', 'max:150'],
            'nick_name' => ['nullable', 'string', 'max:50'],
            'gender_id' => ['nullable', 'exists:genders,id'],
            'religion_id' => ['nullable', 'exists:religions,id'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'blood_type' => ['nullable', Rule::in(['A', 'B', 'AB', 'O'])],
            'address' => ['nullable', 'string'],

            // Wilayah berjenjang — id berupa string sesuai kode BPS.
            'province_id' => ['nullable', 'string', 'max:2', 'exists:provinces,id'],
            'city_id' => ['nullable', 'string', 'max:4', 'exists:cities,id'],
            'district_id' => ['nullable', 'string', 'max:7', 'exists:districts,id'],
            'village_id' => ['nullable', 'string', 'max:10', 'exists:villages,id'],
            'postal_code' => ['nullable', 'string', 'max:10'],

            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'residence_type_id' => ['nullable', 'exists:residence_types,id'],
            'transportation_id' => ['nullable', 'exists:transportations,id'],
            'child_order' => ['nullable', 'integer', 'min:1', 'max:20'],
            'sibling_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'previous_school' => ['nullable', 'string', 'max:150'],
            'admission_date' => ['nullable', 'date'],
            'status_id' => ['nullable', 'exists:student_statuses,id'],
            'is_active' => ['boolean'],

            // Wali boleh dikirim sekalian saat menyimpan siswa.
            'guardians' => ['sometimes', 'array'],
            'guardians.*.guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'guardians.*.full_name' => ['nullable', 'string', 'max:150'],
            'guardians.*.guardian_relation_id' => ['nullable', 'exists:guardian_relations,id'],
            'guardians.*.is_primary' => ['boolean'],
            'guardians.*.phone' => ['nullable', 'string', 'max:20'],
            'guardians.*.email' => ['nullable', 'email', 'max:100'],
            'guardians.*.occupation_id' => ['nullable', 'exists:occupations,id'],
            'guardians.*.income_range_id' => ['nullable', 'exists:income_ranges,id'],
            'guardians.*.education_background_id' => ['nullable', 'exists:education_backgrounds,id'],
            'guardians.*.gender_id' => ['nullable', 'exists:genders,id'],
            'guardians.*.religion_id' => ['nullable', 'exists:religions,id'],
            'guardians.*.address' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'school_id' => 'sekolah',
            'nis' => 'NIS',
            'nisn' => 'NISN',
            'full_name' => 'nama lengkap',
            'nick_name' => 'nama panggilan',
            'gender_id' => 'jenis kelamin',
            'religion_id' => 'agama',
            'birth_place' => 'tempat lahir',
            'birth_date' => 'tanggal lahir',
            'blood_type' => 'golongan darah',
            'address' => 'alamat',
            'province_id' => 'provinsi',
            'city_id' => 'kabupaten/kota',
            'district_id' => 'kecamatan',
            'village_id' => 'desa/kelurahan',
            'postal_code' => 'kode pos',
            'phone' => 'telepon',
            'email' => 'email',
            'residence_type_id' => 'jenis tempat tinggal',
            'transportation_id' => 'transportasi',
            'child_order' => 'anak ke-',
            'sibling_count' => 'jumlah saudara',
            'previous_school' => 'asal sekolah',
            'admission_date' => 'tanggal masuk',
            'status_id' => 'status siswa',
            'is_active' => 'status aktif',
            'guardians' => 'data wali',
            'guardians.*.guardian_id' => 'wali',
            'guardians.*.full_name' => 'nama wali',
            'guardians.*.guardian_relation_id' => 'hubungan wali',
            'guardians.*.is_primary' => 'wali utama',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nis.unique' => 'NIS sudah dipakai siswa lain.',
            'nisn.unique' => 'NISN sudah dipakai siswa lain.',
            'blood_type.in' => 'Golongan darah harus salah satu dari A, B, AB, atau O.',
        ];
    }
}
