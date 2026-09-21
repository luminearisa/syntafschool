<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
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
        $employeeId = $this->route('employee')?->id;

        return [
            'school_id' => ['nullable', 'exists:schools,id'],
            'nip' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('employees', 'nip')->ignore($employeeId),
            ],
            'full_name' => ['required', 'string', 'max:150'],
            'gender_id' => ['nullable', 'exists:genders,id'],
            'religion_id' => ['nullable', 'exists:religions,id'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'employee_type' => ['required', Rule::in(['guru', 'staf'])],
            'employment_status' => ['required', Rule::in(['PNS', 'PPPK', 'Honorer', 'GTY'])],
            'join_date' => ['nullable', 'date'],
            'is_teacher' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'school_id' => 'sekolah',
            'nip' => 'NIP',
            'full_name' => 'nama lengkap',
            'gender_id' => 'jenis kelamin',
            'religion_id' => 'agama',
            'birth_place' => 'tempat lahir',
            'birth_date' => 'tanggal lahir',
            'address' => 'alamat',
            'phone' => 'telepon',
            'email' => 'email',
            'position' => 'jabatan',
            'employee_type' => 'jenis pegawai',
            'employment_status' => 'status kepegawaian',
            'join_date' => 'tanggal masuk',
            'is_teacher' => 'status guru',
            'is_active' => 'status aktif',
        ];
    }
}
