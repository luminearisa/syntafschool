<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat purchase request (§37) beserta barisnya.
 */
class FinancePurchaseRequestRequest extends FormRequest
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
            'cost_center_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'needed_date' => ['nullable', 'date'],
            'submit' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_name' => ['required', 'string', 'max:200'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.estimated_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.account_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => 'baris purchase request',
            'items.*.item_name' => 'nama barang',
        ];
    }
}
