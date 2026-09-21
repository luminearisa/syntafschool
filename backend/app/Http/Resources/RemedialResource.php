<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Catatan remedial satu siswa.
 */
class RemedialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'student_id' => $this->student_id,
            'score_before' => $this->score_before === null ? null : (float) $this->score_before,
            'score_after' => $this->score_after === null ? null : (float) $this->score_after,
            'improvement' => $this->improvement(),
            'date' => $this->date?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->score_after === null ? 'pending' : 'done',

            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'nis' => $this->student->nis,
                'full_name' => $this->student->full_name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
