<?php

namespace App\Modules\LMS\Console\Commands;

use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Jobs\PruneLmsTemporaryDataJob;
use Illuminate\Console\Command;

/**
 * `php artisan lms:prune` — penyapu data sementara (§39).
 *
 * Tanpa `--force` perintah ini hanya **melapor** apa yang akan dihapus.
 */
class LmsPruneCommand extends Command
{
    protected $signature = 'lms:prune {--force : Benar-benar menghapus berkas/catatan sementara}';

    protected $description = 'Melaporkan (atau menghapus) data sementara LMS: ekspor kedaluwarsa & catatan notifikasi lama';

    public function handle(FileStorageProviderInterface $files): int
    {
        $summary = (new PruneLmsTemporaryDataJob((bool) $this->option('force')))->handle($files);

        $this->info(($summary['dry_run'] ? 'Pratinjau' : 'Dijalankan').': '
            .$summary['exports'].' ekspor kedaluwarsa, '
            .$summary['notifications'].' catatan notifikasi, '
            .$summary['orphan_files'].' berkas laporan yatim.');

        if ($summary['dry_run']) {
            $this->line('Jalankan ulang dengan --force untuk benar-benar menghapus.');
        }

        return self::SUCCESS;
    }
}
