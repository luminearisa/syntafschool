<?php

namespace App\Modules\LMS\Jobs;

use App\Modules\LMS\Application\Services\GradeSyncQueueService;
use App\Modules\LMS\Domain\Enums\GradeSyncStatus;
use App\Modules\LMS\Models\LmsGradeSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Menjalankan satu baris sinkronisasi nilai di antrean (§6, §11).
 *
 * `$uniqueId` membuat antrean tidak pernah memproses baris yang sama dua kali
 * sekaligus — pengaman tambahan di samping kunci baris dan `idempotency_key`.
 */
class ProcessGradeSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $syncId) {}

    public function uniqueId(): string
    {
        return 'lms-grade-sync-'.$this->syncId;
    }

    public function handle(GradeSyncQueueService $queue): void
    {
        $sync = LmsGradeSync::query()->find($this->syncId);

        if ($sync === null) {
            return; // Baris hilang (course/enrollment dibersihkan) — tidak ada yang perlu dikerjakan.
        }

        $queue->process($sync);
    }

    public function failed(Throwable $exception): void
    {
        $sync = LmsGradeSync::query()->find($this->syncId);

        if ($sync === null) {
            return;
        }

        $sync->fill([
            'status' => GradeSyncStatus::FAILED,
            'error_message' => mb_strimwidth($exception->getMessage(), 0, 500, '…'),
        ])->save();
    }
}
