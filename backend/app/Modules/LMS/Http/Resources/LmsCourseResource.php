<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Kartu course untuk katalog, daftar, dan dashboard. @mixin LmsCourse */
class LmsCourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $stats = $this->additional['stats'] ?? null;

        return [
            'id' => (int) $this->id,
            'category_id' => $this->category_id !== null ? (int) $this->category_id : null,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => (int) $this->category->id,
                'name' => (string) $this->category->name,
                'slug' => (string) $this->category->slug,
            ]),
            'academic_course_id' => $this->academic_course_id !== null ? (int) $this->academic_course_id : null,
            'academic_year_id' => $this->academic_year_id !== null ? (int) $this->academic_year_id : null,
            'semester_id' => $this->semester_id !== null ? (int) $this->semester_id : null,
            'program_id' => $this->program_id !== null ? (int) $this->program_id : null,
            'class_id' => $this->class_id !== null ? (int) $this->class_id : null,
            'teacher_id' => $this->teacher_id !== null ? (int) $this->teacher_id : null,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->when($request->boolean('with_description'), $this->description),
            'learning_objectives' => $this->when($request->boolean('with_description'), $this->learning_objectives),
            'thumbnail' => $this->thumbnail,
            'cover_image' => $this->cover_image,
            'course_type' => $this->course_type?->value,
            'course_type_label' => $this->course_type?->label(),
            'level' => $this->level?->value,
            'level_label' => $this->level?->label(),
            'language' => $this->language,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'visibility' => $this->visibility?->value,
            'visibility_label' => $this->visibility?->label(),
            'enrollment_method' => $this->enrollment_method?->value,
            'is_featured' => (bool) $this->is_featured,
            'max_students' => $this->max_students !== null ? (int) $this->max_students : null,
            'estimated_minutes' => $this->estimated_minutes !== null ? (int) $this->estimated_minutes : null,
            'published_at' => $this->published_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'teachers' => $this->whenLoaded('teachers', fn () => $this->teachers->map(fn ($teacher) => [
                'id' => (int) $teacher->id,
                'user_id' => $teacher->user_id !== null ? (int) $teacher->user_id : null,
                'employee_id' => $teacher->employee_id !== null ? (int) $teacher->employee_id : null,
                'name' => $teacher->name,
                'role' => $teacher->role?->value,
                'is_primary' => (bool) $teacher->is_primary,
            ])->values()->all()),
            'counts' => [
                'students' => $this->whenHas('enrolled_students_count', fn () => (int) $this->enrolled_students_count),
                'sections' => $this->whenHas('sections_count', fn () => (int) $this->sections_count),
                'items' => $this->whenHas('items_count', fn () => (int) $this->items_count),
            ],
            'statistics' => $stats,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
