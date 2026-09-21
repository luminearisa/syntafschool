<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizMutabaahEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            // Label dicatat saat pengisian agar rekam jejak tetap terbaca
            // walau item mutabaah diubah kemudian.
            'item_label' => $this->item_label ?? $this->item?->label,
            'done' => (bool) $this->done,
            'value' => $this->value !== null ? (float) $this->value : null,
            'note' => $this->note,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'code' => $this->item->code,
                'label' => $this->item->label,
                'category' => $this->item->category,
                'target_value' => $this->item->target_value,
            ]),
        ];
    }
}
