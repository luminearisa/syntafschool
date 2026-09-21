<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Profil orang tua pada portal.
 *
 * Nama/email/telepon ditulis ke `users`, detail identitas ke `guardians` —
 * tidak ada kolom identitas pada `ppdb_parent_accounts`.
 */
class PpdbProfileRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => [
                'sometimes', 'required', 'string', 'email:rfc', 'max:150',
                Rule::unique('users', 'email')->ignore($this->user()?->id)->whereNull('deleted_at'),
            ],
            'phone' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[0-9+\-\s]{6,20}$/'],
            'gender_id' => ['nullable', 'integer', 'exists:genders,id'],
            'religion_id' => ['nullable', 'integer', 'exists:religions,id'],
            'occupation_id' => ['nullable', 'integer', 'exists:occupations,id'],
            'education_background_id' => ['nullable', 'integer', 'exists:education_backgrounds,id'],
            'income_range_id' => ['nullable', 'integer', 'exists:income_ranges,id'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'email',
            'phone' => 'nomor telepon',
            'address' => 'alamat',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Email ini sudah dipakai akun lain.',
            'phone.regex' => 'Format nomor telepon tidak valid.',
        ];
    }
}
