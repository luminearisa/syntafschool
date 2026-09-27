<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceVendorInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Terima invoice vendor (§35). Total = subtotal + tax. `account_id` (akun
 * beban/aset) wajib bila invoice langsung berstatus `received` agar hutang
 * bisa diakui (Dr beban/aset, Cr Hutang 2100).
 */
class FinanceVendorInvoiceRequest extends FormRequest
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
            'vendor_id' => ['required', 'integer', Rule::exists('finance_vendors', 'id')->whereNull('deleted_at')],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'account_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('finance_purchase_orders', 'id')],
            'expense_request_id' => ['nullable', 'integer', Rule::exists('finance_expense_requests', 'id')],
            'notes' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in([FinanceVendorInvoice::STATUS_DRAFT, FinanceVendorInvoice::STATUS_RECEIVED])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vendor_id' => 'vendor',
            'subtotal' => 'subtotal',
            'account_id' => 'akun tujuan',
        ];
    }
}
