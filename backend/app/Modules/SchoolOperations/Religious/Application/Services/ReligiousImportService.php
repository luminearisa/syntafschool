<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{ReligiousImportBatch, ReligiousImportRow};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Impor & ekspor (§87): CSV/Excel dengan alur
 * Preview → Validation → Error Report → Commit → Rollback → Riwayat.
 *
 * Tidak ada impor yang langsung menulis ke tabel tujuan: setiap baris divalidasi
 * lebih dulu dan hasilnya dapat ditinjau manusia.
 */
class ReligiousImportService
{
    public function __construct(protected ReligiousContext $context)
    {
    }

    /** Tahap 1: buat batch & validasi baris (belum menulis ke tabel tujuan). */
    public function preview(string $type, array $rows, array $meta = []): ReligiousImportBatch
    {
        $type = strtoupper($type);
        $definition = (array) config('religious.import.types.'.$type, []);
        if ($definition === []) {
            throw ValidationException::withMessages([
                'import_type' => 'Jenis impor tidak dikenal: '.$type,
            ]);
        }

        $batch = ReligiousImportBatch::query()->create([
            'code' => $this->context->nextCode('IMP', 'religious_import_batches'),
            'import_type' => $type,
            'file_name' => $meta['file_name'] ?? null,
            'file_path' => $meta['file_path'] ?? null,
            'total_rows' => count($rows),
            'status' => 'PREVIEW',
            'imported_by' => $this->context->actorId(),
            'notes' => $meta['notes'] ?? null,
        ]);

        $valid = 0;
        $invalid = 0;
        $errors = [];
        $previewRows = [];

        foreach ($rows as $index => $row) {
            $rowErrors = $this->validateRow($type, (array) $row, (int) $index + 1);
            $status = $rowErrors === [] ? 'VALID' : 'INVALID';
            $rowErrors === [] ? $valid++ : $invalid++;

            if ($rowErrors !== []) {
                $errors[] = ['row' => $index + 1, 'errors' => $rowErrors];
            }
            if (count($previewRows) < 20) {
                $previewRows[] = ['row' => $index + 1, 'status' => $status, 'data' => $row, 'errors' => $rowErrors];
            }

            ReligiousImportRow::query()->create([
                'batch_id' => $batch->getKey(),
                'row_number' => $index + 1,
                'payload' => (array) $row,
                'status' => $status,
                'errors' => $rowErrors ?: null,
                'action' => 'CREATE',
            ]);
        }

        $batch->forceFill([
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'status' => 'VALIDATED',
            'error_report' => $errors,
            'preview' => $previewRows,
        ])->save();

        $this->context->audit('religious.import.previewed', $batch, [
            'type' => $type, 'valid' => $valid, 'invalid' => $invalid,
        ]);

        return $batch;
    }

    /** Validasi per baris sesuai jenis impor. */
    protected function validateRow(string $type, array $row, int $rowNumber): array
    {
        $errors = [];
        $definition = (array) config('religious.import.types.'.$type, []);
        $required = (array) ($definition['required'] ?? []);

        foreach ($required as $column) {
            if (! isset($row[$column]) || $row[$column] === '' || $row[$column] === null) {
                $errors[] = 'Kolom wajib kosong: '.$column;
            }
        }

        foreach ((array) ($definition['rules'] ?? []) as $column => $rule) {
            if (! isset($row[$column]) || $row[$column] === '') {
                continue;
            }
            $value = (string) $row[$column];

            if (($rule['type'] ?? null) === 'time' && ! preg_match('/^\d{1,2}:\d{2}/', $value)) {
                $errors[] = 'Format waktu tidak sah pada '.$column.' (contoh 04:35).';
            }
            if (($rule['type'] ?? null) === 'date' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $errors[] = 'Format tanggal tidak sah pada '.$column.' (YYYY-MM-DD).';
            }
            if (($rule['type'] ?? null) === 'in' && ! in_array(strtoupper($value), (array) ($rule['values'] ?? []), true)) {
                $errors[] = 'Nilai '.$value.' tidak dikenal pada '.$column.'.';
            }
        }

        return $errors;
    }

    /**
     * Tahap 2: terapkan baris valid ke tabel tujuan.
     * Baris tidak valid dilewati sehingga satu kesalahan tidak membatalkan semua.
     */
    public function commit(int $batchId, bool $onlyValid = true): array
    {
        $batch = ReligiousImportBatch::query()->findOrFail($batchId);
        if ($batch->status === 'COMMITTED') {
            return ['batch_id' => $batchId, 'status' => 'ALREADY_COMMITTED', 'imported' => (int) $batch->imported_rows];
        }

        $rows = ReligiousImportRow::query()->where('batch_id', $batchId)
            ->when($onlyValid, fn ($q) => $q->where('status', 'VALID'))->get();

        $imported = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $entity = $this->applyRow($batch->import_type, (array) $row->payload);
                $row->forceFill([
                    'status' => 'IMPORTED',
                    'entity_type' => $entity['type'] ?? null,
                    'entity_id' => $entity['id'] ?? null,
                ])->save();
                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $row->forceFill([
                    'status' => 'FAILED',
                    'errors' => array_merge((array) $row->errors, [$e->getMessage()]),
                ])->save();
            }
        }

        $batch->forceFill([
            'imported_rows' => $imported,
            'status' => 'COMMITTED',
            'committed_at' => now(),
        ])->save();

        $this->context->audit('religious.import.committed', $batch, [
            'imported' => $imported, 'failed' => $failed,
        ]);

        return [
            'batch_id' => $batchId,
            'status' => 'COMMITTED',
            'imported' => $imported,
            'failed' => $failed,
            'rollback_reference' => $batch->code,
        ];
    }

    /** Terapkan satu baris ke entitas tujuan. */
    protected function applyRow(string $type, array $row): array
    {
        return match ($type) {
            'PRAYER_SCHEDULE' => $this->applyPrayerSchedule($row),
            'IMAM', 'MUADZIN', 'KHATIB' => $this->applyPersonnel($type, $row),
            'RELIGIOUS_ACTIVITY' => $this->applyActivity($row),
            'DUTY_ROSTER' => $this->applyDutyRoster($row),
            'PARTICIPANT' => $this->applyParticipant($row),
            default => throw new \RuntimeException('Jenis impor belum didukung: '.$type),
        };
    }

    protected function applyPrayerSchedule(array $row): array
    {
        $schedule = app(PrayerScheduleService::class)->upsertSchedule([
            'schedule_date' => $row['date'] ?? $row['schedule_date'] ?? now()->toDateString(),
            'mosque_id' => $row['mosque_id'] ?? null,
            'campus_id' => $row['campus_id'] ?? null,
            'prayer_type' => $row['prayer_type'],
            'adhan_time' => $row['adhan_time'] ?? null,
            'iqamah_time' => $row['iqamah_time'] ?? null,
            'end_time' => $row['end_time'] ?? null,
            'source' => 'IMPORT',
        ], 'import');

        return ['type' => 'prayer_schedules', 'id' => $schedule->getKey()];
    }

    protected function applyPersonnel(string $type, array $row): array
    {
        $service = app(PersonnelService::class);
        $payload = array_merge($row, ['person_type' => $row['person_type'] ?? 'EXTERNAL']);

        $profile = match ($type) {
            'IMAM' => $service->registerImam($payload),
            'MUADZIN' => $service->registerMuadzin($payload),
            default => $service->registerKhatib($payload),
        };

        return ['type' => strtolower($type).'_profiles', 'id' => $profile->getKey()];
    }

    protected function applyActivity(array $row): array
    {
        $activity = app(ReligiousActivityService::class)->create($row);

        return ['type' => 'religious_activities', 'id' => $activity->getKey()];
    }

    protected function applyDutyRoster(array $row): array
    {
        $rosterId = (int) ($row['roster_id'] ?? 0);
        if (! $rosterId) {
            throw new \RuntimeException('Kolom roster_id wajib diisi untuk impor roster.');
        }

        $assignment = app(DutyRosterService::class)->addAssignment($rosterId, $row, (bool) ($row['allow_override'] ?? false));

        return ['type' => 'religious_duty_assignments', 'id' => $assignment->getKey()];
    }

    protected function applyParticipant(array $row): array
    {
        $activityId = (int) ($row['activity_id'] ?? 0);
        if (! $activityId) {
            throw new \RuntimeException('Kolom activity_id wajib diisi untuk impor peserta.');
        }

        $registration = app(ReligiousActivityService::class)->register($activityId, $row);

        return ['type' => 'religious_activity_registrations', 'id' => $registration->getKey()];
    }

    /**
     * Tahap 3: batalkan hasil impor dengan cara yang dapat diaudit.
     * Baris yang sudah dibuat ditandai DIBATALKAN, bukan dihapus diam-diam.
     */
    public function rollback(int $batchId, string $reason): array
    {
        $batch = ReligiousImportBatch::query()->findOrFail($batchId);

        $rows = ReligiousImportRow::query()->where('batch_id', $batchId)->where('status', 'IMPORTED')->get();
        $reverted = 0;

        foreach ($rows as $row) {
            try {
                $this->revertRow((string) $row->entity_type, (int) $row->entity_id);
                $row->forceFill(['status' => 'ROLLED_BACK', 'notes' => $reason])->save();
                $reverted++;
            } catch (\Throwable $e) {
                $row->forceFill(['errors' => array_merge((array) $row->errors, ['rollback: '.$e->getMessage()])])->save();
            }
        }

        $batch->forceFill([
            'status' => 'ROLLED_BACK',
            'rolled_back_at' => now(),
            'rollback_reference' => $batch->code,
            'notes' => trim(($batch->notes ?? '').' | Rollback: '.$reason, ' |'),
        ])->save();

        $this->context->audit('religious.import.rolled_back', $batch, ['reverted' => $reverted], $reason);

        return ['batch_id' => $batchId, 'status' => 'ROLLED_BACK', 'reverted' => $reverted];
    }

    /**
     * Aturan 6 & 15: catatan historis tidak dihapus destructive.
     * Baris hasil impor dinonaktifkan/dibatalkan berstatus, bukan dihapus.
     */
    protected function revertRow(string $entityType, int $entityId): void
    {
        if ($entityId <= 0) {
            return;
        }

        match ($entityType) {
            'prayer_schedules' => DB::table('prayer_schedules')->where('id', $entityId)
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]),
            'religious_activities' => DB::table('religious_activities')->where('id', $entityId)
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]),
            'religious_activity_registrations' => DB::table('religious_activity_registrations')->where('id', $entityId)
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]),
            'religious_duty_assignments' => DB::table('religious_duty_assignments')->where('id', $entityId)
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]),
            'imam_profiles', 'muadzin_profiles', 'khatib_profiles' => DB::table($entityType)->where('id', $entityId)
                ->update(['status' => 'INACTIVE', 'updated_at' => now()]),
            default => null,
        };
    }

    /** Riwayat impor (§87). */
    public function history(array $filters = []): array
    {
        return ReligiousImportBatch::query()
            ->when(! empty($filters['type']), fn ($q) => $q->where('import_type', strtoupper((string) $filters['type'])))
            ->orderByDesc('id')->limit((int) ($filters['limit'] ?? 50))->get()
            ->map(fn (ReligiousImportBatch $b): array => [
                'id' => $b->getKey(), 'code' => $b->code, 'type' => $b->import_type,
                'total_rows' => $b->total_rows, 'valid_rows' => $b->valid_rows,
                'imported_rows' => $b->imported_rows, 'status' => $b->status,
                'committed_at' => $b->committed_at?->toIso8601String(),
            ])->all();
    }

    /** Ekspor CSV sederhana (§87). */
    public function exportCsv(string $entity, array $filters = []): array
    {
        $definition = (array) config('religious.import.exports.'.$entity, []);
        $table = $definition['table'] ?? null;
        if ($table === null || ! $this->context->hasTable($table)) {
            return ['exported' => false, 'reason' => 'Ekspor untuk '.$entity.' belum dikonfigurasi.'];
        }

        $columns = (array) ($definition['columns'] ?? ['*']);
        $query = DB::table($table)->limit((int) ($filters['limit'] ?? 5000));
        foreach ((array) ($filters['where'] ?? []) as $column => $value) {
            $query->where($column, $value);
        }

        $rows = $query->get($columns === ['*'] ? ['*'] : $columns);

        return [
            'exported' => true,
            'entity' => $entity,
            'rows' => $rows->count(),
            'columns' => $columns,
            'data' => $rows->map(fn ($r): array => (array) $r)->all(),
        ];
    }
}
