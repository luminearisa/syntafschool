<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah baris rekening koran ke rekonsiliasi (§44). Bila `bank_transaction_id`
 * diisi, baris dicap cocok (matched) ke transaksi sistem.
 */
class FinanceReconciliationItemRequest extends FormRequest
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
            'transaction_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
            'bank_amount' => ['required', 'numeric'],
            'bank_transaction_id' => ['nullable', 'integer', Rule::exists('finance_bank_transactions', 'id')],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bank_amount' => 'nominal bank',
        ];
    }
}
