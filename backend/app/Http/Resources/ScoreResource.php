<?php

namespace App\Http\Resources;

use App\Models\ScoreRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Skor mentah satu siswa pada satu penilaian.
 */
class ScoreResource extends JsonResource
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
            'score' => $this->score === null ? null : (float) $this->score,
            'grade' => $this->grade,
            'predicate' => $this->predicate,
            'notes' => $this->notes,

            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'nis' => $this->student->nis,
                'full_name' => $this->student->full_name,
            ] : null),

            // Riwayat perubahan — bukti bahwa tidak ada nilai yang berubah diam-diam.
            'revisions' => $this->whenLoaded('revisions', fn () => $this->revisions->map(
                fn (ScoreRevision $revision) => [
                    'id' => $revision->id,
                    'old_score' => $revision->old_score === null ? null : (float) $revision->old_score,
                    'new_score' => $revision->new_score === null ? null : (float) $revision->new_score,
                    'reason' => $revision->reason,
                    'revised_by' => $revision->revised_by,
                    'revised_at' => $revision->created_at?->toIso8601String(),
                ]
            )->values()),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
