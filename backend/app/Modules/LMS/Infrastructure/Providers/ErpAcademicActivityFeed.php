<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Score;
use App\Modules\LMS\Domain\Contracts\AcademicActivityFeedInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Integrated Mode: aktivitas akademik dibaca dari modul Assignment & Assessment
 * ERP (§27), tanpa menduplikasi tugas ke tabel LMS. Phase 2 akan menautkan
 * `lms_learning_items.external_activity_id` ke baris `assignments` ini.
 */
class ErpAcademicActivityFeed implements AcademicActivityFeedInterface
{
    public function upcomingForStudent(int $studentId, int $limit = 5): array
    {
        $classIds = \App\Models\ClassStudent::query()
            ->where('student_id', $studentId)
            ->pluck('school_class_id');

        if ($classIds->isEmpty()) {
            return [];
        }

        return $this->upcomingForClasses($classIds->map('intval')->all(), $limit);
    }

    public function pendingGrading(?int $employeeId, int $limit = 10): array
    {
        return $this->submissionQuery($employeeId)
            ->whereIn('status', [
                AssignmentSubmission::STATUS_SUBMITTED,
                AssignmentSubmission::STATUS_LATE,
            ])
            ->orderBy('submitted_at')
            ->limit($limit)
            ->get()
            ->map(fn (AssignmentSubmission $submission) => $this->mapSubmission($submission))
            ->all();
    }

    public function recentSubmissions(?int $employeeId, int $limit = 10): array
    {
        return $this->submissionQuery($employeeId)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->limit($limit)
            ->get()
            ->map(fn (AssignmentSubmission $submission) => $this->mapSubmission($submission))
            ->all();
    }

    public function upcomingForClasses(array $classIds, int $limit = 5): array
    {
        if ($classIds === []) {
            return [];
        }

        return Assignment::query()
            ->with(['subject:id,name', 'schoolClass:id,name'])
            ->whereIn('class_id', $classIds)
            ->published()
            ->whereDate('due_date', '>=', now()->toDateString())
            ->orderBy('due_date')
            ->limit($limit)
            ->get()
            ->map(fn (Assignment $assignment) => [
                'id' => (int) $assignment->id,
                'title' => (string) $assignment->title,
                'type' => $assignment->type,
                'subject' => $assignment->subject?->name,
                'class_name' => $assignment->schoolClass?->name,
                'due_date' => $assignment->due_date?->toDateString(),
                'days_left' => $assignment->due_date !== null ? (int) now()->startOfDay()->diffInDays($assignment->due_date->startOfDay(), false) : null,
                'source' => 'siakad',
            ])
            ->all();
    }

    public function gradesForStudent(int $studentId): array
    {
        $scores = Score::query()
            ->with(['assessment:id,name,subject_id,max_score', 'assessment.subject:id,name'])
            ->where('student_id', $studentId)
            ->whereNotNull('score')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        if ($scores->isEmpty()) {
            return ['count' => 0, 'average' => null, 'recent' => []];
        }

        $percentages = $scores->map(function (Score $score) {
            $max = (float) ($score->assessment?->max_score ?? 100);

            return $max > 0 ? round((float) $score->score / $max * 100, 2) : null;
        })->filter(fn ($value) => $value !== null);

        return [
            'count' => $scores->count(),
            'average' => $percentages->isEmpty() ? null : round($percentages->avg(), 2),
            'recent' => $scores->take(8)->map(fn (Score $score) => [
                'id' => (int) $score->id,
                'assessment' => $score->assessment?->name,
                'subject' => $score->assessment?->subject?->name,
                'score' => (float) $score->score,
                'max_score' => (float) ($score->assessment?->max_score ?? 100),
                'grade' => $score->grade,
                'date' => $score->updated_at?->toDateString(),
            ])->all(),
            'source' => 'siakad',
        ];
    }

    public function gradingCounters(?int $employeeId): array
    {
        $query = $this->submissionQuery($employeeId);

        return [
            'pending' => (int) (clone $query)->whereIn('status', ['submitted', 'late'])->count(),
            'graded' => (int) (clone $query)->where('status', 'graded')->count(),
            'total' => (int) (clone $query)->count(),
        ];
    }

    /** @return Builder<AssignmentSubmission> */
    protected function submissionQuery(?int $employeeId): Builder
    {
        return AssignmentSubmission::query()
            ->with(['assignment:id,title,class_id,subject_id,due_date,teacher_id', 'student:id,full_name,nis'])
            ->when($employeeId !== null, fn (Builder $q) => $q->whereHas(
                'assignment',
                fn (Builder $inner) => $inner->where('teacher_id', $employeeId),
            ));
    }

    /** @return array<string, mixed> */
    protected function mapSubmission(AssignmentSubmission $submission): array
    {
        return [
            'id' => (int) $submission->id,
            'assignment_id' => (int) $submission->assignment_id,
            'title' => $submission->assignment?->title,
            'student_id' => $submission->student_id !== null ? (int) $submission->student_id : null,
            'student_name' => $submission->student?->full_name,
            'status' => $submission->status,
            'score' => $submission->score !== null ? (float) $submission->score : null,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'graded_at' => $submission->graded_at?->toIso8601String(),
            'due_date' => $submission->assignment?->due_date?->toDateString(),
            'source' => 'siakad',
        ];
    }
}
