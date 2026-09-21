<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryMemberCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'card_number' => $this->card_number,
            'barcode' => $this->barcode,
            // Token QR untuk pemindaian pada sirkulasi (Fase 4).
            'qr_token' => $this->qr_token,
            'issued_at' => $this->issued_at?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'status' => $this->status,
            'is_valid' => $this->isValid(),
            'printed_count' => (int) $this->printed_count,
            'last_printed_at' => $this->last_printed_at?->toIso8601String(),

            'member' => $this->whenLoaded('member', fn () => $this->member ? [
                'id' => $this->member->id,
                'member_number' => $this->member->member_number,
                'display_name' => $this->member->display_name,
                'photo_path' => $this->member->photo_path,
                'status' => $this->member->status,
                'valid_until' => $this->member->valid_until?->toDateString(),
                'membership_type' => $this->member->relationLoaded('membershipType') && $this->member->membershipType
                    ? $this->member->membershipType->name
                    : null,
            ] : null),

            // Data perpustakaan untuk keperluan cetak kartu (§39).
            'library' => $this->when(
                $this->member !== null && $this->member->relationLoaded('library') && $this->member->library !== null,
                fn () => [
                    'id' => $this->member->library->id,
                    'code' => $this->member->library->code,
                    'name' => $this->member->library->name,
                    'logo_path' => $this->member->library->logo_path,
                ]
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}