<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pembalikan jurnal posted (§34, §41, §51). Satu-satunya "edit" yang boleh
 * terjadi pada jurnal yang sudah diposting adalah membuat jurnal pembalik —
 * karena itu satu-satunya input yang dibutuhkan adalah alasan, bukan nominal
 * atau baris baru.
 */
class FinanceJournalReverseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan pembatalan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Alasan pembatalan minimal 10 karakter: jurnal pembalik harus menjelaskan kenapa (§34).',
        ];
    }
}
