<?php

namespace App\Modules\Hrd\Console;

use App\Modules\Hrd\Application\Support\IntegrationOutbox;
use Illuminate\Console\Command;

/**
 * Pengiriman outbox integrasi (§66).
 *
 * Peristiwa HR yang perlu diketahui modul lain (perangkat onboarding, clearance
 * aset/keuangan, penonaktifan presensi, pembuatan akun) dikirim dari sini. Baris
 * yang modul tujuannya belum terpasang tetap `pending` — bukan gagal — sehingga
 * memasang modulnya kelak langsung memproses antrean yang menunggu.
 */
class HrdDispatchIntegrationOutboxCommand extends Command
{
    protected $signature = 'hrd:dispatch-outbox {--limit=100 : Jumlah maksimum baris per jalan}';

    protected $description = 'Kirim peristiwa HRD yang menunggu ke modul ERP lain melalui outbox.';

    public function handle(IntegrationOutbox $outbox): int
    {
        $stats = $outbox->dispatch((int) $this->option('limit'));

        $this->info(sprintf(
            'Outbox: %d terkirim, %d ditunda (modul tujuan belum siap), %d gagal.',
            $stats['dispatched'],
            $stats['skipped'],
            $stats['failed'],
        ));

        foreach ($outbox->status() as $module => $statuses) {
            $this->line("  {$module}: ".json_encode($statuses));
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
