<?php

namespace App\Http\Resources;

use App\Models\PpdbInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tagihan PPDB.
 *
 * Yang bersifat internal petugas (`notes`, `created_by`, `discount_amount` bila
 * belum seharusnya diketahui orang tua, dan `fee_id` pada rincian) disembunyikan
 * dari pemanggil portal lewat permission check — orang tua hanya melihat apa
 * yang harus dibayar, jatuh tempo, dan riwayat pembayarannya sendiri.
 */
class PpdbInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->hasAnyPermission(['ppdb.payment.view', 'ppdb.registration.view']) ?? false;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'registration_id' => $this->registration_id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'amount' => (float) $this->amount,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,
            'paid_amount' => (float) $this->paid_amount,
            'outstanding_amount' => $this->outstandingAmount(),
            'is_paid' => $this->isPaid(),
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'notes' => $this->when($staff, $this->notes),
            'created_by' => $this->when($staff, $this->created_by),
            'items' => PpdbInvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PpdbPaymentResource::collection($this->whenLoaded('payments')),
            'registration' => $this->whenLoaded('registration', fn () => $this->registration ? [
                'id' => $this->registration->id,
                'registration_number' => $this->registration->registration_number,
                'status' => $this->registration->status,
                'payment_status' => $this->registration->payment_status,
                'period_id' => $this->registration->period_id,
                'wave_id' => $this->registration->wave_id,
                'program_id' => $this->registration->program_id,
                'applicant_name' => $this->registration->applicant?->full_name,
            ] : null),
            'paid_amount_sum' => $this->when(isset($this->resource->paid_amount_sum), (float) $this->resource->paid_amount_sum),
            'payments_count' => $this->whenCounted('payments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function typeLabel(): string
    {
        return match ($this->type) {
            PpdbInvoice::TYPE_RE_REGISTRATION => 'Biaya daftar ulang',
            PpdbInvoice::TYPE_OTHER => 'Tagihan lain',
            default => 'Biaya pendaftaran',
        };
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            PpdbInvoice::STATUS_UNPAID => 'Belum dibayar',
            PpdbInvoice::STATUS_PENDING => 'Menunggu verifikasi / sebagian',
            PpdbInvoice::STATUS_PAID => 'Lunas',
            PpdbInvoice::STATUS_FAILED => 'Gagal',
            PpdbInvoice::STATUS_EXPIRED => 'Kedaluwarsa',
            PpdbInvoice::STATUS_CANCELLED => 'Dibatalkan',
            PpdbInvoice::STATUS_REFUNDED => 'Dikembalikan',
            default => (string) $this->status,
        };
    }

    private function isOverdue(): bool
    {
        return ! $this->isPaid()
            && $this->due_date !== null
            && $this->due_date->endOfDay()->isPast();
    }
}
