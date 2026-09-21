<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizExamParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'participant_id' => $this->participant_id,
            'enrollment_id' => $this->enrollment_id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'score' => $this->score !== null ? (float) $this->score : null,
            'grade' => $this->grade,
            'result' => $this->result,
            'result_label' => $this->resultLabel(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'feedback' => $this->feedback,
            'items' => TahfizExamItemResource::collection($this->whenLoaded('items')),
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ]),
            'exam' => $this->whenLoaded('exam', fn () => [
                'id' => $this->exam->id,
                'code' => $this->exam->code,
                'name' => $this->exam->name,
                'type' => $this->exam->type,
                'max_score' => (float) $this->exam->max_score,
                'passing_score' => (float) $this->exam->passing_score,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function statusLabel(): string
    {
        return [
            'terdaftar' => 'Terdaftar',
            'hadir' => 'Hadir',
            'tidak_hadir' => 'Tidak Hadir',
            'menunggu_nilai' => 'Menunggu Nilai',
            'dinilai' => 'Dinilai',
        ][$this->status] ?? $this->status;
    }

    protected function resultLabel(): ?string
    {
        if ($this->result === null) {
            return null;
        }

        return [
            'lulus' => 'Lulus',
            'tidak_lulus' => 'Tidak Lulus',
            'remedial' => 'Remedial',
        ][$this->result] ?? $this->result;
    }
}
