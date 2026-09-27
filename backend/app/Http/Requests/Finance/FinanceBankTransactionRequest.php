<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceBankTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Catat transaksi bank standalone (§41). `contra_account_id` wajib agar jurnal
 * double-entry seimbang (Dr/Cr bank vs contra). Tipe transfer ditolak — kaki
 * transfer dibuat oleh FinanceTransferService.
 */
class FinanceBankTransactionRequest extends FormRequest
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
            'transaction_date' => ['nullable', 'date'],
            'type' => ['required', Rule::in([
                FinanceBankTransaction::TYPE_DEPOSIT,
                FinanceBankTransaction::TYPE_WITHDRAWAL,
                FinanceBankTransaction::TYPE_FEE,
            ])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'contra_account_id' => ['required', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'reference' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bank_account_id' => 'rekening bank',
            'contra_account_id' => 'akun lawan',
            'amount' => 'nominal',
            'type' => 'tipe transaksi',
        ];
    }
}
