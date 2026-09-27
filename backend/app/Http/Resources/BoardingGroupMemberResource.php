<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Keanggotaan kelompok pengasuhan (§8).
 *
 * Anggota yang keluar tidak dihapus — `left_date` diisi, sehingga baris ini
 * juga menjadi riwayat "siapa dibina siapa". `is_current` membedakan keduanya.
 */
class BoardingGroupMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'group_id' => (int) $this->group_id,
            'group' => $this->when(
                $this->relationLoaded('group') && $this->group !== null,
                fn (): array => [
                    'id' => (int) $this->group->id,
                    'code' => $this->group->code,
                    'name' => $this->group->name,
                ]
            ),
            'student_id' => (int) $this->student_id,
            'student' => $this->when(
                $this->relationLoaded('student') && $this->student !== null,
                fn (): array => [
                    'id' => (int) $this->student->id,
                    'full_name' => $this->student->full_name,
                    'nis' => $this->student->nis,
                ]
            ),
            'joined_date' => $this->joined_date?->toDateString(),
            'left_date' => $this->left_date?->toDateString(),
            'is_current' => $this->resource->isCurrent(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
