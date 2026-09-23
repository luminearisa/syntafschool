<?php

namespace App\Http\Resources;

use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu kartu RFID beserta status pelayanannya (§6, §7, §23).
 *
 * UID penuh hanya untuk petugas (`wallet.view`): pemilik saldo melihat versi
 * tertutup (`****1234`) karena layarnyalah yang menampilkan kartu itu, sementara
 * nomor UID adalah kunci pakai di mesin kasir.
 *
 * `wallet` ditampilkan seadanya (id + nomor + nama pemilik) supaya daftar kartu
 * lintas wallet dapat dibaca tanpa memanggil `WalletResource` secara rekursif.
 */
class WalletRfidCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = WalletService::staffView($request);

        return [
            'id' => (int) $this->id,
            'uid' => $staff ? $this->uid : WalletService::maskedUid($this->uid),
            'uid_masked' => WalletService::maskedUid($this->uid),
            'uid_is_masked' => ! $staff,
            'serial_number' => $this->when($staff, $this->serial_number),
            'issuing_batch' => $this->when($staff, $this->issuing_batch),
            'wallet_id' => (int) $this->wallet_id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_active' => $this->resource->isActive(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'replaced_by_card_id' => $this->replaced_by_card_id === null
                ? null
                : (int) $this->replaced_by_card_id,
            'replaced_by' => $this->when(
                $this->relationLoaded('replacedBy') && $this->replacedBy !== null,
                fn (): array => [
                    'id' => (int) $this->replacedBy->id,
                    'uid' => $staff ? $this->replacedBy->uid : WalletService::maskedUid($this->replacedBy->uid),
                ]
            ),
            'notes' => $this->when($staff, $this->notes),
            'created_by' => $this->when($staff, $this->created_by === null ? null : (int) $this->created_by),
            'wallet' => $this->when(
                $this->relationLoaded('wallet') && $this->wallet !== null,
                fn (): array => [
                    'id' => (int) $this->wallet->id,
                    'number' => $this->wallet->number,
                    'status' => $this->wallet->status,
                    'owner_name' => $this->wallet->ownerName(),
                ]
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function statusLabel(): string
    {
        return match ((string) $this->status) {
            'active' => 'Aktif',
            'inactive' => 'Nonaktif',
            'blocked' => 'Diblokir',
            'lost' => 'Hilang',
            'replaced' => 'Sudah diganti',
            default => (string) $this->status,
        };
    }
}
