<?php

namespace App\Http\Resources;

use App\Models\FinanceFeePackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paket tagihan (§11, §12). `items` = baris jenis tagihan + nominal; `total`
 * dihitung dari item aktif. Segmen (program/kelas/boarding) menentukan paket
 * mana yang dipakai billing engine.
 */
class FinanceFeePackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'program_id' => $this->program_id !== null ? (int) $this->program_id : null,
            'program_name' => $this->whenLoaded('program', fn (): ?string => $this->program?->name),
            'level' => $this->level,
            'class_id' => $this->class_id !== null ? (int) $this->class_id : null,
            'class_name' => $this->whenLoaded('schoolClass', fn (): ?string => $this->schoolClass?->name),
            'boarding_type' => $this->boarding_type,
            'academic_year' => $this->academic_year !== null ? (int) $this->academic_year : null,
            'is_active' => (bool) $this->is_active,
            'description' => $this->description,
            'total' => $this->when(isset($this->total_amount), fn (): float => (float) $this->total_amount),
            'items' => FinanceFeeItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->when(isset($this->items_count), fn (): int => (int) $this->items_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
