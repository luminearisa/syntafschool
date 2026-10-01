<?php

namespace App\Modules\Asset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('asset.maintenance.request') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asset_id' => ['nullable', 'integer', 'exists:assets,id', 'required_without:facility_id'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id', 'required_without:asset_id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'in:LOW,NORMAL,HIGH,URGENT,CRITICAL'],
            'category' => ['nullable', 'string', 'max:40'],
            'photo_path' => ['nullable', 'string', 'max:200'],
            'cost_estimate' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'asset_id.required_without' => 'Permintaan harus menunjuk aset atau fasilitas.',
            'facility_id.required_without' => 'Permintaan harus menunjuk aset atau fasilitas.',
        ];
    }
}
