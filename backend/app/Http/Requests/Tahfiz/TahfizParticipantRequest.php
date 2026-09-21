<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Data peserta Tahfiz.
 *
 * Peserta terikat ke entitas induk (siswa/pegawai/wali) atau mandiri
 * (umum/alumni/lainnya). Aturannya: tepat satu tautan yang dibutuhkan sesuai
 * jenis peserta, dan identitas mandiri wajib diisi bila tidak ada induk.
 */
class TahfizParticipantRequest extends FormRequest
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
            'participant_type' => ['required', 'string', 'in:'.implode(',', \App\Models\TahfizParticipant::TYPES)],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'birth_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:100'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TahfizParticipant::STATUSES)],

            // Profil tambahan (opsional, untuk peserta umum/alumni).
            'profile' => ['nullable', 'array'],
            'profile.experience_level' => ['nullable', 'string', 'in:beginner,intermediate,advanced'],
            'profile.last_memorized' => ['nullable', 'string', 'max:150'],
            'profile.reading_ability' => ['nullable', 'string', 'in:not_yet,learning,fluent,tahsin_completed'],
            'profile.memorization_target' => ['nullable', 'string', 'max:150'],
            'profile.schedule_preference' => ['nullable', 'string', 'max:150'],
            'profile.education_background' => ['nullable', 'string', 'max:100'],
            'profile.occupation' => ['nullable', 'string', 'max:100'],
            'profile.emergency_contact' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'participant_type' => 'jenis peserta',
            'student_id' => 'siswa',
            'employee_id' => 'pegawai',
            'guardian_id' => 'wali',
            'birth_date' => 'tanggal lahir',
            'profile.experience_level' => 'pengalaman menghafal',
            'profile.reading_ability' => 'kemampuan membaca',
        ];
    }

    /**
     * Konsistensi tautan entitas induk dengan jenis peserta.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $type = $this->input('participant_type');
                $linked = array_filter([
                    'student' => $this->input('student_id'),
                    'employee' => $this->input('employee_id'),
                    'guardian' => $this->input('guardian_id'),
                ]);

                if (in_array($type, \App\Models\TahfizParticipant::SELF_MANAGED_TYPES, true)) {
                    if (! empty($linked)) {
                        $validator->errors()->add(
                            'participant_type',
                            'Peserta jenis ini tidak terikat ke siswa, pegawai, atau wali.'
                        );
                    }

                    if (! $this->filled('name')) {
                        $validator->errors()->add('name', 'Nama wajib diisi untuk peserta jenis ini.');
                    }

                    return;
                }

                $expected = $type;
                if (empty($linked[$expected] ?? null)) {
                    $validator->errors()->add(
                        $expected.'_id',
                        "Peserta jenis {$type} wajib menautkan {$expected}."
                    );
                }

                $others = array_diff_key($linked, [$expected => true]);
                foreach (array_keys($others) as $other) {
                    $validator->errors()->add(
                        $other.'_id',
                        "Peserta jenis {$type} hanya boleh menautkan {$expected}."
                    );
                }
            },
        ];
    }
}
