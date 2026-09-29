<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\AssessmentResultStatus;
use App\Modules\LMS\Domain\Enums\GradeHistoryAction;
use App\Modules\LMS\Domain\Enums\GradeReleaseStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsAssessmentResult;
use App\Modules\LMS\Models\LmsGradeHistory;
use App\Modules\LMS\Models\LmsGradeRelease;
use App\Modules\LMS\Models\LmsGradebook;
use Illuminate\Support\Facades\DB;

/**
 * Rilis nilai (§16–§18) dan penguncian hasil (§42, §44).
 *
 * Sebelum dirilis, siswa tidak melihat apa pun. Snapshot perhitungan disimpan
 * saat rilis sehingga nilai yang sudah keluar tetap dapat diaudit walau
 * konfigurasi gradebook berubah kemudian.
 */
class GradeReleaseService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly FinalGradeService $finalGrades,
        private readonly GradebookService $gradebooks,
        private readonly GradeNotifier $notifier,
        private readonly AssessmentResultService $results,
    ) {}

    /**
     * Rilis nilai akhir seorang siswa (§18).
     *
     * @return array<string, mixed>
     */
    public function releaseStudent(LmsGradebook $gradebook, int $userId, LmsActor $actor, bool $notify = true): array
    {
        $this->assertReleasable($gradebook, $actor, [$userId]);

        $snapshot = $this->finalGrades->snapshotFor($gradebook, $userId);

        if ($snapshot['grade']['normalized'] === null) {
            LmsFault::invalid('Nilai belum dapat dirilis karena belum ada nilai yang dihitung.', 'grades');
        }

        $release = DB::transaction(function () use ($gradebook, $userId, $actor, $snapshot): LmsGradeRelease {
            $release = LmsGradeRelease::query()->updateOrCreate(
                ['gradebook_id' => $gradebook->id, 'user_id' => $userId],
                [
                    'student_id' => $snapshot['grade']['items'][0]['student_id'] ?? null,
                    'status' => GradeReleaseStatus::RELEASED,
                    'final_score' => $snapshot['grade']['final_score'],
                    'normalized_score' => $snapshot['grade']['normalized'],
                    'letter' => $snapshot['grade']['letter'],
                    'predicate' => $snapshot['grade']['predicate'],
                    'passed' => $snapshot['grade']['passed'],
                    'component_snapshot' => $snapshot['component_snapshot'],
                    'calculation_snapshot' => $snapshot['calculation_snapshot'],
                    'config_hash' => $snapshot['config_hash'],
                    'released_by' => $actor->id,
                    'released_at' => now(),
                    'revoked_at' => null,
                    'revoked_by' => null,
                ],
            );

            // Hasil per item yang sudah dinilai ikut terlihat (§17).
            LmsAssessmentResult::query()
                ->where('gradebook_id', $gradebook->id)
                ->where('user_id', $userId)
                ->where('status', AssessmentResultStatus::GRADED->value)
                ->update(['status' => AssessmentResultStatus::RELEASED->value, 'released_at' => now()]);

            return $release;
        });

        $this->writeHistory($release, GradeHistoryAction::RELEASED, $actor, null, [
            'final_score' => $release->final_score === null ? null : (float) $release->final_score,
            'letter' => $release->letter,
        ]);

        $this->audit('lms.grade.released', $release, ['user_id' => $userId]);

        if ($notify) {
            $this->notifier->gradeReleased([$userId], [
                'title' => 'Nilai dirilis',
                'message' => sprintf(
                    'Nilai %s Anda: %s%s',
                    (string) $gradebook->name,
                    $release->normalized_score === null ? '-' : number_format((float) $release->normalized_score, 2),
                    $release->letter === null ? '' : ' ('.$release->letter.')',
                ),
                'gradebook_id' => (int) $gradebook->id,
                'course_id' => (int) $gradebook->course_id,
                'url' => '/lms/grades',
            ]);
        }

        return $this->releasePayload($release);
    }

    /**
     * Rilis sebagian siswa (§18 "release selected").
     *
     * @param  list<int>  $userIds
     * @return array{released: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function releaseSelected(LmsGradebook $gradebook, array $userIds, LmsActor $actor): array
    {
        $this->assertReleasable($gradebook, $actor, $userIds);

        $released = [];
        $skipped = [];

        foreach (array_values(array_unique($userIds)) as $userId) {
            $snapshot = $this->finalGrades->snapshotFor($gradebook, (int) $userId);

            if ($snapshot['grade']['normalized'] === null) {
                $skipped[] = ['user_id' => (int) $userId, 'reason' => 'Belum ada nilai yang dapat dirilis.'];

                continue;
            }

            $released[] = $this->releaseStudent($gradebook, (int) $userId, $actor, notify: true);
        }

        return ['released' => $released, 'skipped' => $skipped];
    }

    /**
     * Rilis seluruh siswa terdaftar (§18 "release all").
     *
     * @param  list<int>  $userIds
     * @return array{released: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function releaseAll(LmsGradebook $gradebook, array $userIds, LmsActor $actor): array
    {
        return $this->releaseSelected($gradebook, $userIds, $actor);
    }

    /** Batalkan rilis (mis. nilai salah masuk) (§18). */
    public function revoke(LmsGradeRelease $release, LmsActor $actor, ?string $reason = null): LmsGradeRelease
    {
        $gradebook = $release->gradebook()->firstOrFail();
        $this->gradebooks->assertEditable($gradebook, $actor);

        $release->fill([
            'status' => GradeReleaseStatus::REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
            'note' => $reason,
        ])->save();

        $this->writeHistory($release, GradeHistoryAction::REVOKED, $actor, $reason);

        return $release;
    }

    /** Kunci nilai yang sudah dirilis (§42) — biasanya setelah sinkronisasi. */
    public function lock(LmsGradeRelease $release, LmsActor $actor, ?string $reason = null): LmsGradeRelease
    {
        $release->fill(['status' => GradeReleaseStatus::LOCKED])->save();
        $this->writeHistory($release, GradeHistoryAction::LOCKED, $actor, $reason);

        LmsAssessmentResult::query()
            ->where('gradebook_id', $release->gradebook_id)
            ->where('user_id', $release->user_id)
            ->update(['status' => AssessmentResultStatus::LOCKED->value, 'locked_at' => now()]);

        return $release;
    }

    public function unlock(LmsGradeRelease $release, LmsActor $actor, string $reason): LmsGradeRelease
    {
        if (! $actor->can('lms.grade.correct')) {
            LmsFault::forbidden('Hanya pengguna dengan izin koreksi nilai yang dapat membuka nilai terkunci.');
        }

        if (trim($reason) === '') {
            LmsFault::invalid('Alasan pembukaan kunci wajib diisi.', 'reason');
        }

        $release->fill(['status' => GradeReleaseStatus::RELEASED])->save();
        $this->writeHistory($release, GradeHistoryAction::UNLOCKED, $actor, $reason);

        LmsAssessmentResult::query()
            ->where('gradebook_id', $release->gradebook_id)
            ->where('user_id', $release->user_id)
            ->where('status', AssessmentResultStatus::LOCKED->value)
            ->update(['status' => AssessmentResultStatus::RELEASED->value, 'locked_at' => null]);

        return $release;
    }

    /** Peta rilis sebuah gradebook. @return array<int, array<string, mixed>> */
    public function releases(LmsGradebook $gradebook): array
    {
        return LmsGradeRelease::query()
            ->where('gradebook_id', $gradebook->id)
            ->get()
            ->mapWithKeys(fn (LmsGradeRelease $release) => [$release->user_id => $this->releasePayload($release)])
            ->all();
    }

    public function forStudent(LmsGradebook $gradebook, int $userId): ?LmsGradeRelease
    {
        return LmsGradeRelease::query()
            ->where('gradebook_id', $gradebook->id)
            ->where('user_id', $userId)
            ->first();
    }

    /** @return array<string, mixed> */
    public function releasePayload(LmsGradeRelease $release): array
    {
        return [
            'user_id' => (int) $release->user_id,
            'status' => $release->status->value,
            'status_label' => $release->status->label(),
            'status_tone' => $release->status->tone(),
            'final_score' => $release->final_score === null ? null : (float) $release->final_score,
            'normalized_score' => $release->normalized_score === null ? null : (float) $release->normalized_score,
            'letter' => $release->letter,
            'predicate' => $release->predicate,
            'passed' => $release->passed,
            'components' => $release->component_snapshot ?? [],
            'config_hash' => $release->config_hash,
            'released_at' => $release->released_at?->toIso8601String(),
            'is_visible' => $release->isVisible(),
            'is_stale' => $release->config_hash !== null
                && $release->config_hash !== $release->gradebook?->configHash(),
        ];
    }

    /**
     * Pastikan gradebook boleh dirilis: tidak beku, bobot valid (§7), dan siswa
     * benar-benar terdaftar.
     *
     * @param  list<int>  $userIds
     */
    public function assertReleasable(LmsGradebook $gradebook, LmsActor $actor, array $userIds = []): void
    {
        $this->gradebooks->assertEditable($gradebook, $actor);
        $this->gradebooks->assertWeightsValid($gradebook);

        if ($gradebook->items()->where('include_in_final', true)->doesntExist()) {
            LmsFault::invalid('Gradebook belum memiliki komponen nilai yang dihitung.', 'items');
        }
    }

    private function writeHistory(
        LmsGradeRelease $release,
        GradeHistoryAction $action,
        LmsActor $actor,
        ?string $reason = null,
        ?array $new = null,
    ): void {
        LmsGradeHistory::query()->create([
            'gradebook_id' => $release->gradebook_id,
            'grade_item_id' => null,
            'assessment_result_id' => null,
            'student_id' => $release->student_id,
            'user_id' => $release->user_id,
            'action' => $action,
            'source' => 'RELEASE',
            'previous_value' => null,
            'new_value' => $new ?? ['final_score' => $release->final_score === null ? null : (float) $release->final_score],
            'reason' => $reason,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
