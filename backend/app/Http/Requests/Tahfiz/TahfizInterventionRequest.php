<?php

namespace App\Http\Requests\Tahfiz;

use App\Models\TahfizIntervention;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Intervention untuk peserta yang tertinggal dari target.
 *
 * Jenis dan status intervention bersifat data (dari konstanta model), bukan
 * aturan yang ditulis keras di validasi — gunakan `TahfizIntervention::TYPES`
 * dan `::STATUSES` sebagai sumber kebenaran tunggal.
 */
class TahfizInterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'integer', 'exists:tahfiz_participants,id'],
            'enrollment_id' => ['nullable', 'integer', 'exists:tahfiz_enrollments,id'],
            'halaqah_id' => ['nullable', 'integer', 'exists:tahfiz_halaqahs,id'],
            'type' => ['required', 'string', 'in:'.implode(',', TahfizIntervention::TYPES)],
            'reason' => ['required', 'string', 'max:2000'],
            'action' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', 'in:'.implode(',', TahfizIntervention::STATUSES)],
            'due_date' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'result' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $status = $this->input('status');
                $completedAt = $this->input('completed_at');

                if ($status === TahfizIntervention::STATUS_DONE && empty($completedAt)) {
                    $validator->errors()->add(
                        'completed_at',
                        'Tanggal penyelesaian wajib diisi ketika status selesai.'
                    );
                }

                if ($completedAt && $this->input('status') === TahfizIntervention::STATUS_CANCELLED) {
                    $validator->errors()->add(
                        'completed_at',
                        'Intervention yang dibatalkan tidak boleh memiliki tanggal penyelesaian.'
                    );
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'participant_id' => 'peserta',
            'enrollment_id' => 'pendaftaran',
            'halaqah_id' => 'halaqah',
            'type' => 'jenis intervention',
            'reason' => 'alasan',
            'action' => 'tindakan',
            'status' => 'status',
            'due_date' => 'tenggat',
            'completed_at' => 'tanggal penyelesaian',
            'result' => 'hasil',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.required' => 'Peserta wajib dipilih.',
            'type.required' => 'Jenis intervention wajib dipilih.',
            'type.in' => 'Jenis intervention tidak valid.',
            'reason.required' => 'Alasan intervention wajib diisi.',
            'status.in' => 'Status intervention tidak valid.',
        ];
    }
}
