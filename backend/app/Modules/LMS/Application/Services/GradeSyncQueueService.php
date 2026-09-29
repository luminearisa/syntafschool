<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\GradeSyncStatus;
use App\Modules\LMS\Domain\Enums\NotificationEvent;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Jobs\ProcessGradeSyncJob;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsGradeRelease;
use App\Modules\LMS\Models\LmsGradeSync;
use App\Modules\LMS\Models\LmsGradeSyncAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Antrean sinkronisasi nilai LMS → SIAKAD (§6).
 *
 * Alur: `enqueue()` → job `ProcessGradeSyncJob` → `process()`.
 *
 * Sifat yang dijaga:
 * - **Idempotent**: payload di-hash; kiriman dengan hash sama tidak diulang,
 *   dan `idempotency_key` unik menahan job dobel dari antrean.
 * - **Retryable**: kegagalan dijadwalkan ulang dengan backoff; batas percobaan
 *   dan jeda berasal dari `lms.automation.*`.
 * - **Auditable**: setiap percobaan meninggalkan baris `lms_grade_sync_attempts`
 *   berisi request, respons, galat, dan durasi.
 * - **Tidak merusak nilai resmi**: koreksi tetap memerlukan izin dan
 *   membuka kunci lewat `GradeSyncService` yang sudah ada (§42).
 */
class GradeSyncQueueService
{
    use LogsLmsAudit;

    public function __construct(private readonly GradeSyncService $sync) {}

    /**
     * Masukkan nilai satu gradebook ke antrean (§6: Grade Sync Queue).
     *
     * @param  list<int>  $userIds  kosong = seluruh siswa pada gradebook
     * @return array{queued:int,skipped:int,skipped_reasons:array<string,int>,ids:list<int>}
     */
    public function enqueue(LmsGradebook $gradebook, array $userIds, LmsActor $actor, bool $dispatch = true): array
    {
        if (! $actor->can('lms.grade.sync') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak memiliki izin sinkronisasi nilai.');
        }

        $userIds = $userIds === [] ? $this->studentIds($gradebook) : array_values(array_unique($userIds));

        $queued = 0;
        $skipped = 0;
        $reasons = [];
        $ids = [];

        foreach ($userIds as $userId) {
            $payload = $this->sync->payloadFor($gradebook, (int) $userId);

            $reason = $this->skipReason($payload);

            if ($reason !== null) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                $skipped++;

                continue;
            }

            $hash = hash('sha256', json_encode($payload));
            $sync = $this->lockRow($gradebook, (int) $userId);

            // Sudah tersinkron dengan isi identik → tidak perlu diantrekan lagi.
            if ($sync->status === GradeSyncStatus::SYNCED && $sync->payload_hash === $hash) {
                $reasons['unchanged'] = ($reasons['unchanged'] ?? 0) + 1;
                $skipped++;

                continue;
            }

            // Provider akademik belum ada (mis. SIAKAD belum diaktifkan) dan
            // isinya sama seperti percobaan terakhir → mengantre ulang hanya
            // menghasilkan pekerjaan sia-sia. Begitu provider siap, antrean
            // berikutnya akan memprosesnya.
            if (
                $sync->status === GradeSyncStatus::SKIPPED
                && $sync->payload_hash === $hash
                && ! $this->sync->providerAvailable()
            ) {
                $reasons['provider_unavailable'] = ($reasons['provider_unavailable'] ?? 0) + 1;
                $skipped++;

                continue;
            }

            if ($sync->isLocked() && ! $actor->can('lms.grade.correct')) {
                $reasons['locked'] = ($reasons['locked'] ?? 0) + 1;
                $skipped++;

                continue;
            }

            $sync->fill([
                'status' => GradeSyncStatus::PENDING,
                'payload' => $payload,
                'payload_hash' => $hash,
                'provider' => $this->sync->providerKey(),
                'student_id' => $payload['student']['student_id'] ?? null,
                'created_by' => $sync->created_by ?? $actor->id,
                'error_message' => null,
                'queued_at' => now(),
                'next_attempt_at' => now(),
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancel_reason' => null,
                'idempotency_key' => $sync->idempotency_key ?? $this->idempotencyKey($gradebook, (int) $userId, $hash),
            ])->save();

            $queued++;
            $ids[] = (int) $sync->id;
        }

        if ($dispatch && $ids !== []) {
            foreach ($ids as $id) {
                ProcessGradeSyncJob::dispatch($id);
            }
        }

        $this->audit('LMS_GRADE_SYNC_QUEUED', $gradebook, [
            'course_id' => $gradebook->course_id,
            'queued' => $queued,
            'skipped' => $skipped,
            'reasons' => $reasons,
        ]);

        return ['queued' => $queued, 'skipped' => $skipped, 'skipped_reasons' => $reasons, 'ids' => $ids];
    }

    /**
     * Jalankan satu baris sinkronisasi (dipanggil job).
     *
     * @return array<string, mixed>
     */
    public function process(LmsGradeSync $sync, ?LmsActor $actor = null): array
    {
        $sync->refresh();

        if (in_array($sync->status, [GradeSyncStatus::CANCELLED, GradeSyncStatus::SYNCED], true)) {
            return ['processed' => false, 'reason' => 'status_'.$sync->status->value, 'sync_id' => (int) $sync->id];
        }

        $attemptNo = (int) LmsGradeSyncAttempt::query()->where('grade_sync_id', $sync->id)->max('attempt_no') + 1;
        $startedAt = now();
        $key = $sync->idempotency_key !== null ? $sync->idempotency_key.'#'.$attemptNo : null;

        $attempt = LmsGradeSyncAttempt::query()->create([
            'grade_sync_id' => $sync->id,
            'attempt_no' => $attemptNo,
            'status' => GradeSyncStatus::PROCESSING,
            'idempotency_key' => $key,
            'request' => ['payload_hash' => $sync->payload_hash, 'provider' => $sync->provider],
            'actor_id' => $actor?->id,
            'started_at' => $startedAt,
        ]);

        $sync->fill([
            'status' => GradeSyncStatus::PROCESSING,
            'attempts' => (int) $sync->attempts + 1,
            'last_attempt_at' => $startedAt,
        ])->save();

        $result = $this->sync->pushQueued($sync, $actor);

        $attempt->fill([
            'status' => $sync->status,
            'response' => $result,
            'error_message' => $sync->error_message,
            'finished_at' => now(),
            'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
        ])->save();

        if ($sync->status->isRetryable()) {
            $this->scheduleRetry($sync);
        }

        return ['processed' => true, 'sync_id' => (int) $sync->id, 'attempt_no' => $attemptNo, 'status' => $sync->status->value, 'result' => $result];
    }

    /**
     * Coba ulang seluruh sinkronisasi yang gagal (§6: Retry Failed).
     *
     * @return array{retried:int,ids:list<int>}
     */
    public function retryFailed(LmsGradebook $gradebook, LmsActor $actor, bool $dispatch = true): array
    {
        if (! $actor->can('lms.grade.sync') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak memiliki izin sinkronisasi nilai.');
        }

        $rows = LmsGradeSync::query()
            ->where('gradebook_id', $gradebook->id)
            ->whereIn('status', [GradeSyncStatus::FAILED->value, GradeSyncStatus::SYNC_FAILED->value, GradeSyncStatus::RETRYING->value, GradeSyncStatus::PENDING->value])
            ->get();

        $ids = [];

        foreach ($rows as $sync) {
            $sync->fill([
                'status' => GradeSyncStatus::PENDING,
                'error_message' => null,
                'queued_at' => now(),
                'next_attempt_at' => now(),
            ])->save();

            $ids[] = (int) $sync->id;

            if ($dispatch) {
                ProcessGradeSyncJob::dispatch((int) $sync->id);
            }
        }

        $this->audit('LMS_GRADE_SYNC_RETRIED', $gradebook, [
            'course_id' => $gradebook->course_id,
            'retried' => count($ids),
        ]);

        return ['retried' => count($ids), 'ids' => $ids];
    }

    /** Batalkan sinkronisasi yang masih menunggu (§6: CANCELLED). */
    public function cancel(LmsGradeSync $sync, LmsActor $actor, string $reason): LmsGradeSync
    {
        if (! $actor->can('lms.grade.sync') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak memiliki izin sinkronisasi nilai.');
        }

        if ($sync->status === GradeSyncStatus::SYNCED) {
            LmsFault::conflict('Nilai sudah tersinkron; gunakan alur koreksi nilai untuk mengubahnya.');
        }

        $sync->fill([
            'status' => GradeSyncStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
        ])->save();

        $this->audit('LMS_GRADE_SYNC_CANCELLED', $sync, [
            'course_id' => $sync->gradebook()->value('course_id'),
            'reason' => $reason,
        ]);

        return $sync->fresh();
    }

    /**
     * Riwayat percobaan satu baris sinkronisasi (§6: View History).
     *
     * @return list<array<string, mixed>>
     */
    public function history(LmsGradeSync $sync, int $limit = 20): array
    {
        return LmsGradeSyncAttempt::query()
            ->where('grade_sync_id', $sync->id)
            ->orderByDesc('attempt_no')
            ->limit($limit)
            ->get()
            ->map(fn (LmsGradeSyncAttempt $attempt) => [
                'id' => (int) $attempt->id,
                'attempt_no' => (int) $attempt->attempt_no,
                'status' => $attempt->status?->value,
                'status_label' => $attempt->status?->label(),
                'error_message' => $attempt->error_message,
                'started_at' => $attempt->started_at?->toIso8601String(),
                'finished_at' => $attempt->finished_at?->toIso8601String(),
                'duration_ms' => $attempt->duration_ms,
            ])
            ->all();
    }

    /**
     * Ringkasan antrean sebuah gradebook.
     *
     * @return array<string, mixed>
     */
    public function status(LmsGradebook $gradebook): array
    {
        $counts = [];

        foreach (GradeSyncStatus::cases() as $status) {
            $counts[$status->value] = LmsGradeSync::query()
                ->where('gradebook_id', $gradebook->id)
                ->where('status', $status->value)
                ->count();
        }

        $rows = LmsGradeSync::query()
            ->where('gradebook_id', $gradebook->id)
            ->orderByRaw('CASE WHEN next_attempt_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_attempt_at')
            ->get();

        return [
            'course_id' => (int) $gradebook->course_id,
            'counts' => $counts,
            'pending' => $counts[GradeSyncStatus::PENDING->value] ?? 0,
            'failed' => ($counts[GradeSyncStatus::FAILED->value] ?? 0) + ($counts[GradeSyncStatus::SYNC_FAILED->value] ?? 0),
            'synced' => $counts[GradeSyncStatus::SYNCED->value] ?? 0,
            'in_flight' => $rows->filter(fn (LmsGradeSync $sync) => $sync->status->isInFlight())->count(),
            'next_attempt_at' => optional($rows->first(fn (LmsGradeSync $row) => $row->next_attempt_at !== null)?->next_attempt_at)->toIso8601String(),
            'rows' => $rows->map(fn (LmsGradeSync $sync) => [
                'sync_id' => (int) $sync->id,
                'user_id' => (int) $sync->user_id,
                'student_id' => $sync->student_id !== null ? (int) $sync->student_id : null,
                'status' => $sync->status?->value,
                'status_label' => $sync->status?->label(),
                'tone' => $sync->status?->tone(),
                'attempts' => (int) $sync->attempts,
                'error_message' => $sync->error_message,
                'queued_at' => $sync->queued_at?->toIso8601String(),
                'next_attempt_at' => $sync->next_attempt_at?->toIso8601String(),
                'synced_at' => $sync->synced_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Bandingkan nilai LMS dengan nilai resmi di SIAKAD (§6: Compare).
     *
     * Perbandingan dilakukan pada **snapshot rilis** LMS dan jejak sinkronisasi
     * terakhir — bukan dengan membaca tabel SIAKAD langsung, supaya LMS tetap
     * tidak bergantung pada skema akademik.
     *
     * @return array<string, mixed>
     */
    public function compare(LmsGradebook $gradebook, int $userId): array
    {
        $sync = LmsGradeSync::query()
            ->where('gradebook_id', $gradebook->id)
            ->where('user_id', $userId)
            ->first();

        $release = LmsGradeRelease::query()
            ->where('gradebook_id', $gradebook->id)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first();

        $lmsGrade = $release?->final_score;
        $sentGrade = $sync->payload['result']['final_grade'] ?? null;

        $difference = ($lmsGrade !== null && $sentGrade !== null)
            ? round((float) $lmsGrade - (float) $sentGrade, 2)
            : null;

        return [
            'course_id' => (int) $gradebook->course_id,
            'user_id' => $userId,
            'lms' => [
                'final_grade' => $lmsGrade !== null ? (float) $lmsGrade : null,
                'released_at' => $release?->released_at?->toIso8601String(),
                'released' => $release !== null,
            ],
            // SIAKAD = nilai resmi; LMS = nilai yang baru dikirim/disiapkan.
            'siakad' => [
                'status' => $sync?->status?->value,
                'status_label' => $sync?->status?->label(),
                'external_id' => $sync?->external_id,
                'external_ref' => $sync?->external_ref,
                'sent_grade' => $sentGrade !== null ? (float) $sentGrade : null,
                'synced_at' => $sync?->synced_at?->toIso8601String(),
                'locked' => $sync?->isLocked() ?? false,
            ],
            'difference' => $difference,
            'in_sync' => $difference !== null && abs($difference) < 0.01,
            'requires_action' => $difference !== null && abs($difference) >= 0.01,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function skipReason(array $payload): ?string
    {
        $finalGrade = $payload['result']['final_grade'] ?? null;

        if ($finalGrade === null) {
            return 'final_grade_missing';
        }

        if (($payload['student']['student_id'] ?? null) === null) {
            return 'student_not_mapped';
        }

        return null;
    }

    private function scheduleRetry(LmsGradeSync $sync): void
    {
        $maxAttempts = (int) config('lms.automation.grade_sync_max_attempts', 5);
        $backoffMinutes = (int) config('lms.automation.grade_sync_backoff_minutes', 10);

        if ((int) $sync->attempts >= $maxAttempts) {
            $sync->fill([
                'status' => GradeSyncStatus::FAILED,
                'next_attempt_at' => null,
            ])->save();

            $this->notifyFailure($sync);

            return;
        }

        $multiplier = max(1, (int) $sync->attempts);

        $sync->fill([
            'status' => GradeSyncStatus::RETRYING,
            'next_attempt_at' => now()->addMinutes($backoffMinutes * $multiplier),
        ])->save();
    }

    private function notifyFailure(LmsGradeSync $sync): void
    {
        if (config('lms.automation.notify_grade_sync_failure', true) !== true) {
            return;
        }

        $gradebook = $sync->gradebook()->first();
        $recipients = $gradebook !== null ? $this->sync->handlersFor($gradebook) : [];

        if ($recipients === []) {
            return;
        }

        app(LmsGateway::class)->notify(
            $recipients,
            NotificationEvent::LEARNING_AT_RISK,
            [
                'title' => 'Sinkronisasi nilai gagal',
                'body' => 'Nilai course '.($gradebook?->course?->name ?? '').' gagal dikirim ke SIAKAD setelah '.$sync->attempts.' percobaan.',
                'context' => ['grade_sync_id' => (int) $sync->id],
            ],
            ['in_app'],
            'grade-sync-failed-'.$sync->id,
        );
    }

    private function lockRow(LmsGradebook $gradebook, int $userId): LmsGradeSync
    {
        return DB::transaction(function () use ($gradebook, $userId): LmsGradeSync {
            $sync = LmsGradeSync::query()
                ->where('gradebook_id', $gradebook->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            return $sync ?? new LmsGradeSync([
                'gradebook_id' => $gradebook->id,
                'user_id' => $userId,
            ]);
        });
    }

    private function idempotencyKey(LmsGradebook $gradebook, int $userId, string $hash): string
    {
        return 'grade-sync:'.$gradebook->id.':'.$userId.':'.substr($hash, 0, 24);
    }

    /** @return list<int> */
    private function studentIds(LmsGradebook $gradebook): array
    {
        return LmsEnrollment::query()
            ->where('course_id', $gradebook->course_id)
            ->where('role', 'STUDENT')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
