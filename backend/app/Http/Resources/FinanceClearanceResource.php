<?php

namespace App\Http\Resources;

use App\Models\FinanceClearance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Keberesan finansial (§12 Phase 5).
 *
 * Yang dikirim bukan hanya status: `headline` menjawab "kenapa" dalam satu
 * kalimat, `checklist` menjawab "apa saja yang diperiksa", dan baris piutang di
 * dalam checklist menjawab "invoice mana, berapa, jatuh tempo kapan" — sesuai
 * tuntutan §12 bahwa layar tidak boleh berhenti pada tulisan "Belum Lunas".
 *
 * Tidak ada field yang bisa ditulis balik dari layar: status keputusan hanya
 * berubah lewat endpoint approve/waive yang beralasan.
 */
class FinanceClearanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'clearance_number' => $this->clearance_number,
            'student_id' => (int) $this->student_id,
            'student_name' => $this->whenLoaded('student', fn (): ?string => $this->student?->full_name),
            'student_nis' => $this->whenLoaded('student', fn (): ?string => $this->student?->nis),
            'purpose' => $this->purpose,
            'purpose_label' => $this->purposeLabel(),
            'status' => $this->status,
            'status_label' => FinanceClearance::STATUS_LABELS[$this->status] ?? $this->status,
            'is_open' => $this->isOpen(),
            'is_settled' => $this->isSettled(),
            'evaluated_on' => $this->evaluated_on?->toDateString(),
            'outstanding_total' => round((float) $this->outstanding_total, 2),
            'credit_total' => round((float) $this->credit_total, 2),
            'blocking_count' => (int) $this->blocking_count,
            'headline' => $this->headline,
            'checklist' => $this->checklist ?? [],
            'blocking_lines' => $this->blockingLines(),
            'notes' => $this->notes,
            'requested_by' => $this->whenLoaded('requestedBy', fn (): ?array => $this->requestedBy === null ? null : [
                'id' => (int) $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ]),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'decided_by' => $this->whenLoaded('decidedBy', fn (): ?array => $this->decidedBy === null ? null : [
                'id' => (int) $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ]),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_reason' => $this->decision_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
