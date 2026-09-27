<?php

namespace App\Http\Resources;

use App\Models\BoardingGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kelompok pengasuhan lintas kamar (§8) — halaqah, piket, kelompok binaan.
 *
 * `members_count` dihitung dari keanggotaan yang masih berjalan (`left_date`
 * kosong); riwayat anggota lama tetap tersimpan di tabelnya sendiri.
 */
class BoardingGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'musyrif_employee_id' => $this->musyrif_employee_id === null ? null : (int) $this->musyrif_employee_id,
            'musyrif' => $this->when(
                $this->relationLoaded('musyrifEmployee') && $this->musyrifEmployee !== null,
                fn (): array => [
                    'id' => (int) $this->musyrifEmployee->id,
                    'full_name' => $this->musyrifEmployee->full_name,
                    'nip' => $this->musyrifEmployee->nip,
                ]
            ),
            'dormitory_id' => $this->dormitory_id === null ? null : (int) $this->dormitory_id,
            'dormitory' => $this->when(
                $this->relationLoaded('dormitory') && $this->dormitory !== null,
                fn (): array => [
                    'id' => (int) $this->dormitory->id,
                    'code' => $this->dormitory->code,
                    'name' => $this->dormitory->name,
                ]
            ),
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => BoardingGroup::STATUS_LABELS[$this->status] ?? $this->status,
            'members_count' => $this->when(isset($this->members_count), fn (): int => (int) $this->members_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
