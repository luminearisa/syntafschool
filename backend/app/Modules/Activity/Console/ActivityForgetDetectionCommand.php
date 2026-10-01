<?php

namespace App\Modules\Activity\Console;

use App\Modules\Activity\Domain\Enums\SessionStatus;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRegistration;
use App\Modules\Activity\Models\ActivitySession;
use App\Modules\Activity\Models\ActivityWaitlistEntry;
use Illuminate\Console\Command;

/**
 * Pemeriksaan anomali operasional — hal-hal yang sering terlewat manusia:
 * sesi lewat tanpa kehadiran, pendaftaran menggantung, daftar tunggu mengendap,
 * dan program terbit tanpa anggota.
 */
class ActivityForgetDetectionCommand extends Command
{
    protected $signature = 'activity:check-anomalies {--days= : Batas umur data menggantung (bawaan 14)}';

    protected $description = 'Deteksi anomali operasional modul aktivitas';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? 14);

        $sessionsWithoutAttendance = ActivitySession::query()
            ->where('date', '<', now()->toDateString())
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->whereDoesntHave('attendance')
            ->count();

        $staleRegistrations = ActivityRegistration::query()
            ->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW'])
            ->where('submitted_at', '<', now()->subDays($days))
            ->count();

        $staleWaitlist = ActivityWaitlistEntry::query()
            ->where('status', 'WAITING')
            ->where('created_at', '<', now()->subDays($days))
            ->count();

        $emptyPrograms = ActivityProgram::query()
            ->whereIn('status', ['PUBLISHED', 'ACTIVE'])
            ->whereDoesntHave('memberships')
            ->count();

        $this->table(['Temuan', 'Jumlah'], [
            ['Sesi lewat tanpa kehadiran', $sessionsWithoutAttendance],
            ['Pendaftaran menggantung > '.$days.' hari', $staleRegistrations],
            ['Daftar tunggu mengendap > '.$days.' hari', $staleWaitlist],
            ['Program terbit tanpa anggota', $emptyPrograms],
        ]);

        return self::SUCCESS;
    }
}
