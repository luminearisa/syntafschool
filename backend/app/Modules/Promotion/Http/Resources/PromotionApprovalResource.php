<?php

namespace App\Modules\Promotion\Http\Resources;

use App\Modules\Promotion\Models\PromotionApproval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PromotionApproval $approval */
        $approval = $this->resource;

        return [
            'id' => (int) $approval->id,
            'promotion_decision_id' => (int) $approval->promotion_decision_id,
            'approver_role' => $approval->approver_role,
            'approver_role_label' => PromotionApproval::ROLE_LABELS[$approval->approver_role] ?? $approval->approver_role,
            'status' => $approval->status,
            'approved_by' => $approval->approved_by === null ? null : (int) $approval->approved_by,
            'approved_at' => $approval->approved_at?->toIso8601String(),
            'note' => $approval->note,
            'approver' => $this->whenLoaded('approver', fn () => $approval->approver ? [
                'id' => (int) $approval->approver->id,
                'name' => $approval->approver->name,
            ] : null),
        ];
    }
}
