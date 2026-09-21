<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizTahsinScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rubric_item_id' => $this->rubric_item_id,
            // criterion disalin dari rubrik saat dicatat, sehingga rekam
            // jejak tetap terbaca walau kriteria rubrik diubah kemudian.
            'criterion' => $this->criterion,
            'score' => (float) $this->score,
            'note' => $this->note,
            'rubric_item' => $this->whenLoaded('rubricItem', fn () => [
                'id' => $this->rubricItem->id,
                'criterion' => $this->rubricItem->criterion,
                'weight' => (float) $this->rubricItem->weight,
            ]),
        ];
    }
}
