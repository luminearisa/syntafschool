<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pengajuan pendaftaran mandiri peserta umum.
 *
 * Endpoint ini publik (tidak butuh login): calon peserta mengisi biodata,
 * program pilihan, pengalaman menghafal, hafalan terakhir, kemampuan membaca,
 * target, dan preferensi jadwal. Admin kemudian mereview, menyelenggarakan
 * placement test, dan menyetujui.
 */
class TahfizRegistrationRequest extends FormRequest
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
            'gender' => ['nullable', 'string', 'in:male,female'],
            'birth_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:100'],
            'address' => ['nullable', 'string'],
            'program_id' => ['required', 'integer', 'exists:tahfiz_programs,id'],
            'experience_level' => ['nullable', 'string', 'in:beginner,intermediate,advanced'],
            'last_memorized' => ['nullable', 'string', 'max:150'],
            'reading_ability' => ['nullable', 'string', 'in:not_yet,learning,fluent,tahsin_completed'],
            'memorization_target' => ['nullable', 'string', 'max:150'],
            'schedule_preference' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'program_id' => 'program pilihan',
            'experience_level' => 'pengalaman menghafal',
            'last_memorized' => 'hafalan terakhir',
            'reading_ability' => 'kemampuan membaca Al-Quran',
            'memorization_target' => 'target hafalan',
            'schedule_preference' => 'preferensi jadwal',
        ];
    }

    public function messages(): array
    {
        return [
            'program_id.required' => 'Pilih program Tahfiz yang ingin diikuti.',
        ];
    }
}
