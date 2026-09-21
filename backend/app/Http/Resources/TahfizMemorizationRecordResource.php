<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizMemorizationRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'participant_id' => $this->participant_id,
            'halaqah_id' => $this->halaqah_id,
            'session_id' => $this->session_id,
            'date' => $this->date?->toDateString(),
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'surah_id' => $this->surah_id,
            'surah' => $this->whenLoaded('surah', fn () => [
                'id' => $this->surah->id,
                'number' => $this->surah->number,
                'name' => $this->surah->name,
                'arabic_name' => $this->surah->arabic_name,
            ]),
            'ayah_start' => $this->ayah_start,
            'ayah_end' => $this->ayah_end,
            'ayah_count' => $this->ayahCount(),
            'juz' => $this->juz,
            'page_start' => $this->page_start,
            'page_end' => $this->page_end,
            'page_count' => $this->pageCount(),
            'line_count' => $this->line_count,
            'duration_minutes' => $this->duration_minutes,
            'score' => $this->score !== null ? (float) $this->score : null,
            'fluency' => $this->fluency,
            'tajwid' => $this->tajwid,
            'makhraj' => $this->makhraj,
            'mistakes_count' => $this->mistakes_count,
            'mistakes' => TahfizMemorizationMistakeResource::collection($this->whenLoaded('mistakes')),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_accepted' => $this->isAccepted(),
            'revision_due_date' => $this->revision_due_date?->toDateString(),
            'teacher_note' => $this->teacher_note,
            'participant_note' => $this->participant_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ]),
            'halaqah' => $this->whenLoaded('halaqah', fn () => [
                'id' => $this->halaqah->id,
                'name' => $this->halaqah->name,
                'code' => $this->halaqah->code,
            ]),
            'recorded_by' => $this->recorded_by,
            'reviewed_by' => $this->reviewed_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function typeLabel(): string
    {
        return [
            'hafalan_baru' => 'Hafalan Baru',
            'ziyadah' => 'Ziyadah',
            'setoran' => 'Setoran',
            'tasmi' => 'Tasmi\'',
        ][$this->type] ?? $this->type;
    }

    protected function statusLabel(): string
    {
        return [
            'submitted' => 'Menunggu Penilaian',
            'reviewed' => 'Sedang Dinilai',
            'accepted' => 'Diterima',
            'accepted_with_revision' => 'Diterima dengan Revisi',
            'repeated' => 'Diulang',
            'rejected' => 'Ditolak',
        ][$this->status] ?? $this->status;
    }
}
