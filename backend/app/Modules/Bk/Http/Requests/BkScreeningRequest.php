<?php

namespace App\Modules\Bk\Http\Requests;

use App\Modules\Bk\Domain\Enums\ScreeningOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi screening awal.
 *
 * Indikator akademik/kehadiran/perilaku/asrama boleh dikosongkan: layanan akan
 * mengambilnya sendiri dari modul terkait pada 90–180 hari terakhir.
 */
class BkScreeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('bk.screening.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'presenting_concern' => ['required', 'string', 'min:5', 'max:4000'],
            'observation' => ['nullable', 'string', 'max:4000'],
            'student_perspective' => ['nullable', 'string', 'max:4000'],
            'teacher_observation' => ['nullable', 'string', 'max:4000'],
            'parent_observation' => ['nullable', 'string', 'max:4000'],
            'academic_indicators' => ['nullable', 'array'],
            'attendance_indicators' => ['nullable', 'array'],
            'behavioral_indicators' => ['nullable', 'array'],
            'boarding_indicators' => ['nullable', 'array'],
            'previous_support' => ['nullable', 'string', 'max:2000'],
            'immediate_support_needed' => ['nullable', 'boolean'],
            'recommended_outcome' => ['required', Rule::in(ScreeningOutcome::values())],
            'recommendation_notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date'],
        ];
    }
}
