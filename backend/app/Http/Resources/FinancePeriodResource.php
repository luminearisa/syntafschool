<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Periode fiskal (§48) dengan status open/closed (§49).
 */
class FinancePeriodResource extends JsonResource
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
            'type' => $this->type,
            'fiscal_year' => (int) $this->fiscal_year,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'is_open' => $this->status === \App\Models\FinancePeriod::STATUS_OPEN,
            'closed_by' => $this->closed_by !== null ? (int) $this->closed_by : null,
            'closed_by_name' => $this->whenLoaded('closedBy', fn (): ?string => $this->closedBy?->name),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'transactions_count' => $this->when(isset($this->transactions_count), fn (): int => (int) $this->transactions_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
