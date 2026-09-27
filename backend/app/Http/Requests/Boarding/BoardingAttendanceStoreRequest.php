<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingActivity;
use App\Models\BoardingAttendanceType;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Catat satu absensi manual (§17, §24). Aturan bisnis (tipe diizinkan untuk
 * kegiatan, note wajib, santri ditempatkan, duplikat) ada di service.
 */
class BoardingAttendanceStoreRequest extends FormRequest
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
            'student_id' => ['required', 'integer', Rule::exists(Student::class, 'id')],
            'activity_id' => ['required', 'integer', Rule::exists(BoardingActivity::class, 'id')],
            'attendance_type_id' => ['required', 'integer', Rule::exists(BoardingAttendanceType::class, 'id')],
            'date' => ['nullable', 'date'],
            'session' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'santri',
            'activity_id' => 'kegiatan',
            'attendance_type_id' => 'status kehadiran',
            'date' => 'tanggal',
            'session' => 'sesi',
            'note' => 'catatan',
        ];
    }
}
