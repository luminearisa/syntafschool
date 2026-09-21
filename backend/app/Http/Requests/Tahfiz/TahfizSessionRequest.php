<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Data sesi konkret: satu pertemuan pada satu tanggal.
 *
 * Sesi dapat dibuat manual atau di-generate dari jadwal berulang
 * (lihat `TahfizSessionController::generate`).
 */
class TahfizSessionRequest extends FormRequest
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
            'halaqah_id' => ['nullable', 'integer', 'exists:tahfiz_halaqahs,id'],
            'schedule_id' => ['nullable', 'integer', 'exists:tahfiz_schedules,id'],
            'activity_type' => ['required', 'string', 'in:'.implode(',', \App\Models\TahfizSchedule::ACTIVITY_TYPES)],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'instructor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'topic' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'in:planned,ongoing,done,cancelled'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'halaqah_id' => 'halaqah',
            'activity_type' => 'jenis aktivitas',
            'date' => 'tanggal',
            'instructor_id' => 'instruktur',
        ];
    }
}
