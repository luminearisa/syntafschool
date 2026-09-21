<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input presensi massal satu kelas pada satu tanggal.
 *
 * Bersifat upsert: entri yang sudah ada diperbarui, yang belum dibuat.
 */
class BulkAttendanceRequest extends FormRequest
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
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'schedule_id' => ['nullable', 'integer', 'exists:schedules,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'date' => ['required', 'date'],

            'entries' => ['required', 'array', 'min:1'],
            'entries.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'entries.*.attendance_status_id' => ['required', 'integer', 'exists:attendance_statuses,id'],
            'entries.*.leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'entries.*.time_in' => ['nullable', 'date_format:H:i'],
            'entries.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'class_id' => 'kelas',
            'semester_id' => 'semester',
            'schedule_id' => 'jadwal',
            'subject_id' => 'mata pelajaran',
            'date' => 'tanggal',
            'entries' => 'daftar presensi',
            'entries.*.student_id' => 'siswa',
            'entries.*.attendance_status_id' => 'status presensi',
            'entries.*.leave_type_id' => 'jenis izin',
            'entries.*.time_in' => 'jam masuk',
            'entries.*.notes' => 'catatan',
        ];
    }
}
