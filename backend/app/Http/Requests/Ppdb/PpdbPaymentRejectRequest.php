<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penolakan bukti pembayaran manual.
 *
 * Alasan wajib: orang tua menerima notifikasi berisi alasan ini dan tidak boleh
 * diminta mengunggah ulang tanpa tahu apa yang salah.
 */
class PpdbPaymentRejectRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('post')) {
            $this->merge([
                'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan penolakan wajib diisi.',
            'reason.min' => 'Alasan penolakan terlalu singkat, minimal 5 karakter.',
            'reason.max' => 'Alasan penolakan maksimal 500 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan penolakan',
        ];
    }
}
