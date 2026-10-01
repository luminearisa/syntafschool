<?php

namespace App\Modules\Asset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('asset.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:asset_categories,id'],
            'asset_type_id' => ['sometimes', 'nullable', 'integer', 'exists:asset_types,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'criticality' => ['sometimes', 'string', 'max:24'],
            'condition' => ['sometimes', 'string', 'max:24'],
            'acquisition_cost' => ['sometimes', 'numeric', 'min:0'],
            'residual_value' => ['sometimes', 'numeric', 'min:0'],
            'useful_life_months' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'depreciation_method' => ['sometimes', 'string', 'max:32'],
            'expected_life_end' => ['sometimes', 'nullable', 'date'],
            'warranty_end' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
            'asset_code' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            // Kode aset tidak dapat diubah: ia tercetak pada label yang sudah
            // tertempel di barang. Perubahan status juga punya endpointnya sendiri
            // (transisi divalidasi StateMachine), bukan lewat PATCH biasa.
            'asset_code.prohibited' => 'Kode aset tidak dapat diubah karena sudah tercetak pada label.',
            'status.prohibited' => 'Perubahan status memakai endpoint /status agar transisinya tervalidasi.',
        ];
    }
}
