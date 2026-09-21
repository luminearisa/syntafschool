<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tahap seleksi PPDB.
 *
 * `weight` ikut dikeluarkan karena bobot adalah konfigurasi (audit §26): UI
 * panitia harus bisa melihat porsi tiap tahap pada skor gabungan tanpa menebak
 * angka dari dalam kode.
 */
class PpdbSelectionStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_id' => $this->period_id,
            'program_id' => $this->program_id,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => str_replace('_', ' ', (string) $this->type),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'location' => $this->location,
            'quota' => $this->quota === null ? null : (int) $this->quota,
            'passing_score' => $this->passing_score === null ? null : (float) $this->passing_score,
            'weight' => round((float) $this->weight, 2),
            'status' => $this->status,
            'order' => (int) $this->order,
            'notes' => $this->notes,
            'participants_count' => $this->whenCounted('participants'),
            'scored_participants_count' => $this->when(
                $this->resource->getAttribute('scored_participants_count') !== null,
                fn (): int => (int) $this->resource->getAttribute('scored_participants_count'),
            ),
            'period' => $this->whenLoaded('period', fn () => $this->period ? [
                'id' => $this->period->id,
                'code' => $this->period->code,
                'name' => $this->period->name,
            ] : null),
            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'code' => $this->program->code,
                'name' => $this->program->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
