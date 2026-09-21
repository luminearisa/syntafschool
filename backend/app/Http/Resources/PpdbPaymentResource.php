<?php

namespace App\Http\Resources;

use App\Models\PpdbPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Satu pembayaran tagihan (manual maupun Midtrans).
 *
 * Kolom internal petugas — `note` (berisi payload mentah penyedia), `verified_by`,
 * `provider_reference`, `created_by` — hanya dibaca pemegang permission
 * pembayaran. Orang tua tetap melihat status & bukti yang ia unggah sendiri.
 */
class PpdbPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->hasAnyPermission(['ppdb.payment.view', 'ppdb.registration.view']) ?? false;

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'method' => $this->method,
            'channel_label' => $this->channelLabel(),
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'payment_method_id' => $this->payment_method_id,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
                'code' => $this->paymentMethod->code,
            ] : null),
            'proof_path' => $this->when($staff, $this->proof_path),
            'proof_url' => $this->proofUrl(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'verified_at' => $this->when($staff, $this->verified_at?->toIso8601String()),
            'verified_by' => $this->when($staff, $this->verified_by),
            'verified_by_name' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->name),
            'provider' => $this->when($this->method === PpdbPayment::METHOD_MIDTRANS, $this->provider),
            'provider_reference' => $this->when($staff, $this->provider_reference),
            'note' => $this->when($staff, $this->note),
            'created_by' => $this->when($staff, $this->created_by),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function channelLabel(): string
    {
        return $this->method === PpdbPayment::METHOD_MIDTRANS ? 'Pembayaran online' : 'Transfer manual';
    }

    /** URL bukti bayar; hanya tersedia pada disk public. */
    private function proofUrl(): ?string
    {
        $path = $this->proof_path;

        return filled($path) ? Storage::disk('public')->url((string) $path) : null;
    }
}
