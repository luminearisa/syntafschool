<?php

namespace App\Http\Requests\AcademicCalendar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventTypeRequest extends FormRequest
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
        $eventType = $this->route('eventType');
        $eventTypeId = $eventType instanceof Model ? $eventType->getKey() : $eventType;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('event_types', 'code')->ignore($eventTypeId)],
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:10'],
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
            'code' => 'kode jenis event',
            'name' => 'nama jenis event',
            'color' => 'warna',
            'is_holiday' => 'penanda libur',
            'is_active' => 'status aktif',
        ];
    }
}
