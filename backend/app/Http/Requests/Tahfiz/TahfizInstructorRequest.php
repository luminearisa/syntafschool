<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Data profil instruktur Tahfiz (guru/asisten/musyrif/musyrifah).
 */
class TahfizInstructorRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'role' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TahfizInstructor::ROLES)],
            'specialization' => ['nullable', 'array'],
            'specialization.*' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TahfizInstructor::SPECIALIZATIONS)],
            'certificate' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'pegawai',
            'role' => 'peran',
            'specialization' => 'kekhususan',
            'certificate' => 'sertifikat',
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.exists' => 'Pegawai yang dipilih tidak ditemukan.',
        ];
    }
}
