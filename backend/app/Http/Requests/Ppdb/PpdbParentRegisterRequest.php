<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pendaftaran akun orang tua/wali mandiri (publik, di-throttle oleh rute).
 *
 * Identitas orang tua dibuat menjadi satu baris `users` + satu baris
 * `guardians`; `ppdb_parent_accounts` hanya menautkan keduanya.
 */
class PpdbParentRegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s]{6,20}$/'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
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
    public function messages(): array
    {
        return [
            'name.required' => 'Nama lengkap wajib diisi',
            'email.required' => 'Email wajib diisi',
            'email.email' => 'Format email tidak valid',
            'email.unique' => 'Email ini sudah terdaftar. Silakan masuk atau gunakan email lain.',
            'phone.required' => 'Nomor telepon wajib diisi',
            'phone.regex' => 'Format nomor telepon tidak valid',
            'password.required' => 'Password wajib diisi',
            'password.min' => 'Password minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi password tidak sama',
        ];
    }
}
