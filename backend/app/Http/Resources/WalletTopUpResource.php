<?php

namespace App\Http\Resources;

use App\Models\WalletTopUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Satu permintaan top up — datar (§36).
 *
 * Tiga keadaan ditampilkan berdampingan dan sengaja tidak digabung jadi satu label
 * (§14): `status` (permintaan), `payment_status` (pembayaran), `transaction_id` +
 * `transaction_number` (ledger tempat saldo benar-benar bertambah). Frontend yang
 * menampilkan "sedang diproses" harus membacanya bertiga, bukan menebak dari satu.
 *
 * Kolom internal petugas — jejak provider, rekening asal, siapa yang memeriksa,
 * payload mentah — hanya dibaca pemegang permission `wallet.topup.view`. Pemilik
 * wallet tetap melihat nominal, status, dan bukti yang ia unggah sendiri.
 */
class WalletTopUpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = $this->staffView($request);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'wallet_id' => $this->wallet_id,
            'wallet_number' => $this->wallet?->number,
            'owner_name' => $this->ownerName(),
            'owner_type' => $this->ownerType(),
            'owner_identifier' => $this->ownerIdentifier(),
            'amount' => round((float) $this->amount, 2),
            'method' => $this->method,
            'method_label' => $this->methodLabel(),
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'can_verify' => $this->verifiable(),
            'can_reject' => $this->verifiable(),
            'provider' => $this->provider,
            'provider_reference' => $this->when($staff, $this->provider_reference),
            'payment_expired_at' => $this->payment_expired_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'checkout_url' => $this->when(
                $this->checkoutAvailable($request, $staff),
                fn (): ?string => data_get($this->payment_payload, 'redirect_url'),
            ),
            'snap_token' => $this->when(
                $this->checkoutAvailable($request, $staff),
                fn (): ?string => data_get($this->payment_payload, 'token'),
            ),
            'bank_id' => $this->bank_id,
            'bank_name' => $this->bank?->name,
            'source_account_number' => $this->when($staff, $this->source_account_number),
            'source_account_name' => $this->when($staff, $this->source_account_name),
            'transferred_on' => $this->transferred_on?->toDateString(),
            'proof_path' => $this->when($staff, $this->proof_path),
            'proof_url' => $this->proofUrl(),
            'verified_at' => $this->when($staff, $this->verified_at?->toIso8601String()),
            'verified_by' => $this->when($staff, $this->verified_by),
            'verified_by_name' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->name),
            'requested_by' => $this->when($staff, $this->requested_by),
            'requested_by_name' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'rejection_reason' => $this->rejection_reason,
            'transaction_id' => $this->transaction_id,
            'transaction_number' => $this->whenLoaded('transaction', fn () => $this->transaction?->transaction_number),
            'transaction_balance_after' => $this->when(
                $staff && $this->relationLoaded('transaction'),
                fn () => $this->transaction ? round((float) $this->transaction->balance_after, 2) : null,
            ),
            'wallet_balance' => $this->when(
                $staff || $this->isRequester($request),
                fn () => $this->wallet ? round((float) $this->wallet->balance, 2) : null,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Petugas modul: pemegang `wallet.topup.view` (atau `wallet.view` untuk detail wallet). */
    private function staffView(Request $request): bool
    {
        return $request->user()?->hasAnyPermission(['wallet.topup.view', 'wallet.ledger.view']) ?? false;
    }

    private function isRequester(Request $request): bool
    {
        return (int) $request->user()?->id === (int) $this->requested_by;
    }

    /**
     * Kunci checkout hanya berguna bagi yang boleh menyelesaikan pembayarannya:
     * peminta pengajuan itu sendiri, atau petugas.
     */
    private function checkoutAvailable(Request $request, bool $staff): bool
    {
        return $this->method === WalletTopUp::METHOD_GATEWAY
            && $this->status === WalletTopUp::STATUS_PENDING_PAYMENT
            && ($staff || $this->isRequester($request));
    }

    /**
     * Nama pemilik wallet — hanya bila relasinya sudah dimuat.
     *
     * `Wallet::ownerName()` membaca relasi pemilik lewat `getRelation()`, yang
     * mengasumsikan relasinya ada. Memanggilnya pada koleksi tanpa eager load akan
     * menghasilkan peringatan, jadi nama hanya ditampilkan bila baris pemilik ikut
     * diambil (service selalu memuatnya).
     */
    private function ownerName(): ?string
    {
        $wallet = $this->wallet;

        if ($wallet === null) {
            return null;
        }

        $type = $wallet->ownerType();

        return $type !== null && $wallet->relationLoaded($type) ? $wallet->ownerName() : null;
    }

    private function ownerType(): ?string
    {
        return $this->wallet?->ownerType();
    }

    private function ownerIdentifier(): ?string
    {
        $wallet = $this->wallet;

        if ($wallet === null) {
            return null;
        }

        $type = $wallet->ownerType();

        return $type !== null && $wallet->relationLoaded($type) ? $wallet->ownerIdentifier() : null;
    }

    /** URL bukti transfer; hanya tersedia pada disk public. */
    private function proofUrl(): ?string
    {
        $path = $this->proof_path;

        return filled($path) ? Storage::disk('public')->url((string) $path) : null;
    }

    private function methodLabel(): string
    {
        return $this->method === WalletTopUp::METHOD_GATEWAY ? 'Payment gateway' : 'Transfer manual';
    }

    /**
     * Apakah permintaan ini masih bisa diputus petugas?
     *
     * Dijoined dari dua keadaan (§14): masih menunggu keputusan DAN belum pernah
     * menambah saldo. Frontend memakai ini untuk menyembunyikan tombol yang pasti
     * ditolak service.
     */
    private function verifiable(): bool
    {
        return in_array($this->status, [
            WalletTopUp::STATUS_AWAITING_VERIFICATION,
            WalletTopUp::STATUS_PENDING_PAYMENT,
        ], true) && $this->transaction_id === null && $this->payment_status !== WalletTopUp::PAYMENT_PAID;
    }
}
