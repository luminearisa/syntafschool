<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajukan refund (§29) atas sebuah payment. Nominal positif dan alasan wajib;
 * akun kas/bank sumber dana opsional (default mengikuti payment asalnya).
 */
class FinanceRefundRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
            'cash_account_id' => ['nullable', 'integer', Rule::exists('finance_cash_accounts', 'id')->whereNull('deleted_at')],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('finance_bank_accounts', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'nominal refund',
            'reason' => 'alasan refund',
            'cash_account_id' => 'akun kas',
            'bank_account_id' => 'akun bank',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Nominal refund wajib diisi.',
            'amount.min' => 'Nominal refund harus lebih dari nol.',
            'reason.required' => 'Alasan refund wajib diisi.',
        ];
    }
}
