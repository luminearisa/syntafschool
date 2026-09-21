<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'program_id' => $this->program_id,
            'halaqah_id' => $this->halaqah_id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'rubric_id' => $this->rubric_id,
            'exam_date' => $this->exam_date?->toDateString(),
            'start_time' => $this->start_time?->format('H:i'),
            'duration_minutes' => $this->duration_minutes,
            'examiner_id' => $this->examiner_id,
            'level_id' => $this->level_id,
            'max_score' => (float) $this->max_score,
            'passing_score' => (float) $this->passing_score,
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'participants_count' => $this->whenCounted('participants', $this->participants_count, null),
            'program' => $this->whenLoaded('program', fn () => [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'code' => $this->program->code,
            ]),
            'halaqah' => $this->whenLoaded('halaqah', fn () => [
                'id' => $this->halaqah->id,
                'name' => $this->halaqah->name,
                'code' => $this->halaqah->code,
            ]),
            'rubric' => $this->whenLoaded('rubric', fn () => [
                'id' => $this->rubric->id,
                'name' => $this->rubric->name,
            ]),
            'level' => $this->whenLoaded('level', fn () => [
                'id' => $this->level->id,
                'name' => $this->level->name,
                'code' => $this->level->code,
            ]),
            'examiner' => $this->whenLoaded('examiner', fn () => [
                'id' => $this->examiner->id,
                'full_name' => $this->examiner->full_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function typeLabel(): string
    {
        return [
            'ujian_hafalan' => 'Ujian Hafalan',
            'sambung_ayat' => 'Sambung Ayat',
            'acak_ayat' => 'Ayat Acak',
            'tasmi' => 'Tasmi\'',
            'ujian_tahsin' => 'Ujian Tahsin',
            'kenaikan_level' => 'Kenaikan Level',
        ][$this->type] ?? ucfirst((string) $this->type);
    }

    protected function statusLabel(): string
    {
        return [
            'draft' => 'Draf',
            'scheduled' => 'Terjadwal',
            'ongoing' => 'Berlangsung',
            'done' => 'Selesai',
            'cancelled' => 'Dibatalkan',
        ][$this->status] ?? ucfirst((string) $this->status);
    }
}
