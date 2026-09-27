<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buka sesi kasir (§22). Modal awal opsional (default 0); akun kas wajib dan
 * harus akun kas aktif yang tidak terhapus.
 */
class FinanceCashierSessionRequest extends FormRequest
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
            'opening_cash' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cash_account_id' => 'akun kas',
            'opening_cash' => 'modal awal',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cash_account_id.required' => 'Akun kas untuk sesi kasir wajib dipilih.',
            'opening_cash.min' => 'Modal awal tidak boleh negatif.',
        ];
    }
}
