<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Contracts\StudentProviderInterface;
use App\Modules\Activity\Domain\Enums\AttendanceStatus;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Domain\Enums\SessionStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityAttendance;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivitySession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kehadiran aktivitas (§33–35).
 *
 * Tiga hal yang dijaga:
 *  - **Idempoten** — kunci unik (sesi, siswa) + `idempotency_key` membuat
 *    pengiriman ulang dari aplikasi lapangan (QR/NFC) tidak menghasilkan dua
 *    catatan kehadiran.
 *  - **Tidak menyentuh absensi akademik** — catatan aktivitas milik modul ini;
 *    modul Attendance hanya menerima **cerminan** bila sekolah mengaktifkannya.
 *  - **Persentase dihitung dari data, bukan ditebak** — dipakai untuk aturan
 *    kehadiran minimum, peringatan, dan kelayakan sertifikat.
 */
class ActivityAttendanceService
{
    public function __construct(
        private readonly ActivityAuditService $audit,
        private readonly ActivityAccessService $access,
        private readonly ActivityIntegrationService $integrations,
        private readonly StudentProviderInterface $students,
        private readonly ActivityNotificationService $notifications,
    ) {}

    /** Daftar hadir satu sesi: seluruh anggota aktif + catatan yang sudah ada. */
    public function sessionSheet(ActivitySession $session): array
    {
        $memberships = ActivityMembership::query()
            ->with('student:id,full_name,nis')
            ->where('program_id', $session->program_id)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->get();

        $records = ActivityAttendance::query()
            ->where('session_id', $session->id)
            ->get()
            ->keyBy(fn (ActivityAttendance $attendance) => (int) $attendance->student_id);

        return $memberships
            ->map(function (ActivityMembership $membership) use ($records) {
                $record = $records->get((int) $membership->student_id);

                return [
                    'student_id' => (int) $membership->student_id,
                    'membership_id' => (int) $membership->id,
                    'name' => $membership->student?->full_name,
                    'nis' => $membership->student?->nis,
                    'status' => $record?->status?->value,
                    'status_label' => $record?->status?->label(),
                    'minutes_late' => $record?->minutes_late,
                    'reason' => $record?->reason,
                    'notes' => $record?->notes,
                    'recorded_at' => $record?->recorded_at?->toIso8601String(),
                    'source' => $record?->source?->value,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Catat satu kehadiran (idempoten).
     *
     * @param  array{status: string, minutes_late?: int|null, reason?: string|null, notes?: string|null,
     *               source?: string, idempotency_key?: string|null}  $payload
     */
    public function record(ActivitySession $session, int $studentId, array $payload, User $actor): ActivityAttendance
    {
        if (! $this->access->canRecordAttendance($actor, $session)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang mencatat kehadiran sesi ini.');
        }

        if ($session->status === SessionStatus::CANCELLED) {
            throw ActivityConflictException::make('Sesi ini dibatalkan — kehadiran tidak dapat dicatat.');
        }

        $status = strtoupper((string) $payload['status']);

        if (! in_array($status, AttendanceStatus::values(), true)) {
            throw ActivityValidationException::withErrors(['Status kehadiran tidak dikenal: '.$status.'.']);
        }

        if (! $this->isMember($session, $studentId)) {
            throw ActivityValidationException::withErrors(['Siswa ini bukan anggota aktif program tersebut.']);
        }

        return DB::transaction(function () use ($session, $studentId, $payload, $actor, $status) {
            $membership = $this->access->membershipOf($studentId, (int) $session->program_id);

            $attendance = ActivityAttendance::query()->updateOrCreate(
                ['session_id' => $session->id, 'student_id' => $studentId],
                [
                    'membership_id' => $membership?->id,
                    'status' => $status,
                    'minutes_late' => $payload['minutes_late'] ?? null,
                    'reason' => $payload['reason'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'source' => $payload['source'] ?? 'MANUAL',
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                    'idempotency_key' => $payload['idempotency_key'] ?? null,
                ],
            );

            // Cerminan ke modul Attendance hanya untuk kehadiran yang bermakna
            // bagi absensi akademik; izin/sakit tetap tercatat di sini.
            if ($attendance->attendance_reference === null && (bool) config('activity.attendance.mirror', false)) {
                $snapshot = $this->students->snapshot($studentId);

                $reference = $this->integrations->call('ATTENDANCE', 'mirror', [
                    'subject_type' => ActivityAttendance::class,
                    'subject_id' => $attendance->id,
                    'student_id' => $studentId,
                ], fn () => [
                    'status' => 'OK',
                    'reference' => $this->integrations->attendance()->mirror([
                        'student_id' => $studentId,
                        'class_id' => $snapshot['class_id'] ?? null,
                        'date' => $session->date?->toDateString(),
                        'status' => $status,
                        'notes' => 'Aktivitas '.($session->program?->name ?? ''),
                        // `student_attendances.recorded_by` menunjuk ke tabel pegawai,
                        // bukan pengguna — pengelola yang bukan pegawai dicatat null.
                        'recorded_by' => $this->access->employeeIdForUser($actor),
                    ]),
                ]);

                if (($reference['reference'] ?? null) !== null) {
                    $attendance->forceFill(['attendance_reference' => $reference['reference']])->save();
                }
            }

            $this->audit->record(AuditAction::ATTENDANCE_RECORDED, [
                'subject' => $attendance,
                'program_id' => (int) $session->program_id,
                'student_id' => $studentId,
                'after' => $attendance,
                'actor' => $actor->id,
            ]);

            $this->recalculateStudentProgress($studentId, (int) $session->program_id);

            return $attendance->refresh();
        });
    }

    /**
     * Catat kehadiran banyak siswa sekaligus (isi daftar hadir satu layar).
     *
     * @param  list<array{student_id: int, status: string, minutes_late?: int|null, reason?: string|null, notes?: string|null}>  $rows
     * @return array{recorded: int, failed: list<array>}
     */
    public function recordBulk(ActivitySession $session, array $rows, User $actor, ?string $idempotencyKey = null): array
    {
        $recorded = 0;
        $failed = [];

        foreach ($rows as $row) {
            try {
                $this->record($session, (int) $row['student_id'], array_merge($row, [
                    'idempotency_key' => $idempotencyKey === null ? null : $idempotencyKey.'|'.$row['student_id'],
                ]), $actor);
                $recorded++;
            } catch (ActivityValidationException|ActivityConflictException $exception) {
                $failed[] = ['student_id' => $row['student_id'] ?? null, 'message' => $exception->getMessage()];
            }
        }

        if ($recorded > 0 && $session->status === SessionStatus::SCHEDULED) {
            $session->forceFill(['status' => SessionStatus::OPEN->value])->save();
        }

        return ['recorded' => $recorded, 'failed' => $failed];
    }

    public function completeSession(ActivitySession $session, User $actor): ActivitySession
    {
        if (! $this->access->canRecordAttendance($actor, $session)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang menyelesaikan sesi ini.');
        }

        $session->forceFill([
            'status' => SessionStatus::COMPLETED->value,
            'attendance_locked_at' => now(),
        ])->save();

        $this->notifyMissingAttendance($session);

        return $session->refresh();
    }

    /** Rekap kehadiran siswa pada satu program (§35). */
    public function studentSummary(int $studentId, int $programId): array
    {
        $rows = ActivityAttendance::query()
            ->with('session:id,date,program_id')
            ->whereHas('session', fn ($query) => $query->where('program_id', $programId)->where('status', '!=', SessionStatus::CANCELLED->value))
            ->where('student_id', $studentId)
            ->get();

        // Penyebut = pertemuan yang sudah berlangsung, bukan jumlah catatan yang
        // sudah diisi: siswa yang hadir 1 dari 3 pertemuan harus terbaca 33%.
        $held = ActivitySession::query()
            ->where('program_id', $programId)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->where('date', '<=', now()->toDateString())
            ->count();

        $total = $rows->count();
        $present = $rows->filter(fn (ActivityAttendance $row) => $row->status?->countsAsPresent() ?? false)->count();
        $excused = $rows->filter(fn (ActivityAttendance $row) => $row->status?->isExcused() ?? false)->count();
        $late = $rows->filter(fn (ActivityAttendance $row) => $row->status === AttendanceStatus::LATE)->count();

        return [
            'student_id' => $studentId,
            'program_id' => $programId,
            'sessions_held' => $held,
            'sessions_recorded' => $total,
            'present' => $present,
            'excused' => $excused,
            'late' => $late,
            'absent' => $rows->filter(fn (ActivityAttendance $row) => $row->status === AttendanceStatus::ABSENT)->count(),
            'percentage' => $held === 0 ? null : round($present / $held * 100, 2),
            'trend' => $rows
                ->sortBy(fn (ActivityAttendance $row) => $row->session?->date?->toDateString())
                ->map(fn (ActivityAttendance $row) => [
                    'date' => $row->session?->date?->toDateString(),
                    'status' => $row->status?->value,
                ])
                ->values()
                ->all(),
        ];
    }

    /** Rekap kehadiran satu program (layar pembina). */
    public function programSummary(int $programId): array
    {
        $memberships = ActivityMembership::query()
            ->with('student:id,full_name')
            ->where('program_id', $programId)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->get();

        $students = $memberships->map(fn (ActivityMembership $membership) => [
            'student_id' => (int) $membership->student_id,
            'name' => $membership->student?->full_name,
            'role' => $membership->role?->value,
            'summary' => $this->studentSummary((int) $membership->student_id, $programId),
        ]);

        $sessions = ActivitySession::query()->where('program_id', $programId)->count();

        // Kelengkapan dihitung dari catatan milik **anggota yang masih aktif**:
        // catatan anggota yang sudah berhenti tetap tersimpan (memang harus),
        // tetapi tidak boleh membuat kelengkapan terlihat lebih dari 100%.
        $memberIds = $memberships->pluck('student_id')->all();

        $recorded = ActivityAttendance::query()
            ->whereHas('session', fn ($query) => $query->where('program_id', $programId))
            ->whereIn('student_id', $memberIds === [] ? [-1] : $memberIds)
            ->count();

        $expected = $sessions * $memberships->count();

        return [
            'program_id' => $programId,
            'sessions' => $sessions,
            'attendance_records' => $recorded,
            'coverage' => $expected === 0 ? null : round(min(100, $recorded / $expected * 100), 2),
            'students' => $students->all(),
        ];
    }

    /** Hitung ulang progres siswa (dipakai setelah kehadiran/penilaian berubah). */
    public function recalculateStudentProgress(int $studentId, int $programId): void
    {
        $membership = $this->access->membershipOf($studentId, $programId);

        if ($membership === null) {
            return;
        }

        $summary = $this->studentSummary($studentId, $programId);
        $percentage = $summary['percentage'] ?? 0.0;

        $minimum = $membership->program?->minimum_attendance_percentage;

        if ($minimum !== null && $percentage < (float) $minimum && $summary['sessions_held'] >= (int) config('activity.attendance.warning_after_sessions', 3)) {
            $this->notifications->notify([
                'event' => 'ATTENDANCE_MISSING',
                'program_id' => $programId,
                'student_id' => $studentId,
                'body' => 'Kehadiran '.($membership->program?->name ?? 'aktivitas').' baru '.$percentage.'% (minimum '.$minimum.'%).',
                'marker' => 'attendance-warning|'.$studentId.'|'.now()->format('Y-m'),
            ]);
        }

        $progress = \App\Modules\Activity\Models\ActivityProgress::query()->firstOrCreate(
            ['membership_id' => $membership->id],
            [
                'student_id' => $studentId,
                'program_id' => $programId,
                'period_id' => $membership->period_id,
                'status' => 'ACTIVE',
            ],
        );

        $progress->forceFill([
            'sessions_total' => $summary['sessions_held'],
            'sessions_attended' => $summary['present'],
            'attendance_percentage' => $percentage,
            'status' => $this->progressStatus($progress, $percentage),
            'calculated_at' => now(),
        ])->save();
    }

    protected function progressStatus(\App\Modules\Activity\Models\ActivityProgress $progress, float $percentage): string
    {
        if ($progress->completed_at !== null) {
            return 'COMPLETED';
        }

        if ($percentage <= 0) {
            return 'NOT_STARTED';
        }

        return $percentage >= (float) config('activity.progress.progressing_threshold', 60) ? 'PROGRESSING' : 'ACTIVE';
    }

    protected function isMember(ActivitySession $session, int $studentId): bool
    {
        return ActivityMembership::query()
            ->where('program_id', $session->program_id)
            ->where('student_id', $studentId)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->exists();
    }

    /** Peringatkan pembina bila ada sesi selesai yang kehadirannya belum lengkap. */
    protected function notifyMissingAttendance(ActivitySession $session): void
    {
        $members = ActivityMembership::query()
            ->where('program_id', $session->program_id)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->count();

        $recorded = ActivityAttendance::query()->where('session_id', $session->id)->count();

        if ($members > $recorded) {
            $this->notifications->notify([
                'event' => 'ATTENDANCE_MISSING',
                'program_id' => (int) $session->program_id,
                'body' => 'Kehadiran sesi '.$session->date?->toDateString().' baru terisi '.$recorded.' dari '.$members.' anggota.',
                'audience' => 'COACHES',
                'marker' => 'attendance-missing|'.$session->id,
            ]);
        }
    }
}
