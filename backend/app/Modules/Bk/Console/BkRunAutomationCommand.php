<?php

namespace App\Modules\Bk\Console;

use App\Modules\Bk\Application\Services\BkAutomationService;
use Illuminate\Console\Command;

/**
 * Menjalankan seluruh otomasi harian BK.
 *
 * Satu perintah untuk semua alur, supaya penjadwalan di server tidak perlu tahu
 * urutan internal modul. Setiap tindakan tetap idempoten pada tingkat datanya.
 */
class BkRunAutomationCommand extends Command
{
    protected $signature = 'bk:run-automation {--only= : Jalankan hanya satu alur (reminders|consent|closure)}';

    protected $description = 'Jalankan otomasi BK: pengingat, eskalasi tindak lanjut, persetujuan berakhir, dan kasus siap ditutup.';

    public function handle(BkAutomationService $automation): int
    {
        $only = $this->option('only');

        if ($only === null || $only === 'reminders') {
            $appointments = $automation->appointmentReminders();
            $followUps = $automation->followUpReminders();

            $this->info(sprintf(
                'Pengingat: %d janji temu, %d tindak lanjut; eskalasi keterlambatan: %d.',
                $appointments['reminders_sent'],
                $followUps['due_reminders'],
                $followUps['overdue_escalations'],
            ));
        }

        if ($only === null || $only === 'consent') {
            $consent = $automation->consentExpiring();
            $this->info(sprintf('Persetujuan: %d akan berakhir, %d pengingat dikirim.', $consent['expiring'], $consent['reminders_sent']));
        }

        if ($only === null || $only === 'closure') {
            $closure = $automation->caseReadyToClose();
            $this->info(sprintf('Penutupan: %d kandidat ditinjau, %d ditandai.', $closure['candidates'], $closure['flagged']));
        }

        return self::SUCCESS;
    }
}
