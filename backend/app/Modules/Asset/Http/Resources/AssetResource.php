<?php

namespace App\Modules\Asset\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk data aset untuk API.
 *
 * Angka keuangan HANYA muncul bila pemanggil memegang izin keuangan — dan itu
 * ditentukan di sini, sekali, bukan diulang di setiap layar. Data yang tidak
 * diizinkan tidak dikirim sebagai null "karena kosong", melainkan tidak ada.
 */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeFinancials = (bool) $request->user()?->can('asset.financial.view');

        return [
            'id' => $this->id,
            'asset_code' => $this->asset_code,
            'asset_number' => $this->asset_number,
            'asset_tag' => $this->asset_tag,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'category' => $this->whenLoaded('category', fn () => $this->category?->only(['id', 'code', 'name'])),
            'type' => $this->whenLoaded('type', fn () => $this->type?->only(['id', 'code', 'name'])),
            'location' => $this->whenLoaded('location', fn () => $this->location?->only(['id', 'code', 'name', 'path'])),
            'unit_id' => $this->unit_id,
            'custodian_id' => $this->custodian_id,
            'custodian_label' => $this->custodian_label,
            'custodian_type' => $this->custodian_type,
            'status' => $this->status?->value ?? (string) $this->status,
            'status_label' => method_exists($this->status, 'label') ? $this->status->label() : null,
            'condition' => $this->condition?->value ?? (string) $this->condition,
            'condition_label' => method_exists($this->condition, 'label') ? $this->condition->label() : null,
            'criticality' => $this->criticality?->value ?? (string) $this->criticality,
            'tag_status' => $this->tag_status?->value ?? (string) $this->tag_status,
            'serial_number' => $this->serial_number,
            'manufacturer' => $this->manufacturer,
            'brand' => $this->brand,
            'model' => $this->model,
            'acquisition_source' => $this->acquisition_source?->value ?? (string) $this->acquisition_source,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'received_date' => $this->received_date?->toDateString(),
            'commission_date' => $this->commission_date?->toDateString(),
            'warranty' => [
                'start' => $this->warranty_start?->toDateString(),
                'end' => $this->warranty_end?->toDateString(),
                'provider' => $this->warranty_provider,
                'number' => $this->warranty_number,
                'expiring_soon' => $this->warranty_end !== null && $this->warranty_end->isFuture() && $this->warranty_end->lessThan(now()->addDays(90)),
            ],
            'life' => [
                'useful_life_months' => $this->useful_life_months,
                'expected_life_end' => $this->expected_life_end?->toDateString(),
                'last_inspection_at' => $this->last_inspection_at?->toDateTimeString(),
                'next_inspection_at' => $this->next_inspection_at?->toDateTimeString(),
                'last_maintenance_at' => $this->last_maintenance_at?->toDateTimeString(),
                'next_maintenance_at' => $this->next_maintenance_at?->toDateTimeString(),
                'loan_count' => (int) $this->loan_count,
                'maintenance_count' => (int) $this->maintenance_count,
                'downtime_days' => (int) floor(((int) $this->downtime_minutes) / 1440),
            ],
            'is_archived' => (bool) $this->is_archived,
            'version' => (int) $this->version,
            'description' => $this->description,
            'financial' => $this->when($canSeeFinancials, fn (): array => [
                'acquisition_cost' => (string) $this->acquisition_cost,
                'residual_value' => (string) $this->residual_value,
                'accumulated_depreciation' => (string) $this->accumulated_depreciation,
                'current_book_value' => (string) ($this->current_book_value ?? $this->acquisition_cost),
                'fair_value' => $this->fair_value === null ? null : (string) $this->fair_value,
                'depreciation_method' => $this->depreciation_method,
                'capitalization_decision' => $this->capitalization_decision?->value ?? (string) $this->capitalization_decision,
                'finance_asset_id' => $this->finance_asset_id,
            ]),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
