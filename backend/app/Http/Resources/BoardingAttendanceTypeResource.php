<?php

namespace App\Http\Resources;

use App\Models\BoardingAttendanceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tipe absensi (§16, §18) — konfigurasi, bukan hard-code: status apa yang
 * dihitung hadir/izin/diatur ulang sepenuhnya ditentukan baris ini.
 */
class BoardingAttendanceTypeResource extends JsonResource
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
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'is_present' => (bool) $this->is_present,
            'is_excused' => (bool) $this->is_excused,
            'requires_note' => (bool) $this->requires_note,
            'requires_approval' => (bool) $this->requires_approval,
            'color' => $this->color,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
