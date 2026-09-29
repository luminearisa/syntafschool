<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Enums\AssessmentResultStatus;
use App\Modules\LMS\Domain\Enums\GradeHistoryAction;
use App\Modules\LMS\Domain\Enums\GradeItemVisibility;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityGrade;
use App\Modules\LMS\Models\LmsAssessmentResult;
use App\Modules\LMS\Models\LmsGradeHistory;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsGradebook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penulis tunggal `lms_assessment_results` (§12–§13).
 *
 * Semua nilai — dari tugas, kuis, penilaian manual, maupun rubric — masuk lewat
 * service ini supaya tidak ada dua jalur penulisan yang bisa berbeda hasil.
 * Setiap perubahan meninggalkan `lms_grade_histories` (immutable).
 */
class AssessmentResultService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly GradeItemService $items,
        private readonly GradebookService $gradebooks,
        private readonly GradeNotifier $notifier,
    ) {}

    /** Ambil (buat bila perlu) hasil penilaian tanpa mengubah nilai. */
    public function ensure(LmsGradeItem $item, int $userId): LmsAssessmentResult
    {
        $existing = LmsAssessmentResult::query()
            ->where('grade_item_id', $item->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return LmsAssessmentResult::query()->create([
            'gradebook_id' => $item->gradebook_id,
            'grade_item_id' => $item->id,
            'course_id' => $item->gradebook()->firstOrFail()->course_id,
            'user_id' => $userId,
            'student_id' => $this->studentIdFor($item, $userId),
            'source' => $item->source,
            'maximum_score' => $item->maximum_grade,
            'status' => AssessmentResultStatus::PENDING,
            'is_extra_credit' => (bool) $item->is_extra_credit,
            'attempt' => 1,
        ]);
    }

    /**
     * Tarik nilai aktivitas (Phase 2) ke gradebook (§13, §61).
     *
     * Idempoten: memanggil ulang hanya memperbarui baris yang sudah ada.
     */
    public function pullFromActivityGrade(LmsActivityGrade $grade, ?LmsActor $actor = null): ?LmsAssessmentResult
    {
        $activity = $grade->activity;

        if ($activity === null) {
            $activity = LmsActivity::query()->find($grade->activity_id);
        }

        if ($activity === null) {
            return null;
        }

        $item = $this->items->ensureForActivity($activity, $actor?->id);
        $this->gradebooks->assertEditable($item->gradebook()->firstOrFail(), $actor ?? $this->systemActor());

        return $this->record($item, (int) $grade->user_id, [
            'raw_score' => $grade->raw_grade ?? $grade->grade,
            'final_score' => $grade->grade,
            'maximum_score' => $grade->maximum_grade,
            'feedback' => $grade->feedback,
            'graded_by' => $grade->graded_by ?? null,
            'graded_at' => $grade->graded_at,
            'status' => AssessmentResultStatus::GRADED,
            'activity_grade_id' => (int) $grade->id,
            'metadata' => ['source' => 'activity_grade', 'activity_id' => (int) $activity->id],
        ], $actor);
    }

    /**
     * Tarik seluruh nilai aktivitas pada satu gradebook (tombol "Tarik nilai").
     */
    public function pullFromActivities(LmsGradebook $gradebook, ?LmsActor $actor = null): int
    {
        $this->gradebooks->assertEditable($gradebook, $actor ?? $this->systemActor());
        $this->items->syncFromCourse($gradebook, $actor?->id);

        $activityIds = LmsGradeItem::query()
            ->where('gradebook_id', $gradebook->id)
            ->whereNotNull('activity_id')
            ->pluck('activity_id')
            ->all();

        if ($activityIds === []) {
            return 0;
        }

        $count = 0;

        foreach (LmsActivityGrade::query()->whereIn('activity_id', $activityIds)->get() as $grade) {
            if ($this->pullFromActivityGrade($grade, $actor) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Simpan nilai manual/penilaian guru untuk satu item (§13, §17).
     *
     * @param  array<string, mixed>  $data
     */
    public function saveManual(LmsGradeItem $item, int $userId, array $data, LmsActor $actor): LmsAssessmentResult
    {
        return $this->record($item, $userId, $data, $actor);
    }

    /**
     * Nilai massal (mis. satu kolom penuh di gradebook guru §34).
     *
     * @param  array<int, array<string, mixed>>  $entries  user_id => data
     * @return list<LmsAssessmentResult>
     */
    public function bulkSave(LmsGradeItem $item, array $entries, LmsActor $actor): array
    {
        $results = DB::transaction(function () use ($item, $entries, $actor): array {
            $out = [];

            foreach ($entries as $userId => $data) {
                $out[] = $this->record($item, (int) $userId, $data, $actor);
            }

            return $out;
        });

        $this->audit('lms.grade.bulk_saved', $item, ['count' => count($results)]);

        return $results;
    }

    /** Bebaskan siswa dari sebuah item tanpa dianggap nol (§10). */
    public function exempt(LmsGradeItem $item, int $userId, string $reason, LmsActor $actor): LmsAssessmentResult
    {
        $this->assertEditable($item, $actor);

        if (trim($reason) === '') {
            LmsFault::invalid('Alasan pembebasan wajib diisi.', 'reason');
        }

        $result = $this->ensure($item, $userId);
        $previous = $this->snapshot($result);

        $result->fill([
            'is_exempt' => true,
            'status' => AssessmentResultStatus::EXEMPT,
            'exemption_reason' => $reason,
            'exempted_by' => $actor->id,
            'exempted_at' => now(),
        ])->save();

        $this->history($result, GradeHistoryAction::EXEMPTED, $previous, $this->snapshot($result), $reason, $actor);

        return $result;
    }

    public function unexempt(LmsAssessmentResult $result, LmsActor $actor): LmsAssessmentResult
    {
        $item = $result->item()->firstOrFail();
        $this->assertEditable($item, $actor);

        $previous = $this->snapshot($result);

        $result->fill([
            'is_exempt' => false,
            'status' => $result->final_score === null ? AssessmentResultStatus::PENDING : AssessmentResultStatus::GRADED,
            'exemption_reason' => null,
            'exempted_by' => null,
            'exempted_at' => null,
        ])->save();

        $this->history($result, GradeHistoryAction::UNEXEMPTED, $previous, $this->snapshot($result), null, $actor);

        return $result;
    }

    /**
     * Kembalikan hasil ke siswa (umpan balik terlihat, belum masuk rilis akhir §17).
     *
     * @param  list<int>  $userIds
     */
    public function returnToStudents(LmsGradeItem $item, array $userIds, LmsActor $actor): int
    {
        $count = 0;

        foreach ($this->resultsFor($item, $userIds) as $result) {
            if (! $result->status->allowsGradeChange()) {
                continue;
            }

            $previous = $this->snapshot($result);
            $result->fill(['status' => AssessmentResultStatus::RETURNED, 'returned_at' => now()])->save();
            $this->history($result, GradeHistoryAction::RETURNED, $previous, $this->snapshot($result), null, $actor);
            $count++;
        }

        return $count;
    }

    /** Kunci satu hasil (§42) — perubahan berikutnya butuh izin & alasan. */
    public function lock(LmsAssessmentResult $result, LmsActor $actor): LmsAssessmentResult
    {
        $previous = $this->snapshot($result);
        $result->fill(['status' => AssessmentResultStatus::LOCKED, 'locked_at' => now()])->save();
        $this->history($result, GradeHistoryAction::LOCKED, $previous, $this->snapshot($result), null, $actor);

        return $result;
    }

    /** Hasil milik satu item untuk beberapa siswa. */
    public function resultsFor(LmsGradeItem $item, array $userIds = []): Collection
    {
        $query = LmsAssessmentResult::query()->where('grade_item_id', $item->id);

        if ($userIds !== []) {
            $query->whereIn('user_id', $userIds);
        }

        return $query->get();
    }

    /**
     * Rombak inti: validasi aturan lalu simpan + catat riwayat.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(LmsGradeItem $item, int $userId, array $data, ?LmsActor $actor = null): LmsAssessmentResult
    {
        $actor ??= $this->systemActor();
        $this->assertEditable($item, $actor);

        $gradebook = $item->gradebook()->firstOrFail();
        $result = $this->ensure($item, $userId);
        $previous = $this->snapshot($result);

        if (! $result->status->allowsGradeChange() && ! $actor->can('lms.grade.correct')) {
            LmsFault::forbidden('Nilai ini sudah dikunci/dibebaskan dan hanya dapat diubah dengan izin koreksi nilai.');
        }

        $final = $data['final_score'] ?? $data['score'] ?? null;
        $maximum = (float) ($data['maximum_score'] ?? $item->maximum_grade);
        $status = $data['status'] ?? null;

        if ($final !== null) {
            $final = (float) $final;

            if ($final < 0) {
                LmsFault::invalid('Nilai tidak boleh negatif.', 'final_score');
            }

            if ($maximum <= 0) {
                LmsFault::invalid('Nilai maksimum item harus lebih dari 0.', 'maximum_score');
            }

            // §3: nilai mentah boleh melebihi maksimum hanya bila item extra credit.
            if ($final > $maximum + 0.001 && ! $item->is_extra_credit) {
                LmsFault::invalid(sprintf(
                    'Nilai tidak boleh melebihi nilai maksimum (%s).',
                    rtrim(rtrim(number_format($maximum, 2, '.', ''), '0'), '.'),
                ), 'final_score');
            }
        }

        $raw = $data['raw_score'] ?? $final;

        $result->fill([
            'student_id' => $data['student_id'] ?? $result->student_id ?? $this->studentIdFor($item, $userId),
            'source' => $item->source,
            'activity_grade_id' => $data['activity_grade_id'] ?? $result->activity_grade_id,
            'raw_score' => $raw,
            'maximum_score' => $maximum,
            'final_score' => $final,
            'normalized_score' => $final === null ? null : round($final / $maximum * 100, 4),
            'percent' => $final === null ? null : round($final / $maximum * 100, 2),
            'status' => $status ?? ($final === null ? AssessmentResultStatus::PENDING : AssessmentResultStatus::GRADED),
            'is_extra_credit' => (bool) $item->is_extra_credit,
            'feedback' => $data['feedback'] ?? $result->feedback,
            'feedback_private' => $data['feedback_private'] ?? $result->feedback_private,
            'feedback_meta' => $data['feedback_meta'] ?? $result->feedback_meta,
            'feedback_attachment' => $data['feedback_attachment'] ?? $result->feedback_attachment,
            'graded_by' => $data['graded_by'] ?? $actor->id,
            'graded_at' => $data['graded_at'] ?? ($final === null ? $result->graded_at : now()),
            'metadata' => $data['metadata'] ?? $result->metadata,
        ]);

        // Rilis otomatis bila gradebook memang sudah dikunci untuk rilis.
        if ($result->status === AssessmentResultStatus::GRADED && $item->visibility === GradeItemVisibility::VISIBLE && $item->released_at !== null) {
            $result->status = AssessmentResultStatus::RELEASED;
            $result->released_at = now();
        }

        $result->save();

        $this->history(
            $result,
            $previous['final_score'] === null ? GradeHistoryAction::CREATED : GradeHistoryAction::UPDATED,
            $previous,
            $this->snapshot($result),
            $data['reason'] ?? null,
            $actor,
        );

        if ($result->isVisibleToStudent() && $previous['final_score'] !== null && $previous['final_score'] !== $result->final_score) {
            $this->notifier->gradeChanged([$userId], [
                'title' => 'Nilai diperbarui',
                'message' => sprintf('Nilai "%s" pada %s diperbarui.', $item->name, (string) $gradebook->name),
                'gradebook_id' => (int) $gradebook->id,
                'grade_item_id' => (int) $item->id,
                'course_id' => (int) $gradebook->course_id,
                'url' => '/lms/courses/'.$gradebook->course_id,
            ]);
        }

        return $result->refresh();
    }

    /** Riwayat nilai sebuah hasil (§19). */
    public function historyFor(LmsAssessmentResult $result): Collection
    {
        return LmsGradeHistory::query()
            ->where('assessment_result_id', $result->id)
            ->orderBy('created_at')
            ->get();
    }

    /** @return array<string, mixed> */
    private function snapshot(LmsAssessmentResult $result): array
    {
        return [
            'final_score' => $result->final_score === null ? null : (float) $result->final_score,
            'raw_score' => $result->raw_score === null ? null : (float) $result->raw_score,
            'maximum_score' => (float) $result->maximum_score,
            'status' => $result->status->value,
            'is_exempt' => (bool) $result->is_exempt,
            'feedback' => $result->feedback,
        ];
    }

    /** @param array<string, mixed> $previous @param array<string, mixed> $new */
    public function history(
        LmsAssessmentResult $result,
        GradeHistoryAction $action,
        array $previous,
        array $new,
        ?string $reason,
        LmsActor $actor,
        string $source = 'TEACHER',
    ): LmsGradeHistory {
        return LmsGradeHistory::query()->create([
            'gradebook_id' => $result->gradebook_id,
            'grade_item_id' => $result->grade_item_id,
            'assessment_result_id' => $result->id,
            'student_id' => $result->student_id,
            'user_id' => $result->user_id,
            'action' => $action,
            'source' => $source,
            'previous_value' => $previous,
            'new_value' => $new,
            'reason' => $reason,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function assertEditable(LmsGradeItem $item, LmsActor $actor): void
    {
        $gradebook = $item->gradebook()->firstOrFail();
        $this->gradebooks->assertEditable($gradebook, $actor);
    }

    /** Nilai tidak boleh ditulis dari luar konteks HTTP (seeder/CLI). */
    public function systemActor(): LmsActor
    {
        return new LmsActor(
            id: 0,
            name: 'Sistem LMS',
            email: null,
            avatarPath: null,
            primaryRole: ActorRole::ADMIN,
            roles: ['sistem'],
            permissions: ['lms.manage', 'lms.grade.edit', 'lms.grade.lock', 'lms.grade.correct'],
            superAdmin: true,
        );
    }

    private function studentIdFor(LmsGradeItem $item, int $userId): ?int
    {
        $gradebook = $item->relationLoaded('gradebook') ? $item->gradebook : $item->gradebook()->first();

        return $gradebook?->course?->enrollmentFor($userId)?->student_id;
    }
}
