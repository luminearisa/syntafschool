<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi presensi siswa satuan.
 *
 * Aturan lintas-tabel (siswa anggota kelas, tanggal di dalam semester, hari
 * efektif, presensi ganda) diperiksa di controller agar pesan validasi
 * mengarah ke field yang tepat.
 */
class StudentAttendanceRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'schedule_id' => ['nullable', 'integer', 'exists:schedules,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'date' => ['required', 'date'],
            'attendance_status_id' => ['required', 'integer', 'exists:attendance_statuses,id'],
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'time_in' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
            // `status` (recorded/verified/locked) SENGAJA tidak dapat diisi klien:
            // nilainya hanya berubah lewat alur koreksi/persetujuan, supaya guru
            // tidak bisa menandai presensinya sendiri "verified"/"locked" lewat
            // payload lalu menghindari proses koreksi.
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'siswa',
            'class_id' => 'kelas',
            'semester_id' => 'semester',
            'schedule_id' => 'jadwal',
            'subject_id' => 'mata pelajaran',
            'date' => 'tanggal',
            'attendance_status_id' => 'status presensi',
            'leave_type_id' => 'jenis izin',
            'time_in' => 'jam masuk',
            'notes' => 'catatan',
            'status' => 'status data',
        ];
    }
}
