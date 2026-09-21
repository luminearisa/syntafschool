<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pengajuan koreksi presensi siswa.
 */
class AttendanceCorrectionRequest extends FormRequest
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
            'student_attendance_id' => ['required', 'integer', 'exists:student_attendances,id'],
            'new_status_id' => ['required', 'integer', 'exists:attendance_statuses,id'],
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_attendance_id' => 'presensi',
            'new_status_id' => 'status baru',
            'leave_type_id' => 'jenis izin',
            'reason' => 'alasan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan koreksi wajib diisi agar perubahan bisa diaudit.',
        ];
    }
}
