<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Keanggotaan perpustakaan.
 *
 * Anggota harus berupa entitas yang sudah ada (siswa/pegawai/wali/pengguna)
 * **atau** memiliki identitas mandiri untuk anggota umum — biodata entitas
 * tertaut tidak diinput ulang (Rule 11 & §37).
 */
class LibraryMemberRequest extends FormRequest
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
            'library_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'exists:libraries,id'],
            'membership_type_id' => ['nullable', 'integer', 'exists:library_membership_types,id'],

            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],

            'name' => ['nullable', 'string', 'max:150'],
            'gender_id' => ['nullable', 'integer', 'exists:genders,id'],
            'birth_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string'],
            'identity_number' => ['nullable', 'string', 'max:50'],
            'photo_path' => ['nullable', 'string', 'max:255'],

            'registered_at' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['nullable', 'string', 'in:'.implode(',', LibraryMember::STATUSES)],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $linked = array_filter([
                    $this->input('user_id'),
                    $this->input('student_id'),
                    $this->input('employee_id'),
                    $this->input('guardian_id'),
                ]);

                $hasName = filled($this->input('name'))
                    || filled($this->route('member')?->name);

                // Anggota umum wajib punya nama karena tidak ada entitas sumber.
                if ($linked === [] && ! $hasName) {
                    $validator->errors()->add(
                        'name',
                        'Nama wajib diisi untuk anggota tanpa tautan siswa/pegawai/wali.'
                    );
                }

                // Hanya satu entitas induk yang boleh ditautkan.
                if (count($linked) > 1) {
                    $validator->errors()->add(
                        'student_id',
                        'Pilih salah satu saja: siswa, pegawai, wali, atau pengguna.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'library_id' => 'perpustakaan',
            'membership_type_id' => 'jenis keanggotaan',
            'student_id' => 'siswa',
            'employee_id' => 'pegawai',
            'guardian_id' => 'wali',
            'valid_from' => 'berlaku mulai',
            'valid_until' => 'berlaku sampai',
            'identity_number' => 'nomor identitas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'library_id.required' => 'Perpustakaan wajib dipilih.',
            'valid_until.after_or_equal' => 'Tanggal berakhir tidak boleh mendahului tanggal mulai.',
        ];
    }
}