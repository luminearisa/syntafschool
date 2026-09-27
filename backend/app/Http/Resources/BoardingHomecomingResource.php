<?php

namespace App\Http\Resources;

use App\Models\BoardingHomecoming;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu kepulangan santri ke rumah (§30–§31).
 *
 * Flag kelayakan tindakan (`can_*`) hanya menggambarkan STATUS — hak akses
 * tetap urusan middleware rute, supaya frontend tidak menyembunyikan aksi
 * yang sebenarnya boleh dilakukan pemanggil.
 */
class BoardingHomecomingResource extends JsonResource
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
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'home_date' => $this->home_date?->toDateString(),
            'return_date' => $this->return_date?->toDateString(),
            'actual_return_date' => $this->actual_return_date?->toDateString(),
            'late_return' => (bool) $this->late_return,
            'is_late_return' => $this->isLateReturn(),
            'transport' => $this->transport,
            'picked_up_by' => $this->picked_up_by,
            'returned_with' => $this->returned_with,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_active' => $this->isActive(),
            'is_home' => $this->isHome(),
            'is_overdue' => $this->status === BoardingHomecoming::STATUS_OUT
                && $this->return_date !== null
                && $this->return_date->lessThan(now()),
            'can_submit' => $this->status === BoardingHomecoming::STATUS_DRAFT,
            'can_approve' => $this->status === BoardingHomecoming::STATUS_SUBMITTED,
            'can_reject' => in_array($this->status, [
                BoardingHomecoming::STATUS_DRAFT,
                BoardingHomecoming::STATUS_SUBMITTED,
            ], true),
            'can_check_out' => $this->status === BoardingHomecoming::STATUS_APPROVED,
            'can_check_in' => $this->status === BoardingHomecoming::STATUS_OUT,
            'can_cancel' => in_array($this->status, [
                BoardingHomecoming::STATUS_DRAFT,
                BoardingHomecoming::STATUS_SUBMITTED,
                BoardingHomecoming::STATUS_APPROVED,
            ], true),
            'requested_by' => $this->requested_by === null ? null : (int) $this->requested_by,
            'requested_by_name' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'approved_by' => $this->approved_by === null ? null : (int) $this->approved_by,
            'approved_by_name' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'checked_out_by_name' => $this->whenLoaded('checkedOutBy', fn () => $this->checkedOutBy?->name),
            'checked_in_by_name' => $this->whenLoaded('checkedInBy', fn () => $this->checkedInBy?->name),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
