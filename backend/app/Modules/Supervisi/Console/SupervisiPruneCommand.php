<?php

namespace App\Modules\Supervisi\Console;

use App\Modules\Supervisi\Models\SupReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Penyapu berkas dokumen lama (Fase 5 — retensi).
 *
 * Retensi blueprint §11.7 menuntut hasil supervisi disimpan minimal 5 tahun,
 * tetapi **berkas ekspor** (CSV/XLSX/PDF) tidak perlu ikut menumpuk selamanya:
 * isinya dapat dihasilkan ulang dari data yang tetap tersimpan.
 *
 * Karena itu penyapu ini hanya menghapus berkas fisik. **Baris riwayatnya
 * dibiarkan utuh** — kode verifikasi, sidik digital, siapa dan kapan
 * menghasilkannya — sehingga jejak dokumen tidak hilang dan penyalahgunaan
 * (dokumen lama yang masih beredar) tetap dapat dilacak.
 *
 * Penghapusan hanya berjalan bila `--force` diberikan; tanpa itu perintah hanya
 * melaporkan apa yang **akan** dihapus.
 */
class SupervisiPruneCommand extends Command
{
    protected $signature = 'supervisi:prune
        {--months= : Umur minimum berkas (bulan), bawaan dari config/supervisi.php}
        {--dry-run : Hanya melaporkan (bawaan bila --force tidak diberikan)}
        {--force : Benar-benar menghapus berkas}';

    protected $description = 'Hapus berkas dokumen supervisi yang melewati masa retensi';

    public function handle(): int
    {
        $months = (int) ($this->option('months') ?? config('supervisi.report_retention_months', 24));
        $force = (bool) $this->option('force') && ! $this->option('dry-run');
        $cutoff = now()->subMonths(max(1, $months));

        $candidates = SupReport::query()
            ->whereNotNull('path')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        $existed = $candidates->filter(fn (SupReport $report) => Storage::disk('local')->exists($report->path));
        $missing = $candidates->diff($existed);

        $this->table(['Keterangan', 'Jumlah'], [
            ['Berkas lebih tua dari '.$months.' bulan', $candidates->count()],
            ['Berkas ada di penyimpanan', $existed->count()],
            ['Sudah hilang sebelumnya', $missing->count()],
            ['Mode', $force ? 'HAPUS' : 'PRATINJAU (tidak menghapus apa pun)'],
        ]);

        $deleted = 0;
        $freed = 0;

        foreach ($existed as $report) {
            $size = (int) Storage::disk('local')->size($report->path);

            if (! $force) {
                continue;
            }

            Storage::disk('local')->delete($report->path);
            $report->forceFill(['path' => null, 'status' => 'PRUNED'])->save();
            $deleted++;
            $freed += $size;
        }

        if ($force) {
            $this->info(sprintf('Selesai: %d berkas dihapus, %.1f MB dibebaskan. Baris riwayat & kode verifikasi tetap tersimpan.', $deleted, $freed / 1048576));
        } else {
            $this->info(sprintf(
                'Pratinjau: %d berkas siap dihapus (%.1f MB). Jalankan dengan --force untuk menghapus. Baris riwayat tidak akan dihapus.',
                $existed->count(),
                $existed->sum(fn (SupReport $report) => (int) Storage::disk('local')->size($report->path)) / 1048576,
            ));
        }

        return self::SUCCESS;
    }
}
