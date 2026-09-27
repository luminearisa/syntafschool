<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceCashTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Catat transaksi kas standalone (§2). `contra_account_id` wajib supaya jurnal
 * double-entry seimbang; akun lawan yang berupa rekening kas/bank lain ditolak
 * oleh service (itu transfer, punya workflow sendiri di §3).
 */
class FinanceCashTransactionRequest extends FormRequest
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
            'cash_account_id' => ['required', 'integer', Rule::exists('finance_cash_accounts', 'id')->whereNull('deleted_at')],
            'transaction_date' => ['nullable', 'date'],
            'type' => ['required', Rule::in(FinanceCashTransaction::TYPES)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'contra_account_id' => ['required', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'reference' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'cashier_session_id' => ['nullable', 'integer', Rule::exists('finance_cashier_sessions', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cash_account_id' => 'akun kas',
            'contra_account_id' => 'akun lawan',
            'amount' => 'nominal',
            'type' => 'tipe transaksi',
            'cashier_session_id' => 'sesi kasir',
        ];
    }
}
