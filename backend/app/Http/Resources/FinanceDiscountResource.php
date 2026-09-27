<?php

namespace App\Http\Resources;

use App\Models\FinanceDiscount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Aturan diskon reusable (§17): nominal/persen, bisa dibatasi jenis tagihan,
 * program, santri, dan rentang tanggal berlaku.
 */
class FinanceDiscountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => $this->type === FinanceDiscount::TYPE_PERCENTAGE ? 'Persentase' : 'Nominal',
            'value' => (float) $this->value,
            'fee_type_id' => $this->fee_type_id !== null ? (int) $this->fee_type_id : null,
            'fee_type_name' => $this->whenLoaded('feeType', fn (): ?string => $this->feeType?->name),
            'program_id' => $this->program_id !== null ? (int) $this->program_id : null,
            'program_name' => $this->whenLoaded('program', fn (): ?string => $this->program?->name),
            'student_id' => $this->student_id !== null ? (int) $this->student_id : null,
            'student_name' => $this->whenLoaded('student', fn (): ?string => $this->student?->full_name),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
