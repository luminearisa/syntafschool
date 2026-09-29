<?php

namespace App\Modules\LMS\Console\Commands;

use App\Modules\LMS\Application\Services\LmsGateway;
use App\Modules\LMS\Jobs\SendLearningRemindersJob;
use Illuminate\Console\Command;

/** `php artisan lms:reminders` — pengingat tenggat & keterlambatan (§10). */
class LmsReminderCommand extends Command
{
    protected $signature = 'lms:reminders
        {--overdue : Kirim pengingat untuk tenggat yang sudah lewat}
        {--now : Jalankan langsung tanpa antrean}';

    protected $description = 'Mengirim pengingat tenggat aktivitas kepada siswa';

    public function handle(): int
    {
        $overdue = (bool) $this->option('overdue');

        if ($this->option('now')) {
            (new SendLearningRemindersJob($overdue))->handle(app(LmsGateway::class));
            $this->info('Pengingat '.($overdue ? 'keterlambatan' : 'tenggat').' dikirim langsung.');
        } else {
            SendLearningRemindersJob::dispatch($overdue);
            $this->info('Pengingat '.($overdue ? 'keterlambatan' : 'tenggat').' dimasukkan ke antrean.');
        }

        return self::SUCCESS;
    }
}
