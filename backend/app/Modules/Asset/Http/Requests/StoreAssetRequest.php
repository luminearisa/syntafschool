<?php

namespace App\Modules\Asset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('asset.register') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'asset_type_id' => ['nullable', 'integer', 'exists:asset_types,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'custodian_id' => ['nullable', 'integer', 'exists:users,id'],
            'custodian_type' => ['nullable', 'string', 'max:24'],
            'asset_code' => ['nullable', 'string', 'max:80', 'unique:assets,asset_code'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'barcode' => ['nullable', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'acquisition_source' => ['nullable', 'string', 'max:24'],
            'purchase_date' => ['nullable', 'date'],
            'received_date' => ['nullable', 'date'],
            'commission_date' => ['nullable', 'date'],
            'capitalization_date' => ['nullable', 'date'],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'depreciation_method' => ['nullable', 'string', 'max:32'],
            'criticality' => ['nullable', 'string', 'max:24'],
            'condition' => ['nullable', 'string', 'max:24'],
            'warranty_start' => ['nullable', 'date'],
            'warranty_end' => ['nullable', 'date', 'after_or_equal:warranty_start'],
            'warranty_provider' => ['nullable', 'string', 'max:160'],
            'warranty_number' => ['nullable', 'string', 'max:80'],
            'procurement_reference' => ['nullable', 'string', 'max:80'],
            'purchase_order_reference' => ['nullable', 'string', 'max:80'],
            'invoice_reference' => ['nullable', 'string', 'max:80'],
            'vendor_id' => ['nullable', 'integer'],
            'force_asset' => ['nullable', 'boolean'],
            'specifications' => ['nullable', 'array'],
            'attributes' => ['nullable', 'array'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama aset wajib diisi.',
            'acquisition_cost.numeric' => 'Harga perolehan harus berupa angka.',
        ];
    }
}
