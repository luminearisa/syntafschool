<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat purchase order (§38) beserta barisnya. Bisa menaut ke PR yang sudah
 * disetujui (`purchase_request_id`).
 */
class FinancePurchaseOrderRequest extends FormRequest
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
            'purchase_request_id' => ['nullable', 'integer', Rule::exists('finance_purchase_requests', 'id')],
            'po_date' => ['nullable', 'date'],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'account_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'submit' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_name' => ['required', 'string', 'max:200'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vendor_id' => 'vendor',
            'items' => 'baris purchase order',
            'items.*.item_name' => 'nama barang',
        ];
    }
}
