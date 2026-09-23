<?php

namespace App\Http\Resources;

use App\Models\WalletRfidCard;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu wallet + ringkasan saldonya (§22, §23).
 *
 * Bentuknya datar dan sama untuk admin maupun portal; yang berbeda HANYA
 * kepekaan datanya. Pemanggil portal (pemilik saldo, tidak memegang
 * `wallet.view`) tidak membaca catatan internal petugas, siapa pembuatnya,
 * serial fisik kartu, dan UID penuh — UID ditampilkan empat digit terakhir
 * seperti contoh spesifikasi (`****1234`).
 *
 * Relasi pemilik (`student`/`employee`/`guardian`/`user`) dibaca apa adanya
 * bila sudah dimuat; `WalletService` yang menjamin muatannya ada, termasuk pada
 * daftar berpaginasi, supaya nama pemilik tidak memicu satu query per baris.
 */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = WalletService::staffView($request);
        $ownerType = $this->resource->ownerType();
        $ownerColumn = WalletService::ownerColumn($ownerType);
        $ownerId = $ownerColumn === null ? null : $this->getAttribute($ownerColumn);

        // Kartu hanya dibaca bila relasinya sudah dimuat (detail/portal): pada
        // daftar, kolom `rfid_count` cukup dan UID penuh memang tidak dibutuhkan.
        $cardsLoaded = $this->relationLoaded('rfidCards');
        $activeCard = $cardsLoaded ? $this->resource->activeRfidCard() : null;

        return [
            'id' => (int) $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'status_label' => WalletService::statusLabel((string) $this->status),
            'is_active' => $this->resource->isActive(),
            'can_transact' => $this->resource->canTransact(),

            'owner_type' => $ownerType,
            'owner_type_label' => $ownerType === null ? null : WalletService::ownerTypeLabel($ownerType),
            'owner_id' => $ownerId === null ? null : (int) $ownerId,
            'owner_name' => $this->resource->ownerName(),
            'owner_identifier' => $this->resource->ownerIdentifier(),

            'balance' => round((float) $this->balance, 2),
            'held_balance' => round((float) $this->held_balance, 2),
            'available_balance' => $this->resource->availableBalance(),

            'rfid_cards' => WalletRfidCardResource::collection($this->whenLoaded('rfidCards')),
            'rfid_count' => $this->whenCounted('rfidCards'),
            'active_rfid' => $this->when(
                $cardsLoaded && $activeCard instanceof WalletRfidCard,
                fn (): array => [
                    'id' => (int) $activeCard->id,
                    'uid' => $staff
                        ? $activeCard->uid
                        : WalletService::maskedUid($activeCard->uid),
                    'status' => $activeCard->status,
                ]
            ),

            'opened_at' => $this->opened_at?->toIso8601String(),
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'block_reason' => $this->block_reason,

            // Internal petugas: catatan admin dan jejak siapa yang membuka rekening.
            'notes' => $this->when($staff, $this->notes),
            'created_by' => $this->when($staff, $this->created_by === null ? null : (int) $this->created_by),
            'created_by_name' => $this->when(
                $staff && $this->relationLoaded('createdBy'),
                fn (): ?string => $this->createdBy?->name
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
