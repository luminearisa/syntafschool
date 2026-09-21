<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi presensi pegawai.
 */
class EmployeeAttendanceRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'attendance_status_id' => ['required', 'integer', 'exists:attendance_statuses,id'],
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'pegawai',
            'date' => 'tanggal',
            'check_in' => 'jam masuk',
            'check_out' => 'jam pulang',
            'attendance_status_id' => 'status presensi',
            'leave_type_id' => 'jenis izin',
            'notes' => 'catatan',
        ];
    }
}
