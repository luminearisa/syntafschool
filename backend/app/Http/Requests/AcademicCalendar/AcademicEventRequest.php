<?php

namespace App\Http\Requests\AcademicCalendar;

use Illuminate\Foundation\Http\FormRequest;

class AcademicEventRequest extends FormRequest
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
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'event_type_id' => ['required', 'integer', 'exists:event_types,id'],
            'title' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:150'],
            'is_holiday' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'semester_id' => 'semester',
            'academic_year_id' => 'tahun ajaran',
            'event_type_id' => 'jenis event',
            'title' => 'judul event',
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal selesai',
            'description' => 'keterangan',
            'location' => 'lokasi',
            'is_holiday' => 'penanda libur',
            'is_active' => 'status aktif',
        ];
    }
}
