<?php

namespace App\Modules\Promotion\Http\Resources;

use App\Modules\Promotion\Models\PromotionRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PromotionRule $rule */
        $rule = $this->resource;

        return [
            'id' => (int) $rule->id,
            'name' => $rule->name,
            'education_level_id' => $rule->education_level_id === null ? null : (int) $rule->education_level_id,
            'academic_year_id' => (int) $rule->academic_year_id,
            'min_average_score' => (float) $rule->min_average_score,
            'min_attendance_percent' => (float) $rule->min_attendance_percent,
            'max_unpassed_subjects' => (int) $rule->max_unpassed_subjects,
            'min_passing_grade' => (float) $rule->min_passing_grade,
            'violation_point_limit' => $rule->violation_point_limit === null ? null : (int) $rule->violation_point_limit,
            'is_active' => (bool) $rule->is_active,
            'education_level' => $this->whenLoaded('educationLevel', fn () => $rule->educationLevel ? [
                'id' => (int) $rule->educationLevel->id,
                'name' => $rule->educationLevel->name,
                'level_number' => (int) $rule->educationLevel->level_number,
            ] : null),
            'academic_year' => $this->whenLoaded('academicYear', fn () => $rule->academicYear ? [
                'id' => (int) $rule->academicYear->id,
                'name' => $rule->academicYear->name,
            ] : null),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }
}
