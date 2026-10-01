<?php

namespace App\Modules\Bk\Console;

use App\Modules\Bk\Application\Services\BkAppointmentService;
use Illuminate\Console\Command;

/**
 * Kirim pengingat janji temu yang akan datang.
 *
 * Pesan hanya memuat waktu dan nomor janji — tidak ada isi konseling, sehingga
 * pengingat yang mendarat di ponsel siswa/orang tua tidak membocorkan apa pun.
 */
class BkSendAppointmentRemindersCommand extends Command
{
    protected $signature = 'bk:send-appointment-reminders {--hours= : Jendela pengingat dalam jam}';

    protected $description = 'Kirim pengingat janji temu BK yang akan datang.';

    public function handle(BkAppointmentService $appointments): int
    {
        $hours = (int) ($this->option('hours') ?? config('bk.windows.appointment_reminder_hours', 24));
        $sent = $appointments->sendReminders($hours);

        $this->info(sprintf('Pengingat janji temu dikirim: %d.', $sent));

        return self::SUCCESS;
    }
}
