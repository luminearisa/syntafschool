<?php

namespace App\Modules\LMS\Jobs;

use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Enums\ReportStatus;
use App\Modules\LMS\Models\LmsNotificationLog;
use App\Modules\LMS\Models\LmsReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Penyapu data sementara (§39).
 *
 * Hanya menghapus yang memang sementara: berkas laporan yang kedaluwarsa dan
 * catatan notifikasi lama. **Tidak** menyentuh bukti belajar (submission,
 * percobaan kuis, nilai, sertifikat) — data itu hanya boleh dibersihkan lewat
 * kebijakan retensi dengan persetujuan.
 */
class PruneLmsTemporaryDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly bool $force = false) {}

    /**
     * @return array{exports:int,notifications:int,orphan_files:int,dry_run:bool}
     */
    public function handle(FileStorageProviderInterface $files): array
    {
        $summary = ['exports' => 0, 'notifications' => 0, 'orphan_files' => 0, 'dry_run' => ! $this->force];

        $exports = LmsReportExport::query()
            ->where('status', ReportStatus::READY->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($exports as $export) {
            if ($this->force && $export->file_path !== null) {
                $files->delete((string) $export->file_path);
                $export->fill(['status' => ReportStatus::PENDING->value, 'file_path' => null, 'storage_disk' => null])->save();
            }

            $summary['exports']++;
        }

        $keepDays = (int) config('lms.retention.notification_keep_days', 180);
        $cutoff = now()->subDays(max(7, $keepDays));

        $query = LmsNotificationLog::query()->where('created_at', '<', $cutoff);

        if ($this->force) {
            $summary['notifications'] = (int) $query->delete();
        } else {
            $summary['notifications'] = (int) $query->count();
        }

        // Berkas backup/laporan yang tidak punya baris pelacak lagi.
        $disk = Storage::disk($files->disk());
        $known = LmsReportExport::query()->whereNotNull('file_path')->pluck('file_path')->all();
        $known = array_map('strval', $known);
        $lifetime = $files->temporaryLifetimeHours();

        foreach ($disk->files('lms/reports') as $path) {
            if (in_array($path, $known, true)) {
                continue;
            }

            if ($disk->lastModified($path) > now()->subHours($lifetime)->timestamp) {
                continue;
            }

            if ($this->force) {
                $disk->delete($path);
            }

            $summary['orphan_files']++;
        }

        return $summary;
    }
}
