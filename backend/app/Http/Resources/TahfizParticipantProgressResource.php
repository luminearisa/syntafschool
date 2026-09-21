<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizParticipantProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'participant_id' => $this->participant_id,
            'surah_id' => $this->surah_id,
            'surah' => $this->whenLoaded('surah', fn () => [
                'id' => $this->surah->id,
                'number' => $this->surah->number,
                'name' => $this->surah->name,
                'arabic_name' => $this->surah->arabic_name,
                'ayah_count' => $this->surah->ayah_count,
            ]),
            'juz' => $this->juz,
            'ayahs_memorized' => $this->ayahs_memorized,
            'ayahs_mutqin' => $this->ayahs_mutqin,
            'pages_memorized' => $this->pages_memorized,
            'pages_mutqin' => $this->pages_mutqin,
            'memorized_percent' => $this->memorizedPercent(),
            'mutqin_percent' => $this->mutqinPercent(),
            'status' => $this->status,
            'status_label' => \App\Models\TahfizParticipantProgress::STATUS_LABELS[$this->status] ?? $this->status,
            'avg_score' => $this->avg_score !== null ? (float) $this->avg_score : null,
            'setoran_count' => $this->setoran_count,
            'murojaah_count' => $this->murojaah_count,
            'last_setor_date' => $this->last_setor_date?->toDateString(),
            'last_murojaah_date' => $this->last_murojaah_date?->toDateString(),
        ];
    }
}
