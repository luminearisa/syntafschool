<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\GradeHistoryAction;
use App\Modules\LMS\Domain\Enums\GradeSyncStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsGradeHistory;
use App\Modules\LMS\Models\LmsGradeRelease;
use App\Modules\LMS\Models\LmsGradeSync;
use App\Modules\LMS\Models\LmsGradebook;
use Illuminate\Support\Facades\DB;

/**
 * Sinkronisasi nilai akhir ke modul akademik (§41–§42).
 *
 * Idempotent: payload Hash disimpan, sehingga pengiriman ulang dengan isi sama
 * tidak menghasilkan penilaian ganda di SIAKAD. Setelah berhasil, nilai
 * dikunci; perubahan berikutnya harus lewat alur koreksi berizin (§42).
 */
class GradeSyncService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly AcademicIntegrationService $integration,
        private readonly GradeReleaseService $releases,
    ) {}

    /**
     * Sinkronkan nilai satu siswa. Idempotent (§53).
     *
     * @return array<string, mixed>
     */
    public function syncStudent(LmsGradebook $gradebook, int $userId, LmsActor $actor): array
    {
        if (! $actor->can('lms.grade.sync')) {
            LmsFault::forbidden('Anda tidak memiliki izin sinkronisasi nilai.');
        }

        $provider = $this->integration->provider();
        $payload = $this->integration->payload($gradebook, $userId);
        $hash = hash('sha256', json_encode($payload));

        $sync = LmsGradeSync::query()->firstOrNew([
            'gradebook_id' => $gradebook->id,
            'user_id' => $userId,
        ]);

        // Sudah tersinkron dengan isi identik → tidak mengirim ulang.
        if ($sync->exists && $sync->status === GradeSyncStatus::SYNCED && $sync->payload_hash === $hash) {
            return $this->payload($sync) + ['unchanged' => true];
        }

        if ($sync->isLocked() && ! $actor->can('lms.grade.correct')) {
            LmsFault::conflict('Nilai ini sudah disinkronkan dan dikunci. Gunakan alur koreksi nilai.');
        }

        if ($payload['result']['final_grade'] === null) {
            $sync->fill([
                'status' => GradeSyncStatus::NOT_SYNCED,
                'payload' => $payload,
                'payload_hash' => $hash,
                'provider' => $provider->key(),
                'error_message' => 'Belum ada nilai akhir yang dapat dikirim.',
            ])->save();

            return $this->payload($sync) + ['unchanged' => false];
        }

        $sync->fill([
            'status' => GradeSyncStatus::SYNCING,
            'payload' => $payload,
            'payload_hash' => $hash,
            'provider' => $provider->key(),
            'attempts' => (int) $sync->attempts + 1,
            'last_attempt_at' => now(),
            'created_by' => $sync->created_by ?? $actor->id,
            'student_id' => $payload['student']['student_id'],
        ])->save();

        $response = $provider->push($payload);

        $providerAvailable = $provider->isAvailable();

        DB::transaction(function () use ($sync, $response, $payload, $actor, $providerAvailable): void {
            $sync->fill([
                // Provider tersedia tapi menolak → gagal; provider tidak tersedia
                // (standalone/belum diaktifkan) → tidak berlaku, bukan gagal (§63).
                'status' => match (true) {
                    $response['accepted'] => GradeSyncStatus::SYNCED,
                    $providerAvailable => GradeSyncStatus::SYNC_FAILED,
                    default => GradeSyncStatus::SKIPPED,
                },
                'external_type' => $response['external_type'],
                'external_id' => $response['external_id'],
                'external_ref' => $response['external_ref'],
                'error_message' => $response['accepted'] ? null : $response['message'],
                'synced_at' => $response['accepted'] ? now() : null,
            ])->save();

            if ($response['accepted']) {
                // §42: setelah dikirim, nilai dikunci sampai ada koreksi berizin.
                $release = $this->releases->forStudent($sync->gradebook()->firstOrFail(), (int) $sync->user_id);

                if ($release !== null) {
                    $this->releases->lock($release, $actor, 'Dikunci setelah sinkronisasi ke SIAKAD.');
                    $sync->fill(['locked_at' => now(), 'locked_by' => $actor->id])->save();
                }
            }

            LmsGradeHistory::query()->create([
                'gradebook_id' => $sync->gradebook_id,
                'user_id' => $sync->user_id,
                'student_id' => $sync->student_id,
                'action' => $response['accepted'] ? GradeHistoryAction::SYNCED : GradeHistoryAction::SYNC_FAILED,
                'source' => 'SYNC',
                'previous_value' => null,
                'new_value' => [
                    'status' => $sync->status->value,
                    'external_id' => $response['external_id'],
                    'message' => $response['message'],
                ],
                'reason' => $payload['result']['notes'] ?? null,
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);
        });

        $this->audit('lms.grade.synced', $sync, [
            'user_id' => $userId,
            'accepted' => $response['accepted'],
        ]);

        return $this->payload($sync->refresh()) + ['unchanged' => false, 'message' => $response['message']];
    }

    /**
     * Sinkronkan satu gradebook untuk sekumpulan siswa (§41).
     *
     * @param  list<int>  $userIds
     * @return array<string, mixed>
     */
    /** Kunci provider akademik yang dipakai (untuk jejak & pengaturan). */
    public function providerKey(): string
    {
        return $this->integration->provider()->key();
    }

    /** Apakah provider akademik benar-benar siap menerima nilai saat ini? */
    public function providerAvailable(): bool
    {
        return $this->integration->provider()->isAvailable();
    }

    /** Payload nilai satu siswa (§40) — dipakai jalur sinkron & jalur antrean. */
    public function payloadFor(LmsGradebook $gradebook, int $userId): array
    {
        return $this->integration->payload($gradebook, $userId);
    }

    /**
     * Kirim payload yang **sudah** disiapkan antrean (§6).
     *
     * Dipisah dari `syncStudent()` supaya aturan penguncian, riwayat nilai, dan
     * pemetaan status hanya ada di satu tempat: jalur langsung (tombol "Sync
     * Now" di gradebook) dan jalur antrean memakai aturan yang sama.
     *
     * @return array<string, mixed>
     */
    public function pushQueued(LmsGradeSync $sync, ?LmsActor $actor = null): array
    {
        $provider = $this->integration->provider();
        $payload = (array) $sync->payload;

        $sync->fill([
            'status' => GradeSyncStatus::SYNCING,
            'provider' => $provider->key(),
            'last_attempt_at' => now(),
        ])->save();

        $response = $provider->push($payload);

        $providerAvailable = $provider->isAvailable();

        DB::transaction(function () use ($sync, $response, $actor, $providerAvailable): void {
            $sync->fill([
                'status' => match (true) {
                    $response['accepted'] => GradeSyncStatus::SYNCED,
                    $providerAvailable => GradeSyncStatus::FAILED,
                    default => GradeSyncStatus::SKIPPED,
                },
                'external_type' => $response['external_type'],
                'external_id' => $response['external_id'],
                'external_ref' => $response['external_ref'],
                'error_message' => $response['accepted'] ? null : $response['message'],
                'synced_at' => $response['accepted'] ? now() : null,
            ])->save();

            if ($response['accepted']) {
                $gradebook = LmsGradebook::query()->find($sync->gradebook_id);

                if ($gradebook !== null) {
                    $release = $this->releases->forStudent($gradebook, (int) $sync->user_id);

                    if ($release !== null && ! $release->isLocked()) {
                        $this->releases->lock($release, $actor ?? $this->systemActor(), 'Dikunci setelah sinkronisasi ke SIAKAD.');
                    }

                    $sync->fill(['locked_at' => now(), 'locked_by' => $actor?->id])->save();
                }
            }

            LmsGradeHistory::query()->create([
                'gradebook_id' => $sync->gradebook_id,
                'user_id' => (int) $sync->user_id,
                'student_id' => $sync->student_id,
                'action' => $response['accepted'] ? GradeHistoryAction::SYNCED : GradeHistoryAction::SYNC_FAILED,
                'source' => 'QUEUE',
                'previous_value' => null,
                'new_value' => $payload['result']['final_grade'] ?? null,
                'reason' => $response['message'],
                'actor_id' => $actor?->id,
            ]);
        });

        $this->audit('LMS_GRADE_SYNC_PROCESSED', $sync, [
            'course_id' => LmsGradebook::query()->whereKey($sync->gradebook_id)->value('course_id'),
            'accepted' => (bool) $response['accepted'],
            'status' => $sync->status->value,
            'external_ref' => $response['external_ref'],
        ]);

        return [
            'accepted' => (bool) $response['accepted'],
            'status' => $sync->status->value,
            'message' => $response['message'],
            'external_type' => $response['external_type'],
            'external_id' => $response['external_id'],
            'external_ref' => $response['external_ref'],
        ];
    }

    /**
     * Penerima pemberitahuan kegagalan sinkronisasi: guru pengampu course.
     *
     * @return list<int>
     */
    public function handlersFor(LmsGradebook $gradebook): array
    {
        $course = $gradebook->course()->first();

        if ($course === null) {
            return [];
        }

        $teacherIds = $course->teachers()->pluck('users.id')->all();

        if ($teacherIds === []) {
            $teacherIds = array_filter([$course->teacher_id]);
        }

        return array_values(array_unique(array_map('intval', $teacherIds)));
    }

    /** Aktor sistem untuk aksi otomatis (job) tanpa pengguna (§11). */
    private function systemActor(): LmsActor
    {
        return \App\Modules\LMS\Domain\Support\LmsSystemActor::make();
    }

    public function syncGradebook(LmsGradebook $gradebook, array $userIds, LmsActor $actor): array
    {
        $rows = [];
        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($userIds as $userId) {
            $row = $this->syncStudent($gradebook, (int) $userId, $actor);
            $rows[] = $row;

            match ($row['status']) {
                GradeSyncStatus::SYNCED->value => $synced++,
                GradeSyncStatus::SYNC_FAILED->value => $failed++,
                default => $skipped++,
            };
        }

        return [
            'gradebook_id' => (int) $gradebook->id,
            'provider' => $this->integration->provider()->key(),
            'available' => $this->integration->provider()->isAvailable(),
            'synced' => $synced,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $rows,
        ];
    }

    /** Status sinkronisasi sebuah gradebook. @return array<string, mixed> */
    public function status(LmsGradebook $gradebook): array
    {
        $rows = LmsGradeSync::query()
            ->where('gradebook_id', $gradebook->id)
            ->get();

        return [
            'integration' => $this->integration->status(),
            'total' => $rows->count(),
            'synced' => $rows->where('status', GradeSyncStatus::SYNCED)->count(),
            'failed' => $rows->where('status', GradeSyncStatus::SYNC_FAILED)->count(),
            'skipped' => $rows->where('status', GradeSyncStatus::SKIPPED)->count(),
            'not_synced' => $rows->where('status', GradeSyncStatus::NOT_SYNCED)->count(),
            'locked' => $rows->filter(fn (LmsGradeSync $sync) => $sync->isLocked())->count(),
            'last_synced_at' => $rows->max('synced_at'),
            'rows' => $rows->map(fn (LmsGradeSync $sync) => $this->payload($sync))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function payload(LmsGradeSync $sync): array
    {
        return [
            'user_id' => (int) $sync->user_id,
            'status' => $sync->status->value,
            'status_label' => $sync->status->label(),
            'status_tone' => $sync->status->tone(),
            'provider' => $sync->provider,
            'external_type' => $sync->external_type,
            'external_id' => $sync->external_id,
            'external_ref' => $sync->external_ref,
            'attempts' => (int) $sync->attempts,
            'error_message' => $sync->error_message,
            'payload_hash' => $sync->payload_hash,
            'is_locked' => $sync->isLocked(),
            'synced_at' => $sync->synced_at?->toIso8601String(),
            'last_attempt_at' => $sync->last_attempt_at?->toIso8601String(),
        ];
    }

    /** Batalkan kunci sinkronisasi agar nilai dapat dikoreksi (§42). */
    public function unlock(LmsGradeSync $sync, LmsActor $actor, string $reason): LmsGradeSync
    {
        if (! $actor->can('lms.grade.correct')) {
            LmsFault::forbidden('Hanya pengguna dengan izin koreksi nilai yang dapat membuka kunci sinkronisasi.');
        }

        $release = LmsGradeRelease::query()
            ->where('gradebook_id', $sync->gradebook_id)
            ->where('user_id', $sync->user_id)
            ->first();

        if ($release !== null) {
            $this->releases->unlock($release, $actor, $reason);
        }

        $sync->fill(['locked_at' => null, 'locked_by' => null, 'status' => GradeSyncStatus::NOT_SYNCED])->save();

        return $sync->refresh();
    }
}
