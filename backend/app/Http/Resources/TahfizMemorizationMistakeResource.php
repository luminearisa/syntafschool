<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizMemorizationMistakeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mistake_type_id' => $this->mistake_type_id,
            // type_label adalah cadangan: rekam jejak tetap terbaca walau
            // konfigurasi tipe kesalahan diubah kemudian.
            'type_label' => $this->type_label ?? $this->mistakeType?->label,
            'ayah' => $this->ayah,
            'count' => $this->count,
            'note' => $this->note,
            'mistake_type' => $this->whenLoaded('mistakeType', fn () => [
                'id' => $this->mistakeType->id,
                'code' => $this->mistakeType->code,
                'label' => $this->mistakeType->label,
                'category' => $this->mistakeType->category,
            ]),
        ];
    }
}
