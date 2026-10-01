<?php

namespace App\Modules\Activity\Console;

use App\Modules\Activity\Application\Services\ActivityNotificationService;
use App\Modules\Activity\Domain\Enums\SessionStatus;
use App\Modules\Activity\Models\ActivitySession;
use Illuminate\Console\Command;

/**
 * Pengingat pertemuan besok (§67).
 *
 * Kunci idempoten notifikasi memastikan siswa dan orang tua menerima pengingat
 * satu kali per sesi, walau perintah dijalankan berulang.
 */
class ActivitySessionReminderCommand extends Command
{
    protected $signature = 'activity:session-reminders {--date= : Tanggal sesi (bawaan: besok)}';

    protected $description = 'Kirim pengingat pertemuan aktivitas untuk besok';

    public function handle(ActivityNotificationService $notifications): int
    {
        $date = $this->option('date') ?? now()->addDay()->toDateString();

        $sessions = ActivitySession::query()
            ->with('program:id,name')
            ->where('date', $date)
            ->where('status', SessionStatus::SCHEDULED->value)
            ->get();

        $sent = 0;

        foreach ($sessions as $session) {
            $notifications->notify([
                'event' => 'SESSION_TOMORROW',
                'program_id' => (int) $session->program_id,
                'body' => 'Pertemuan '.($session->program?->name ?? 'aktivitas').' pada '.$date.' pukul '.substr((string) $session->start_time, 0, 5).'.',
                'audience' => 'MEMBERS',
                'program_id_for_audience' => (int) $session->program_id,
                'marker' => 'session|'.$session->id,
            ]);

            $sent++;
        }

        $this->info(sprintf('%d sesi diproses untuk %s.', $sent, $date));

        return self::SUCCESS;
    }
}
