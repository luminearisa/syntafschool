<?php

namespace App\Http\Resources;

use App\Models\ReportCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'student_id' => (int) $this->student_id,
            'class_id' => (int) $this->school_class_id,
            'semester_id' => (int) $this->semester_id,
            'academic_year_id' => (int) $this->academic_year_id,
            'type' => $this->type,
            'type_label' => ReportCard::TYPE_LABELS[$this->type] ?? $this->type,
            'status' => $this->status,
            'status_label' => ReportCard::STATUS_LABELS[$this->status] ?? $this->status,
            'average_score' => $this->average_score === null ? null : (float) $this->average_score,
            'rank' => $this->rank === null ? null : (int) $this->rank,
            'attendance_summary' => $this->attendance_summary ?? [],
            'notes' => $this->notes,
            'homeroom_note' => $this->homeroom_note,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->published_by === null ? null : (int) $this->published_by,
            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => (int) $this->student->id,
                'nis' => $this->student->nis,
                'full_name' => $this->student->full_name,
            ] : null),
            'school_class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass ? [
                'id' => (int) $this->schoolClass->id,
                'name' => $this->schoolClass->name,
                'code' => $this->schoolClass->code,
            ] : null),
            'semester' => $this->whenLoaded('semester', fn () => $this->semester ? [
                'id' => (int) $this->semester->id,
                'name' => $this->semester->name,
            ] : null),
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->academicYear ? [
                'id' => (int) $this->academicYear->id,
                'name' => $this->academicYear->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'subject_id' => (int) $item->subject_id,
                'subject' => $item->subject ? [
                    'id' => (int) $item->subject->id,
                    'code' => $item->subject->code,
                    'name' => $item->subject->name,
                ] : null,
                'score' => $item->score === null ? null : (float) $item->score,
                'grade' => $item->grade,
                'predicate' => $item->predicate,
                'description' => $item->description,
                'teacher_notes' => $item->teacher_notes,
                'position' => (int) $item->position,
            ])->values()),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
