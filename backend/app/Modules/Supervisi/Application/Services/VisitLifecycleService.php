<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\InstrumentVersionStatus;
use App\Modules\Supervisi\Domain\Enums\ScheduleStatus;
use App\Modules\Supervisi\Domain\Enums\VisitStatus;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupSchedule;
use App\Modules\Supervisi\Models\SupSupervisor;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daur hidup kegiatan supervisi: pembuatan, perpindahan status, pembatalan,
 * dan pembukaan ulang.
 *
 * Semua aturan tata kelola ditegakkan di sini:
 *  - pelaksana wajib terdaftar di registry supervisor dan lolos uji
 *    `assertAssignable` (bukan diri sendiri, dalam masa berlaku, dalam cakupan);
 *  - instrumen yang dipakai wajib **sudah terbit** dan versinya dibekukan pada
 *    kegiatan (revisi instrumen tidak mengubah kegiatan yang sedang berjalan);
 *  - perpindahan status mengikuti graf di `VisitStatus::allowedNext()`.
 */
class VisitLifecycleService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SupervisorDirectoryService $directory,
        private readonly VisitAccessService $access,
    ) {}

    /**
     * Buat kegiatan supervisi baru.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(array $data, User $actor): SupVisit
    {
        $supervisorId = (int) ($data['supervisor_employee_id'] ?? $this->access->employeeIdFor($actor) ?? 0);
        $superviseeId = (int) ($data['supervisee_employee_id'] ?? 0);

        if ($supervisorId === 0) {
            $this->fail('supervisor_employee_id', 'Pelaksana supervisi tidak dapat ditentukan dari akun ini; pilih supervisor secara eksplisit.');
        }

        $supervisee = Employee::query()->find($superviseeId);
        if ($supervisee === null) {
            $this->fail('supervisee_employee_id', 'Pegawai yang akan disupervisi tidak ditemukan.');
        }

        if (! $supervisee->is_active) {
            $this->fail('supervisee_employee_id', 'Pegawai tersebut sudah tidak aktif.');
        }

        $version = $this->resolveVersion($data);

        $registry = SupSupervisor::query()->where('employee_id', $supervisorId)->first();
        if ($registry === null) {
            $this->fail('supervisor_employee_id', 'Pegawai tersebut belum terdaftar sebagai supervisor pada Pengaturan Supervisor.');
        }

        $this->directory->assertAssignable($registry, $supervisee, $version->instrument?->category);

        $schedule = null;
        if (! empty($data['schedule_id'])) {
            $schedule = SupSchedule::query()->find((int) $data['schedule_id']);
            if ($schedule === null) {
                $this->fail('schedule_id', 'Jadwal yang dirujuk tidak ditemukan.');
            }
        }

        $status = $schedule !== null || ! empty($data['observed_at'])
            ? VisitStatus::SCHEDULED
            : VisitStatus::DRAFT;

        return DB::transaction(function () use ($data, $actor, $supervisorId, $supervisee, $version, $schedule, $status): SupVisit {
            $visit = new SupVisit();
            $visit->forceFill([
                'code' => $this->nextCode(),
                'program_id' => $data['program_id'] ?? $schedule?->program_id,
                'schedule_id' => $schedule?->id,
                'assignment_id' => $data['assignment_id'] ?? $schedule?->assignment_id,
                'supervisor_employee_id' => $supervisorId,
                'supervisee_employee_id' => $supervisee->id,
                'activity_kind' => $data['activity_kind'] ?? $schedule?->activity_kind?->value ?? 'CLASS_OBSERVATION',
                'instrument_version_id' => $version->id,
                'approach' => $data['approach'] ?? 'COLLABORATIVE',
                'status' => $status->value,
                'subject_id' => $data['subject_id'] ?? $schedule?->subject_id,
                'class_id' => $data['class_id'] ?? $schedule?->class_id,
                'lesson_topic' => $data['lesson_topic'] ?? null,
                'observed_at' => $data['observed_at'] ?? $schedule?->planned_at,
                'duration_minutes' => $data['duration_minutes'] ?? $schedule?->duration_minutes,
                'pre_conference_at' => $data['pre_conference_at'] ?? null,
                'post_conference_at' => $data['post_conference_at'] ?? null,
                'client_uuid' => $data['client_uuid'] ?? null,
                'created_by' => $actor->id,
            ]);
            $visit->save();

            $this->audit->log('visit.created', $visit, null, $visit->toArray(), null, $actor, [
                'instrument_version_id' => $version->id,
                'schedule_id' => $schedule?->id,
            ]);

            return $visit->fresh(['instrumentVersion.instrument']);
        });
    }

    /** Perpindahan status yang mengikuti graf `VisitStatus::allowedNext()`. */
    public function transition(SupVisit $visit, VisitStatus $to, ?User $actor = null, ?string $reason = null): SupVisit
    {
        if ($visit->status === $to) {
            return $visit;
        }

        if (! in_array($to, $visit->status->allowedNext(), true)) {
            $this->fail('status', "Perpindahan status dari {$visit->status->label()} ke {$to->label()} tidak diizinkan.");
        }

        $before = ['status' => $visit->status->value, 'finalized_at' => $visit->finalized_at?->toIso8601String()];
        $visit->status = $to;
        $visit->save();

        $this->audit->log('visit.status_changed', $visit, $before, ['status' => $to->value], $reason, $actor);

        return $visit;
    }

    /** Kegiatan hanya dapat diisi selama belum difinalkan/dibatalkan. */
    public function assertEditable(SupVisit $visit): void
    {        if ($visit->status === VisitStatus::CANCELLED) {
            $this->fail('visit', 'Kegiatan ini sudah dibatalkan.');
        }

        if ($visit->finalized_at !== null) {
            $this->fail('visit', 'Hasil kegiatan sudah difinalkan. Ajukan pembukaan ulang bila ada koreksi.');
        }
    }

    /**
     * Kirim kegiatan untuk dianalisis: pastikan sudah melewati tahap observasi.
     *
     * Rantai status mengikuti graf `allowedNext()` sehingga kegiatan yang
     * langsung diisi di lapangan tetap punya jejak tahapan yang sah.
     */
    public function submitForAnalysis(SupVisit $visit, ?User $actor = null): SupVisit
    {
        $this->assertEditable($visit);

        $chain = match ($visit->status) {
            VisitStatus::DRAFT => [VisitStatus::SCHEDULED, VisitStatus::OBSERVED, VisitStatus::ANALYZED],
            VisitStatus::SCHEDULED, VisitStatus::PRE_CONFERENCE => [VisitStatus::OBSERVED, VisitStatus::ANALYZED],
            VisitStatus::OBSERVED => [VisitStatus::ANALYZED],
            default => [],
        };

        foreach ($chain as $status) {
            if ($status === VisitStatus::OBSERVED && $visit->observed_at === null) {
                $visit->forceFill(['observed_at' => now()])->save();
            }

            $this->transition($visit, $status, $actor);
        }

        return $visit->refresh();
    }

    public function cancel(SupVisit $visit, ?User $actor, ?string $reason = null): SupVisit
    {
        if ($visit->status === VisitStatus::CANCELLED) {
            return $visit;
        }

        if ($visit->finalized_at !== null && ! ($actor?->can('supervisi.visit.reopen') ?? false)) {
            $this->fail('visit', 'Kegiatan yang sudah difinalkan tidak dapat dibatalkan tanpa izin pembukaan ulang.');
        }

        $before = ['status' => $visit->status->value];
        $visit->status = VisitStatus::CANCELLED;
        $visit->finalized_at = $visit->finalized_at;
        $visit->save();

        if ($visit->schedule !== null && $visit->schedule->status !== ScheduleStatus::CANCELLED) {
            $visit->schedule->forceFill(['status' => ScheduleStatus::CANCELLED->value])->save();
        }

        $this->audit->log('visit.cancelled', $visit, $before, ['status' => VisitStatus::CANCELLED->value], $reason, $actor);

        return $visit;
    }

    /** Versi instrumen yang dipakai kegiatan (terbit & beku). */
    protected function resolveVersion(array $data): SupInstrumentVersion
    {
        $version = null;

        if (! empty($data['instrument_version_id'])) {
            $version = SupInstrumentVersion::query()->with('instrument')->find((int) $data['instrument_version_id']);
        } elseif (! empty($data['instrument_id'])) {
            $instrument = \App\Modules\Supervisi\Models\SupInstrument::query()->with('currentVersion.instrument')->find((int) $data['instrument_id']);
            $version = $instrument?->currentVersion;
        }

        if ($version === null) {
            $this->fail('instrument_version_id', 'Instrumen supervisi wajib dipilih.');
        }

        if ($version->status !== InstrumentVersionStatus::PUBLISHED) {
            $this->fail('instrument_version_id', 'Instrumen belum diterbitkan sehingga belum dapat dipakai pada kegiatan.');
        }

        return $version;
    }

    /** Nomor kegiatan: SUP/2026/000123. */
    protected function nextCode(): string
    {
        $year = (int) now()->year;
        $sequence = SupVisit::withTrashed()->whereYear('created_at', $year)->count() + 1;

        return sprintf('SUP/%d/%06d', $year, $sequence);
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
