<?php

namespace App\Http\Resources;

use App\Models\BoardingVisitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu kunjungan tamu ke asrama (§40) — buku tamu digital.
 *
 * `duration_minutes` dihitung dari selisih check-in/check-out bila keduanya
 * sudah ada; tamu yang masih di dalam (`is_present`) tidak punya durasi.
 */
class BoardingVisitorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'visitor_name' => $this->visitor_name,
            'identity_number' => $this->identity_number,
            'phone' => $this->phone,
            'relationship' => $this->relationship,
            'student_id' => (int) $this->student_id,
            'student' => $this->when(
                $this->relationLoaded('student') && $this->student !== null,
                fn (): array => [
                    'id' => (int) $this->student->id,
                    'full_name' => $this->student->full_name,
                    'nis' => $this->student->nis,
                ],
            ),
            'visit_date' => $this->visit_date?->toDateString(),
            'purpose' => $this->purpose,
            'check_in_at' => $this->check_in_at?->toIso8601String(),
            'check_out_at' => $this->check_out_at?->toIso8601String(),
            'duration_minutes' => $this->check_in_at !== null && $this->check_out_at !== null
                ? (int) $this->check_in_at->diffInMinutes($this->check_out_at)
                : null,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_present' => $this->isPresent(),
            'can_check_in' => $this->status === BoardingVisitor::STATUS_WAITING,
            'can_check_out' => $this->status === BoardingVisitor::STATUS_VISITING,
            'can_cancel' => $this->isPresent(),
            'recorded_by' => $this->recorded_by === null ? null : (int) $this->recorded_by,
            'recorded_by_name' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'approved_by_name' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
