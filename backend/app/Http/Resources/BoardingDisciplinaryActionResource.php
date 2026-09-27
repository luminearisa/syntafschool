<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tindakan pendisiplinan (§37, §38) — dari antrean kerja petugas.
 * `violation_id` boleh null: tindakan bisa lahir dari akumulasi poin, bukan
 * satu kejadian tunggal.
 */
class BoardingDisciplinaryActionResource extends JsonResource
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
            'violation_id' => $this->violation_id === null ? null : (int) $this->violation_id,
            'violation' => $this->when(
                $this->relationLoaded('violation') && $this->violation !== null,
                fn (): array => [
                    'id' => (int) $this->violation->id,
                    'date' => $this->violation->date?->toDateString(),
                    'points' => (int) $this->violation->points,
                ],
            ),
            'is_auto' => $this->violation_id === null,
            'action' => $this->action,
            'action_label' => $this->actionLabel(),
            'date' => $this->date?->toDateString(),
            'officer' => $this->officer,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'notes' => $this->notes,
            'is_pending' => $this->isPending(),
            'can_complete' => $this->isPending(),
            'can_cancel' => $this->isPending(),
            'recorded_by' => $this->recorded_by === null ? null : (int) $this->recorded_by,
            'recorded_by_name' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
