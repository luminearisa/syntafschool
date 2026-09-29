<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\GradeReviewStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsAssessmentResult;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsGradeReview;
use App\Modules\LMS\Models\LmsGradeReviewEvent;
use Illuminate\Support\Facades\DB;

/**
 * Banding/review nilai (§20, §59).
 *
 * Alur: siswa mengajukan → guru meninjau → setujui/tolak → bila disetujui,
 * nilai dikoreksi lewat `GradeCorrectionService` sehingga tetap meninggalkan
 * jejak audit yang immutable (§19, §53).
 */
class GradeReviewService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly GradeCorrectionService $corrections,
        private readonly GradeNotifier $notifier,
    ) {}

    /**
     * Siswa mengajukan banding untuk sebuah nilai (§20).
     *
     * @return array<string, mixed>
     */
    public function request(
        LmsGradeItem $item,
        int $userId,
        string $reason,
        LmsActor $actor,
        ?float $requestedScore = null,
    ): LmsGradeReview {
        if (trim($reason) === '') {
            LmsFault::invalid('Alasan pengajuan banding wajib diisi.', 'reason');
        }

        if ($userId !== $actor->id && ! $actor->can('lms.grade.review')) {
            LmsFault::forbidden('Anda hanya dapat mengajukan banding untuk nilai Anda sendiri.');
        }

        $result = $item->resultFor($userId);

        if ($result === null || ! $result->isVisibleToStudent()) {
            LmsFault::invalid('Nilai ini belum dirilis sehingga belum dapat dibanding.', 'grade_item_id');
        }

        $open = LmsGradeReview::query()
            ->where('grade_item_id', $item->id)
            ->where('user_id', $userId)
            ->whereIn('status', [GradeReviewStatus::PENDING->value, GradeReviewStatus::UNDER_REVIEW->value])
            ->exists();

        if ($open) {
            LmsFault::conflict('Masih ada pengajuan banding yang sedang diproses untuk nilai ini.');
        }

        $review = DB::transaction(function () use ($item, $userId, $reason, $requestedScore, $actor, $result): LmsGradeReview {
            $review = LmsGradeReview::query()->create([
                'gradebook_id' => $result->gradebook_id,
                'grade_item_id' => $item->id,
                'assessment_result_id' => $result->id,
                'user_id' => $userId,
                'student_id' => $result->student_id,
                'status' => GradeReviewStatus::PENDING,
                'reason' => $reason,
                'current_score' => $result->final_score,
                'requested_score' => $requestedScore,
                'assigned_to' => $this->teacherIdFor($item),
            ]);

            $this->event($review, 'REQUESTED', null, GradeReviewStatus::PENDING, $reason, $actor);

            return $review;
        });

        $this->audit('lms.grade_review.requested', $review, ['grade_item_id' => (int) $item->id, 'user_id' => $userId]);

        $teacherIds = array_values(array_filter([$review->assigned_to === null ? null : (int) $review->assigned_to]));

        $this->notifier->reviewRequested($teacherIds === [] ? $this->courseTeacherIds($item) : $teacherIds, [
            'title' => 'Pengajuan banding nilai',
            'message' => sprintf('Ada pengajuan banding nilai untuk "%s".', $item->name),
            'grade_item_id' => (int) $item->id,
            'grade_review_id' => (int) $review->id,
            'url' => '/lms/activities/'.($item->activity_id ?? '').'/manage',
        ]);

        return $review->refresh()->load('events');
    }

    /** Guru mulai meninjau (§20). */
    public function start(LmsGradeReview $review, LmsActor $actor): LmsGradeReview
    {
        $this->assertTeacher($review, $actor, 'lms.grade.review');

        if ($review->status !== GradeReviewStatus::PENDING) {
            LmsFault::conflict('Banding ini sudah ditinjau.');
        }

        $review->fill(['status' => GradeReviewStatus::UNDER_REVIEW])->save();
        $this->event($review, 'UNDER_REVIEW', GradeReviewStatus::PENDING, GradeReviewStatus::UNDER_REVIEW, null, $actor);

        return $review->refresh();
    }

    /**
     * Setujui banding → nilai dikoreksi dengan jejak audit (§20, §59).
     *
     * @return array<string, mixed>
     */
    public function approve(LmsGradeReview $review, float $newScore, string $note, LmsActor $actor): array
    {
        $this->assertTeacher($review, $actor, 'lms.grade.approve');

        if (! $review->isOpen()) {
            LmsFault::conflict('Banding ini sudah diputuskan.');
        }

        $item = $review->item()->firstOrFail();
        $result = $review->result()->firstOrFail();

        $correction = $this->corrections->correctFromReview($result, $newScore, 'Banding disetujui: '.$note, $actor);

        $review->fill([
            'status' => GradeReviewStatus::APPROVED,
            'decision' => 'APPROVED',
            'decided_score' => $newScore,
            'teacher_note' => $note,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ])->save();

        $this->event($review, 'APPROVED', GradeReviewStatus::UNDER_REVIEW, GradeReviewStatus::APPROVED, $note, $actor, [
            'previous_score' => $correction['previous'],
            'new_score' => $newScore,
        ]);

        $this->event($review, 'ADJUSTED', GradeReviewStatus::APPROVED, GradeReviewStatus::APPROVED, $note, $actor, [
            'previous_score' => $correction['previous'],
            'new_score' => $newScore,
        ]);

        $this->audit('lms.grade_review.approved', $review, ['new_score' => $newScore]);

        $this->notifier->reviewDecided([(int) $review->user_id], [
            'title' => 'Banding nilai disetujui',
            'message' => sprintf('Banding untuk "%s" disetujui. Nilai baru: %s.', $item->name, $newScore),
            'grade_review_id' => (int) $review->id,
            'grade_item_id' => (int) $item->id,
            'url' => '/lms/grades',
        ]);

        return [
            'review' => $review->refresh()->load('events'),
            'correction' => $correction,
        ];
    }

    /** Tolak banding (§20). */
    public function reject(LmsGradeReview $review, string $note, LmsActor $actor): LmsGradeReview
    {
        $this->assertTeacher($review, $actor, 'lms.grade.approve');

        if (! $review->isOpen()) {
            LmsFault::conflict('Banding ini sudah diputuskan.');
        }

        $review->fill([
            'status' => GradeReviewStatus::REJECTED,
            'decision' => 'REJECTED',
            'teacher_note' => $note,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ])->save();

        $this->event($review, 'REJECTED', GradeReviewStatus::UNDER_REVIEW, GradeReviewStatus::REJECTED, $note, $actor);
        $this->audit('lms.grade_review.rejected', $review, []);

        $item = $review->item()->first();

        $this->notifier->reviewDecided([(int) $review->user_id], [
            'title' => 'Banding nilai ditolak',
            'message' => sprintf('Banding untuk "%s" belum dapat dikabulkan. %s', (string) ($item?->name ?? 'nilai'), $note),
            'grade_review_id' => (int) $review->id,
            'url' => '/lms/grades',
        ]);

        return $review->refresh();
    }

    public function close(LmsGradeReview $review, LmsActor $actor, ?string $note = null): LmsGradeReview
    {
        $this->assertTeacher($review, $actor, 'lms.grade.review');

        $from = $review->status;
        $review->fill(['status' => GradeReviewStatus::CLOSED, 'closed_at' => now()])->save();
        $this->event($review, 'CLOSED', $from, GradeReviewStatus::CLOSED, $note, $actor);

        return $review->refresh();
    }

    /** Tambah catatan pada proses banding (tanpa mengubah status). */
    public function comment(LmsGradeReview $review, string $note, LmsActor $actor): LmsGradeReviewEvent
    {
        if ($actor->id !== (int) $review->user_id && ! $actor->can('lms.grade.review')) {
            LmsFault::forbidden('Anda tidak berhak menambahkan catatan pada banding ini.');
        }

        if ($actor->id === (int) $review->user_id) {
            $review->student_note = $note;
            $review->save();
        } else {
            $review->teacher_note = $note;
            $review->save();
        }

        return $this->event($review, 'COMMENTED', $review->status, $review->status, $note, $actor);
    }

    /** Banding milik seorang siswa (§59 siswa). @return list<array<string, mixed>> */
    public function forStudent(int $userId): array
    {
        return LmsGradeReview::query()
            ->where('user_id', $userId)
            ->with(['item', 'events'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LmsGradeReview $review) => $this->payload($review, includeTeacherNotes: true))
            ->all();
    }

    /**
     * Antrean banding untuk guru pada sebuah item/gradebook (§59 guru).
     *
     * @return list<array<string, mixed>>
     */
    public function queue(?LmsGradeItem $item = null, ?int $gradebookId = null, ?LmsActor $actor = null): array
    {
        return LmsGradeReview::query()
            ->when($item !== null, fn ($query) => $query->where('grade_item_id', $item->id))
            ->when($item === null && $gradebookId !== null, fn ($query) => $query->where('gradebook_id', $gradebookId))
            ->with(['item', 'events'])
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 WHEN status = 'UNDER_REVIEW' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->get()
            ->map(fn (LmsGradeReview $review) => $this->payload($review, includeTeacherNotes: true))
            ->all();
    }

    /**
     * Banding anak untuk wali — hanya bila policy mengizinkan (§33, §53).
     *
     * @param  list<int>  $childUserIds
     * @return list<array<string, mixed>>
     */
    public function forChildren(array $childUserIds): array
    {
        if (! (bool) config('lms.parent.can_view_grades', true)) {
            return [];
        }

        return LmsGradeReview::query()
            ->whereIn('user_id', $childUserIds)
            ->where('is_parent_visible', true)
            ->with('item')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LmsGradeReview $review) => $this->payload($review, includeTeacherNotes: false))
            ->all();
    }

    /** @return array<string, mixed> */
    public function payload(LmsGradeReview $review, bool $includeTeacherNotes = true): array
    {
        $review->loadMissing(['item', 'events', 'result']);

        return [
            'id' => (int) $review->id,
            'gradebook_id' => (int) $review->gradebook_id,
            'grade_item_id' => $review->grade_item_id === null ? null : (int) $review->grade_item_id,
            'grade_item_name' => $review->item?->name,
            'user_id' => (int) $review->user_id,
            'status' => $review->status->value,
            'status_label' => $review->status->label(),
            'status_tone' => $review->status->tone(),
            'reason' => $review->reason,
            'current_score' => $review->current_score === null ? null : (float) $review->current_score,
            'requested_score' => $review->requested_score === null ? null : (float) $review->requested_score,
            'decided_score' => $review->decided_score === null ? null : (float) $review->decided_score,
            'decision' => $review->decision,
            'teacher_note' => $includeTeacherNotes ? $review->teacher_note : null,
            'student_note' => $review->student_note,
            'is_parent_visible' => (bool) $review->is_parent_visible,
            'decided_at' => $review->decided_at?->toIso8601String(),
            'created_at' => $review->created_at?->toIso8601String(),
            'events' => $review->events->map(fn (LmsGradeReviewEvent $event) => [
                'action' => $event->action,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'note' => $event->note,
                'meta' => $event->meta,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $meta */
    private function event(
        LmsGradeReview $review,
        string $action,
        ?GradeReviewStatus $from,
        ?GradeReviewStatus $to,
        ?string $note,
        LmsActor $actor,
        array $meta = [],
    ): LmsGradeReviewEvent {
        return LmsGradeReviewEvent::query()->create([
            'grade_review_id' => $review->id,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'note' => $note,
            'meta' => $meta === [] ? null : $meta,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function assertTeacher(LmsGradeReview $review, LmsActor $actor, string $permission): void
    {
        if (! $actor->can($permission) && ! $actor->can('lms.grade.review')) {
            LmsFault::forbidden('Anda tidak memiliki izin meninjau banding nilai.');
        }

        if ($actor->id === (int) $review->user_id) {
            LmsFault::forbidden('Pengaju banding tidak dapat memutuskan bandingnya sendiri.');
        }
    }

    private function teacherIdFor(LmsGradeItem $item): ?int
    {
        $teacherId = $this->itemCourse($item)?->teachers()->whereNotNull('user_id')->value('user_id');

        return $teacherId === null ? null : (int) $teacherId;
    }

    /** @return list<int> */
    private function courseTeacherIds(LmsGradeItem $item): array
    {
        return $this->itemCourse($item)?->teachers()->whereNotNull('user_id')->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all() ?? [];
    }

    private function itemCourse(LmsGradeItem $item): ?\App\Modules\LMS\Models\LmsCourse
    {
        $gradebook = $item->relationLoaded('gradebook') ? $item->gradebook : $item->gradebook()->first();

        return $gradebook?->course ?? $gradebook?->course()->first();
    }
}
