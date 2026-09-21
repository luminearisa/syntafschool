<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class GuardianRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:150'],
            'gender_id' => ['nullable', 'exists:genders,id'],
            'religion_id' => ['nullable', 'exists:religions,id'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'occupation_id' => ['nullable', 'exists:occupations,id'],
            'income_range_id' => ['nullable', 'exists:income_ranges,id'],
            'education_background_id' => ['nullable', 'exists:education_backgrounds,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'nama lengkap',
            'gender_id' => 'jenis kelamin',
            'religion_id' => 'agama',
            'address' => 'alamat',
            'phone' => 'telepon',
            'email' => 'email',
            'occupation_id' => 'pekerjaan',
            'income_range_id' => 'rentang penghasilan',
            'education_background_id' => 'pendidikan terakhir',
        ];
    }
}
