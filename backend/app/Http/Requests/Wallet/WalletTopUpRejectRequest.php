<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penolakan pengajuan top up.
 *
 * Alasan WAJIB (§12): pemilik wallet menerima notifikasi berisi alasan ini dan tidak
 * boleh disuruh mengulang transfer tanpa tahu apa yang salah. Penolakan juga tidak
 * pernah menyentuh saldo, sehingga tidak ada keadaan setengah jadi yang perlu
 * dijelaskan ke siapa pun.
 */
class WalletTopUpRejectRequest extends FormRequest
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
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
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
