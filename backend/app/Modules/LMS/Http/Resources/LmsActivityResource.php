<?php

namespace App\Modules\LMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representasi aktivitas untuk API (§53).
 *
 * Konfigurasi tipe dikirim ringkas sebagai `detail`; halaman pengerjaan memakai
 * endpoint tipe (`/assignments/{id}`, `/quizzes/{id}`, …) agar payload siswa
 * selalu bebas kunci jawaban (§28).
 */
class LmsActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'section_id' => $this->section_id === null ? null : (int) $this->section_id,
            'learning_item_id' => (int) $this->learning_item_id,
            'activity_type' => $this->activity_type?->value,
            'activity_type_label' => $this->activity_type?->label(),
            'title' => (string) $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'visibility' => $this->visibility?->value,
            'start_at' => $this->start_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'close_at' => $this->close_at?->toIso8601String(),
            'completion_rule' => $this->completion_rule?->value,
            'completion_min_grade' => $this->completion_min_grade === null ? null : (float) $this->completion_min_grade,
            'availability' => $this->availability,
            'is_graded' => (bool) $this->is_graded,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_by' => $this->created_by === null ? null : (int) $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'player_url' => $this->playerUrl(),
            'learning_item' => $this->whenLoaded('learningItem', fn () => [
                'id' => (int) $this->learningItem->id,
                'title' => (string) $this->learningItem->title,
                'item_type' => $this->learningItem->item_type?->value,
                'is_required' => (bool) $this->learningItem->is_required,
                'sort_order' => (int) $this->learningItem->sort_order,
                'estimated_minutes' => $this->learningItem->estimated_minutes,
                'visibility' => $this->learningItem->visibility?->value,
            ]),
            'detail' => $this->when(true, fn () => $this->detailSummary()),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function detailSummary(): ?array
    {
        return match ($this->activity_type?->value) {
            'ASSIGNMENT' => $this->assignment === null ? null : [
                'submission_type' => $this->assignment->submission_type?->value,
                'maximum_grade' => (float) $this->assignment->maximum_grade,
                'passing_grade' => $this->assignment->passing_grade === null ? null : (float) $this->assignment->passing_grade,
                'maximum_attempts' => $this->assignment->maxAttempts(),
                'allow_late_submission' => (bool) $this->assignment->allow_late_submission,
                'late_penalty_percent' => (float) $this->assignment->late_penalty_percent,
                'submissions_count' => $this->assignment->submissions()->count(),
            ],
            'QUIZ' => $this->quiz === null ? null : [
                'time_limit_minutes' => $this->quiz->time_limit_minutes,
                'attempts_allowed' => $this->quiz->attemptsAllowed(),
                'maximum_grade' => (float) $this->quiz->maximum_grade,
                'passing_grade' => $this->quiz->passing_grade === null ? null : (float) $this->quiz->passing_grade,
                'navigation_method' => $this->quiz->navigation_method?->value,
                'questions_count' => $this->quiz->questions()->count(),
            ],
            'FORUM' => $this->forum === null ? null : [
                'forum_type' => $this->forum->forum_type?->value,
                'moderation_enabled' => (bool) $this->forum->moderation_enabled,
                'required_posts' => (int) $this->forum->required_posts,
                'discussions_count' => $this->forum->discussions()->count(),
            ],
            'LESSON' => $this->lesson === null ? null : [
                'pages_count' => $this->lesson->pages()->count(),
                'require_all_pages' => (bool) $this->lesson->require_all_pages,
            ],
            'CHOICE' => $this->choice === null ? null : [
                'allow_multiple' => (bool) $this->choice->allow_multiple,
                'anonymous' => (bool) $this->choice->anonymous,
                'options_count' => $this->choice->options()->count(),
                'responses_count' => $this->choice->responses()->count(),
            ],
            'SURVEY' => $this->survey === null ? null : [
                'anonymous' => (bool) $this->survey->anonymous,
                'questions_count' => $this->survey->questions()->count(),
                'responses_count' => $this->survey->responses()->count(),
            ],
            default => null,
        };
    }
}
