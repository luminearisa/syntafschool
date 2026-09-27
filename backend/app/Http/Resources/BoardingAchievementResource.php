<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prestasi santri di asrama (§41) — kabar baik yang juga dikirim ke portal
 * orang tua, penyeimbang pelanggaran di laporan poin.
 */
class BoardingAchievementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'student_id' => (int) $this->student_id,
            'student' => $this->when(
                $this->relationLoaded('student') && $this->student !== null,
                fn (): array => [
                    'id' => (int) $this->student->id,
                    'full_name' => $this->student->full_name,
                    'nis' => $this->student->nis,
                ],
            ),
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'level' => $this->level,
            'level_label' => $this->levelLabel(),
            'date' => $this->date?->toDateString(),
            'evidence_path' => $this->evidence_path,
            'recorded_by' => $this->recorded_by === null ? null : (int) $this->recorded_by,
            'recorded_by_name' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
