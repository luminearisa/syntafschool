<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Data jadwal berulang per jenis aktivitas.
 */
class TahfizScheduleRequest extends FormRequest
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
            'program_id' => ['nullable', 'integer', 'exists:tahfiz_programs,id'],
            'activity_type' => ['required', 'string', 'in:'.implode(',', \App\Models\TahfizSchedule::ACTIVITY_TYPES)],
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'instructor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'activity_type' => 'jenis aktivitas',
            'day_of_week' => 'hari',
            'start_time' => 'jam mulai',
            'end_time' => 'jam selesai',
            'instructor_id' => 'instruktur',
        ];
    }

    public function messages(): array
    {
        return [
            'end_time.after' => 'Jam selesai harus setelah jam mulai.',
            'day_of_week.min' => 'Hari tidak valid (0=Ahad … 6=Sabtu).',
        ];
    }
}
