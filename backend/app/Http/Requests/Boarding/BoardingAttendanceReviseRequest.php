<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingAttendanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Koreksi satu catatan absensi (§26): nilai boleh berubah, baris lama tidak
 * pernah dihapus — `revision` dan audit yang berbicara.
 */
class BoardingAttendanceReviseRequest extends FormRequest
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
            'attendance_type_id' => ['required', 'integer', Rule::exists(BoardingAttendanceType::class, 'id')],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'attendance_type_id' => 'status kehadiran baru',
            'note' => 'catatan',
            'reason' => 'alasan koreksi',
        ];
    }
}
