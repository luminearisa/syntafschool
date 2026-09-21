<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Permintaan transisi status pendaftaran oleh panitia.
 *
 * Hanya status yang dikenal yang diterima; KESAHAN perpindahannya diperiksa
 * `PpdbRegistrationStateMachine` sesuai `PpdbRegistration::TRANSITIONS`.
 */
class PpdbRegistrationTransitionRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(PpdbRegistration::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
            'notify' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'status tujuan',
            'note' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status tujuan wajib dipilih.',
            'status.in' => 'Status tujuan tidak dikenal pada alur pendaftaran.',
        ];
    }
}
