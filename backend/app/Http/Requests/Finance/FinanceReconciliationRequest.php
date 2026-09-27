<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buka rekonsiliasi bank (§44). `bank_balance` diisi dari rekening koran;
 * `system_balance` diambil service dari saldo buku rekening saat ini.
 */
class FinanceReconciliationRequest extends FormRequest
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
            'bank_account_id' => ['required', 'integer', Rule::exists('finance_bank_accounts', 'id')->whereNull('deleted_at')],
            'statement_date' => ['required', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'bank_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bank_account_id' => 'rekening bank',
            'statement_date' => 'tanggal rekening koran',
            'bank_balance' => 'saldo bank',
        ];
    }
}
