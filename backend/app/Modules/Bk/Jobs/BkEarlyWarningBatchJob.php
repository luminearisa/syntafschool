<?php

namespace App\Modules\Bk\Jobs;

use App\Modules\Bk\Application\Services\BkEarlyWarningEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Evaluasi aturan peringatan dini untuk seluruh siswa di latar belakang.
 *
 * Dipisah ke antrean karena evaluasi menyentuh beberapa modul sekaligus; menjalankannya
 * di dalam permintaan HTTP akan membuat halaman menunggu tanpa alasan.
 */
class BkEarlyWarningBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly ?int $actorId = null, public readonly ?int $limit = null)
    {
        $this->onQueue((string) config('bk.early_warning.evaluation_queue', 'bk-early-warning'));
    }

    public function handle(BkEarlyWarningEngine $engine): void
    {
        $actor = $this->actorId !== null ? \App\Models\User::query()->find($this->actorId) : null;
        $summary = $engine->evaluateBatch($actor, limit: $this->limit);

        Log::info('Evaluasi peringatan dini selesai.', [
            'evaluated' => $summary['evaluated'],
            'actioned' => $summary['actioned'],
            'skipped' => $summary['skipped'],
            'cooldown' => $summary['cooldown'],
        ]);
    }
}
