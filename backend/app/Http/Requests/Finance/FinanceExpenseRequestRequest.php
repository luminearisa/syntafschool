<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat pengajuan pengeluaran (§30). Akun tujuan harus akun beban; cost center
 * wajib. Nominal positif. `submit=true` langsung mengajukan untuk approval.
 */
class FinanceExpenseRequestRequest extends FormRequest
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
            'cost_center_id' => ['required', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'account_id' => ['required', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:500'],
            'expense_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('finance_vendors', 'id')->whereNull('deleted_at')],
            'payee_name' => ['nullable', 'string', 'max:150'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'submit' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cost_center_id' => 'cost center',
            'account_id' => 'akun pengeluaran',
            'amount' => 'nominal',
            'description' => 'keterangan',
        ];
    }
}
