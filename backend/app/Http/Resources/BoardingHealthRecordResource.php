<?php

namespace App\Http\Resources;

use App\Services\Boarding\BoardingHealthService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Catatan kesehatan asrama (§39) — gejala, tindakan, dan status alur; BUKAN
 * rekam medis. `allowed_next_statuses` memberi frontend urutan transisi yang
 * sah tanpa menyalin aturan bisnis ke klien.
 */
class BoardingHealthRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'student_id' => (int) $this->student_id,
            'student' => $this->when(
                $this->relationLoaded('student') && $this->student !== null,
                fn (): array => [
                    'id' => (int) $this->student->id,
                    'full_name' => $this->student->full_name,
                    'nis' => $this->student->nis,
                ],
            ),
            'condition' => $this->condition,
            'complaint' => $this->complaint,
            'action' => $this->action,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_open' => $this->isOpen(),
            'allowed_next_statuses' => BoardingHealthService::TRANSITIONS[$this->status] ?? [],
            'recorded_by' => $this->recorded_by === null ? null : (int) $this->recorded_by,
            'recorded_by_name' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
