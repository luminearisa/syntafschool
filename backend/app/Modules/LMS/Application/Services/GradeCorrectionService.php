<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\AssessmentResultStatus;
use App\Modules\LMS\Domain\Enums\GradeHistoryAction;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsAssessmentResult;
use App\Modules\LMS\Models\LmsGradeRelease;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi nilai (§19, §42).
 *
 * Tidak ada nilai yang ditimpa tanpa jejak: nilai lama, nilai baru, alasan,
 * guru, dan waktu selalu tersimpan di `lms_grade_histories` yang immutable.
 */
class GradeCorrectionService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly AssessmentResultService $results,
    ) {}

    /**
     * Koreksi satu hasil penilaian.
     *
     * @return array{result: LmsAssessmentResult, previous: float|null, corrected: float|null}
     */
    public function correct(
        LmsAssessmentResult $result,
        float $newScore,
        string $reason,
        LmsActor $actor,
        bool $notifyChangedStudent = true,
    ): array {
        if (! $actor->can('lms.grade.correct')) {
            LmsFault::forbidden('Anda tidak memiliki izin koreksi nilai.');
        }

        return $this->applyCorrection($result, $newScore, $reason, $actor, $notifyChangedStudent);
    }

    /**
     * Koreksi sebagai eksekusi keputusan banding (§20).
     *
     * Otorisasinya sudah terjadi di alur banding (`lms.grade.review` +
     * `lms.grade.approve`), dan jejak auditnya tetap ditulis sama persis
     * seperti koreksi biasa — tidak ada jalur yang menghapus riwayat.
     */
    public function correctFromReview(
        LmsAssessmentResult $result,
        float $newScore,
        string $reason,
        LmsActor $actor,
    ): array {
        return $this->applyCorrection($result, $newScore, $reason, $actor);
    }

    /** @return array{result: LmsAssessmentResult, previous: float|null, corrected: float|null} */
    private function applyCorrection(
        LmsAssessmentResult $result,
        float $newScore,
        string $reason,
        LmsActor $actor,
        bool $notifyChangedStudent = true,
    ): array {
        if (trim($reason) === '') {
            LmsFault::invalid('Alasan koreksi wajib diisi.', 'reason');
        }

        // Status dibaca ulang dari database: instance yang dipegang pemanggil
        // bisa saja sudah usang setelah alur buka-kunci (§42).
        $current = $result->fresh() ?? $result;

        if ($current->status === AssessmentResultStatus::LOCKED || $current->locked_at !== null) {
            LmsFault::conflict('Nilai sedang terkunci (mis. sudah disinkronkan). Buka kunci dengan alasan sebelum mengoreksi.');
        }

        $previous = $current->final_score === null ? null : (float) $current->final_score;
        $item = $current->item()->firstOrFail();

        $corrected = DB::transaction(function () use ($result, $item, $newScore, $reason, $actor, $previous) {
            $updated = $this->results->record($item, (int) $result->user_id, [
                'final_score' => $newScore,
                'status' => AssessmentResultStatus::GRADED,
                'reason' => $reason,
            ], $actor);

            // Koreksi berarti nilai harus terlihat lagi oleh siswa bila sudah dirilis.
            $this->results->history(
                $updated,
                GradeHistoryAction::CORRECTED,
                ['final_score' => $previous],
                ['final_score' => (float) $newScore],
                $reason,
                $actor,
            );

            return $updated;
        });

        $this->audit('lms.grade.corrected', $result, [
            'from' => $previous,
            'to' => $newScore,
            'reason' => $reason,
        ]);

        if ($notifyChangedStudent) {
            app(GradeNotifier::class)->gradeChanged([(int) $result->user_id], [
                'title' => 'Nilai dikoreksi',
                'message' => sprintf('Nilai "%s" dikoreksi dari %s menjadi %s.', $item->name, $previous ?? '-', $newScore),
                'gradebook_id' => (int) $result->gradebook_id,
                'grade_item_id' => (int) $result->grade_item_id,
                'course_id' => (int) $result->course_id,
                'url' => '/lms/grades',
            ]);
        }

        return ['result' => $corrected, 'previous' => $previous, 'corrected' => (float) $newScore];
    }

    /**
     * Koreksi nilai akhir yang sudah dirilis (mengikuti alur §42:
     * unlock → modify → (re-sync) → lock).
     *
     * @return array<string, mixed>
     */
    public function correctRelease(
        LmsGradeRelease $release,
        float $newScore,
        string $reason,
        LmsActor $actor,
    ): array {
        if (! $actor->can('lms.grade.correct')) {
            LmsFault::forbidden('Anda tidak memiliki izin koreksi nilai.');
        }

        if (trim($reason) === '') {
            LmsFault::invalid('Alasan koreksi wajib diisi.', 'reason');
        }

        $previous = $release->normalized_score === null ? null : (float) $release->normalized_score;

        $release->fill([
            'normalized_score' => $newScore,
            'final_score' => round($newScore / 100 * (float) $release->gradebook->maximum_grade, 2),
            'note' => $reason,
        ])->save();

        app(GradeReleaseService::class)->lock($release, $actor, 'Koreksi manual: '.$reason);

        $this->audit('lms.grade.release_corrected', $release, ['from' => $previous, 'to' => $newScore, 'reason' => $reason]);

        return ['previous' => $previous, 'corrected' => $newScore, 'release' => $release->refresh()];
    }
}
