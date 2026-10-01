<?php

namespace App\Modules\Bk\Console;

use App\Modules\Bk\Application\Services\BkRetentionService;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Menjalankan kebijakan retensi: pengarsipan berkala dan pengajuan pemusnahan.
 *
 * Pemusnahan tidak pernah dijalankan otomatis oleh perintah ini — ia hanya
 * diajukan. Eksekusinya menunggu persetujuan orang lain melalui layar retensi,
 * karena menghapus data secara otomatis tanpa persetujuan bukan otomasi yang
 * bertanggung jawab.
 */
class BkApplyRetentionCommand extends Command
{
    protected $signature = 'bk:apply-retention {--archive : Arsipkan rekaman yang sudah waktunya} {--request-disposal : Ajukan pemusnahan rekaman yang melewati masa simpan}';

    protected $description = 'Terapkan kebijakan retensi BK (pengarsipan dan pengajuan pemusnahan).';

    public function handle(BkRetentionService $retention): int
    {
        $actor = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['BK_ADMIN', 'BK_COORDINATOR']))
            ->orderBy('id')
            ->first();

        if ($actor === null) {
            $this->warn('Tidak ada pengguna BK_ADMIN/BK_COORDINATOR; retensi dilewati.');

            return self::SUCCESS;
        }

        if ($this->option('archive') || ! $this->option('request-disposal')) {
            $archive = $retention->archiveDue($actor);
            $this->info(sprintf('Pengarsipan: %d rekaman diarsipkan.', $archive['archived']));
        }

        if ($this->option('request-disposal')) {
            $disposal = $retention->requestDisposal($actor);
            $this->info(sprintf('Pemusnahan: %d permintaan diajukan, menunggu persetujuan.', $disposal['requested']));
        }

        return self::SUCCESS;
    }
}
