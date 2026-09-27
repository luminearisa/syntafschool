<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingActivity;
use App\Models\BoardingActivitySchedule;
use App\Models\BoardingDormitory;
use App\Models\BoardingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Jadwal mingguan satu kegiatan (§19): global, per asrama, atau per kelompok.
 */
class BoardingActivityScheduleRequest extends FormRequest
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
            'activity_id' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'integer',
                Rule::exists(BoardingActivity::class, 'id'),
            ],
            'dormitory_id' => ['nullable', 'integer', Rule::exists(BoardingDormitory::class, 'id')],
            'group_id' => ['nullable', 'integer', Rule::exists(BoardingGroup::class, 'id')],
            'day_of_week' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'integer',
                Rule::in(BoardingActivitySchedule::DAYS),
            ],
            'start_time' => [$this->isMethod('post') ? 'required' : 'nullable', 'date_format:H:i'],
            'end_time' => [$this->isMethod('post') ? 'required' : 'nullable', 'date_format:H:i', 'after:start_time'],
            'effective_start' => ['nullable', 'date'],
            'effective_end' => ['nullable', 'date', 'after_or_equal:effective_start'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'activity_id' => 'kegiatan',
            'dormitory_id' => 'asrama',
            'group_id' => 'kelompok',
            'day_of_week' => 'hari',
            'start_time' => 'jam mulai',
            'end_time' => 'jam selesai',
            'effective_start' => 'berlaku sejak',
            'effective_end' => 'berlaku sampai',
        ];
    }
}
