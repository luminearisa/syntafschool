<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu langkah persetujuan izin keluar (§27).
 *
 * Ditampilkan apa adanya — termasuk penolakan — supaya orang tua dan pengasuh
 * melihat siapa yang menolak dan mengapa, bukan hanya status akhir. `acted_role`
 * ikut dikembalikan karena konfigurasi asrama boleh mensyaratkan "wali kamar
 * lebih dulu, lalu kepala asrama"; perannya harus terbaca dari barisnya.
 */
class BoardingPermissionApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'permission_id' => (int) $this->permission_id,
            'step' => (int) $this->step,
            'action' => $this->action,
            'action_label' => $this->actionLabel(),
            'acted_by' => $this->acted_by === null ? null : (int) $this->acted_by,
            'acted_by_name' => $this->whenLoaded('actedBy', fn () => $this->actedBy?->name),
            'acted_role' => $this->acted_role,
            'note' => $this->note,
            'acted_at' => $this->acted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
