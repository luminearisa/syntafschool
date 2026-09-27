<?php

namespace App\Http\Resources;

use App\Models\FinancePaymentPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rencana pembayaran (§7: invoice_id, plan_number, total_amount,
 * installment_count, start_date, status) beserta angsurannya.
 *
 * `paid_amount`/`outstanding_amount` di level plan adalah jumlah dari angsuran,
 * dan angsuran sendiri dihitung dari pelunasan invoice — jadi angka ini selalu
 * bisa ditelusuri ke satu sumber: payment + allocation milik invoice aslinya.
 */
class FinancePaymentPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $total = round((float) $this->total_amount, 2);
        $loaded = $this->resource->relationLoaded('installments');

        // Angka level plan tidak dihitung di sini: ia jumlah dari angsuran, dan
        // angsuran sudah disinkronkan service dari pelunasan invoice.
        $paid = $loaded ? $this->resource->paidAmount() : null;
        $next = $loaded ? $this->resource->nextInstallment() : null;

        return [
            'id' => (int) $this->id,
            'plan_number' => $this->plan_number,
            'invoice_id' => (int) $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'student_id' => $this->student_id !== null ? (int) $this->student_id : null,
            'student_name' => $this->whenLoaded('student', fn (): ?string => $this->student?->full_name),
            'nis' => $this->whenLoaded('student', fn (): ?string => $this->student?->nis),
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'total_amount' => $total,
            'installment_count' => (int) $this->installment_count,
            'start_date' => $this->start_date?->toDateString(),
            // Saldo invoice ketika cicilan disepakati: dasar bahwa angsuran ke-1
            // benar-benar cicilan pertama, bukan pelunasan lama yang dihitung ulang.
            'baseline_paid_amount' => (float) $this->baseline_paid_amount,
            'paid_amount' => $paid,
            'outstanding_amount' => $paid === null ? null : round($total - $paid, 2),
            'progress' => $paid === null || $total <= FinancePaymentPlan::EPSILON
                ? null
                : round($paid / $total * 100, 2),
            'next_due_date' => $next?->due_date?->toDateString(),
            'next_installment_sequence' => $next === null ? null : (int) $next->sequence,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'notes' => $this->notes,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_by_name' => $this->whenLoaded('cancelledBy', fn (): ?string => $this->cancelledBy?->name),
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_by_name' => $this->whenLoaded('createdBy', fn (): ?string => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'installments' => $this->whenLoaded(
                'installments',
                fn () => FinancePaymentPlanInstallmentResource::collection($this->installments),
            ),
        ];
    }
}
