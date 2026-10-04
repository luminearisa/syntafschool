<?php

namespace App\Modules\StudentProgress\Http\Resources;

use App\Modules\StudentProgress\Models\StudentAchievement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu prestasi siswa.
 */
class StudentAchievementResource extends JsonResource
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
                    'nis' => $this->student->nis,
                    'full_name' => $this->student->full_name,
                ],
            ),
            'achievement_type_id' => (int) $this->achievement_type_id,
            'achievement_type' => $this->when(
                $this->relationLoaded('achievementType') && $this->achievementType !== null,
                fn (): array => [
                    'id' => (int) $this->achievementType->id,
                    'name' => $this->achievementType->name,
                    'level' => $this->achievementType->level,
                ],
            ),
            'achievement_type_name' => $this->achievementType?->name,
            'title' => $this->title,
            'date' => $this->date?->toDateString(),
            'level' => $this->level,
            'level_label' => $this->levelLabel(),
            'rank' => $this->rank,
            'organizer' => $this->organizer,
            'description' => $this->description,
            'certificate_path' => $this->certificate_path,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
