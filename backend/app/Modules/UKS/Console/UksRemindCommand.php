<?php

namespace App\Modules\UKS\Console;

use App\Modules\UKS\Application\Services\UksReminderService;
use Illuminate\Console\Command;

/** `php artisan uks:remind` — pengingat harian stok, kedaluwarsa, imunisasi. */
class UksRemindCommand extends Command
{
    protected $signature = 'uks:remind {--dry-run : Hitung saja, jangan kirim notifikasi}';

    protected $description = 'Mengirim pengingat UKS: stok menipis, obat kedaluwarsa, imunisasi jatuh tempo, kunjungan tertinggal.';

    public function handle(UksReminderService $reminders): int
    {
        $counts = $reminders->run((bool) $this->option('dry-run'));

        foreach ($counts as $type => $sent) {
            $this->line(str_pad($type, 20).': '.$sent.' pengingat'.($this->option('dry-run') ? ' (pratinjau)' : ''));
        }

        return self::SUCCESS;
    }
}
