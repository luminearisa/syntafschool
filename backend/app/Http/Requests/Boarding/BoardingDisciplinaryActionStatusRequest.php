<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingDisciplinaryAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Memutus antrean kerja (§38): pending → completed/cancelled. Service menuntut
 * `notes` saat menandai selesai bila belum ada catatan hasil.
 */
class BoardingDisciplinaryActionStatusRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:'.implode(',', BoardingDisciplinaryAction::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'officer' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status baru wajib dipilih.',
            'status.in' => 'Status tindakan harus salah satu dari: '.implode(', ', BoardingDisciplinaryAction::STATUSES).'.',
        ];
    }
}
