<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat transfer internal (§42). source/destination berupa pasangan type+id
 * (bank_account|cash_account); keberadaan akun konkret divalidasi service karena
 * tabelnya bergantung pada type. `submit=true` langsung mengajukan.
 * `idempotency_key` (§3) membuat permintaan ulang mengembalikan transfer yang
 * sudah ada alih-alih menambah transfer kedua; ia unik per pembuat.
 */
class FinanceTransferRequest extends FormRequest
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
        $sides = [FinanceTransfer::SIDE_BANK, FinanceTransfer::SIDE_CASH];

        return [
            'type' => ['nullable', Rule::in([FinanceTransfer::TYPE_BANK, FinanceTransfer::TYPE_CASH])],
            'source_type' => ['required', Rule::in($sides)],
            'source_id' => ['required', 'integer', 'min:1'],
            'destination_type' => ['required', Rule::in($sides)],
            'destination_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transfer_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'submit' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source_type' => 'tipe akun sumber',
            'source_id' => 'akun sumber',
            'destination_type' => 'tipe akun tujuan',
            'destination_id' => 'akun tujuan',
            'amount' => 'nominal transfer',
            'idempotency_key' => 'kunci idempotensi',
        ];
    }
}
