<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Transisi status enrollment.
 *
 * Penolakan, penundaan, dan penyelesaian wajib disertai alasan agar perubahan
 * tetap bisa diaudit.
 */
class TahfizEnrollmentTransitionRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:'.implode(',', \App\Models\TahfizEnrollment::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
            'level_id' => ['nullable', 'integer', 'exists:tahfiz_levels,id'],
            'halaqah_id' => ['nullable', 'integer', 'exists:tahfiz_halaqahs,id'],
            'completion_note' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'status',
            'reason' => 'alasan',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan wajib diisi untuk perubahan status ini.',
        ];
    }

    /**
     * Status keluar/penolakan memerlukan alasan (aturan bisnis, bukan sekadar
     * validasi format — karena itu ditegakkan di service).
     */
    public function requiresReason(): bool
    {
        return in_array($this->input('status'), [
            \App\Models\TahfizEnrollment::STATUS_SUSPENDED,
            \App\Models\TahfizEnrollment::STATUS_WITHDRAWN,
            \App\Models\TahfizEnrollment::STATUS_REJECTED,
            \App\Models\TahfizEnrollment::STATUS_COMPLETED,
        ], true);
    }
}
