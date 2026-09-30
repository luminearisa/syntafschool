<?php

namespace App\Modules\Supervisi\Console;

use App\Modules\Supervisi\Application\Services\ReminderService;
use Illuminate\Console\Command;

/**
 * Pengingat supervisi harian (Fase 4).
 *
 * Dijalankan penjadwal setiap pagi. Aman diulang: pengingat yang sama tidak
 * dikirim dua kali pada hari yang sama. `--dry-run` dipakai saat pemeriksaan
 * agar kepala sekolah dapat melihat apa yang akan dikirim tanpa mengirim.
 */
class SupervisiRemindCommand extends Command
{
    protected $signature = 'supervisi:remind
        {--dry-run : Hitung pengingat tanpa mengirim notifikasi}
        {--date= : Tanggal acuan (Y-m-d), bawaan hari ini}';

    protected $description = 'Kirim pengingat kegiatan, RTL, coaching, dan tinjauan PIP supervisi';

    public function handle(ReminderService $reminders): int
    {
        $date = $this->option('date');
        $dryRun = (bool) $this->option('dry-run');

        $counts = $reminders->run($date === null ? null : \Illuminate\Support\Carbon::parse($date), $dryRun);

        $this->table(
            ['Jenis pengingat', 'Jumlah'],
            collect($counts)->map(fn (int $count, string $type) => [$type, $count])->values()->all(),
        );

        $total = array_sum($counts);

        $this->info($dryRun
            ? "Pratinjau: {$total} pengingat siap dikirim."
            : "Selesai: {$total} pengingat terkirim.");

        return self::SUCCESS;
    }
}
