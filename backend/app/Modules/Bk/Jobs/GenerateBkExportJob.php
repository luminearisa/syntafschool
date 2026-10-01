<?php

namespace App\Modules\Bk\Jobs;

use App\Models\User;
use App\Modules\Bk\Application\Services\BkExportService;
use App\Modules\Bk\Domain\Enums\ExportFormat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pembuatan ekspor berat di latar belakang.
 *
 * Dipisah ke antrean karena ekspor besar menahan permintaan HTTP dan menghabiskan
 * memori; pekerjaan ini juga dibuat idempoten dari sisi data (menulis satu berkas
 * bernama unik) sehingga percobaan ulang tidak menggandakan berkas berkas penting.
 */
class GenerateBkExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /** @param array<string, mixed> $filters */
    public function __construct(
        public readonly int $actorId,
        public readonly string $dataset,
        public readonly string $format,
        public readonly array $filters = [],
        public readonly ?int $expectedRows = null,
    ) {
        $this->onQueue((string) config('bk.queue.default', 'bk'));
    }

    public function handle(BkExportService $exports): void
    {
        $actor = User::query()->find($this->actorId);

        if ($actor === null) {
            return;
        }

        $rows = $exports->rows($actor, $this->dataset, $this->filters);
        $result = $exports->write($actor, $this->dataset, ExportFormat::from($this->format), $rows);

        if ($this->expectedRows !== null && count($rows) !== $this->expectedRows) {
            // Perbedaan jumlah bukan kegagalan: data dapat berubah antara pemeriksaan
            // izin dan pembuatan berkas. Dicatat supaya tidak membingungkan.
            Log::info('Ekspor BK: jumlah baris berbeda dari perkiraan awal.', [
                'dataset' => $this->dataset,
                'expected' => $this->expectedRows,
                'actual' => count($rows),
            ]);
        }

        if ($result['status'] === 'ready') {
            app(\App\Modules\Bk\Application\Services\BkNotificationService::class)->notifyUser(
                $this->actorId,
                \App\Modules\Bk\Domain\Enums\NotificationEvent::EXPORT_READY,
                'Ekspor BK siap diunduh.',
                ['dataset' => $this->dataset, 'rows' => count($rows), 'path' => $result['path']],
            );
        }
    }
}
