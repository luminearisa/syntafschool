<?php

namespace App\Http\Resources;

use App\Models\TahfizTasmiRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizTasmiRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'participant_id' => $this->participant_id,
            'date' => $this->date?->toDateString(),
            'juz_from' => $this->juz_from,
            'juz_to' => $this->juz_to,
            'juz_count' => $this->juzCount(),
            'duration_minutes' => $this->duration_minutes,
            'mistakes_count' => $this->mistakes_count,
            'score' => $this->score !== null ? (float) $this->score : null,
            'examiner_id' => $this->examiner_id,
            'result' => $this->result,
            'result_label' => $this->resultLabel(),
            'is_passed' => in_array($this->result, [
                TahfizTasmiRecord::RESULT_LULUS,
                TahfizTasmiRecord::RESULT_LULUS_DENGAN_CATATAN,
            ], true),
            'notes' => $this->notes,
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ]),
            'examiner' => $this->whenLoaded('examiner', fn () => [
                'id' => $this->examiner->id,
                'full_name' => $this->examiner->full_name,
            ]),
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function juzCount(): int
    {
        return max(0, (int) $this->juz_to - (int) $this->juz_from + 1);
    }

    protected function resultLabel(): string
    {
        return [
            'lulus' => 'Lulus',
            'lulus_dengan_catatan' => 'Lulus dengan Catatan',
            'ulang' => 'Ulang',
        ][$this->result] ?? $this->result;
    }
}
