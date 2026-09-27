<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingActivity;
use App\Models\BoardingAttendanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Absensi massal "Hadir Semua" (§24): satu permintaan membawa seluruh baris
 * roster; service melakukan upsert per (santri, kegiatan, tanggal, sesi).
 */
class BoardingAttendanceBulkRequest extends FormRequest
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
            'activity_id' => ['required', 'integer', Rule::exists(BoardingActivity::class, 'id')],
            'date' => ['nullable', 'date'],
            'session' => ['nullable', 'string', 'max:20'],
            'default_attendance_type_id' => ['nullable', 'integer', Rule::exists(BoardingAttendanceType::class, 'id')],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'items.*.attendance_type_id' => ['nullable', 'integer', Rule::exists(BoardingAttendanceType::class, 'id')],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'activity_id' => 'kegiatan',
            'items' => 'daftar santri',
            'items.*.student_id' => 'santri',
            'items.*.attendance_type_id' => 'status kehadiran',
        ];
    }
}
