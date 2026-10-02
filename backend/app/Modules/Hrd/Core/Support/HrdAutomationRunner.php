<?php

namespace App\Modules\Hrd\Core\Support;

use App\Modules\Hrd\Core\Models\HrAutomationRun;
use Closure;
use Throwable;

/**
 * Pembungkus penjadwal otomasi (§79).
 *
 * Setiap tugas menulis `run_key` yang khas (mis. akrual cuti 2026-10). Karena
 * itu penjadwal boleh terpanggil dua kali — tugas yang sama tidak akan
 * dijalankan ulang, dan bila gagal, catatan kegagalannya tersimpan.
 */
class HrdAutomationRunner
{
    /** @param Closure():array<string, mixed> $callback */
    public function run(string $automation, string $runKey, Closure $callback): HrAutomationRun
    {
        $existing = HrAutomationRun::query()->where('run_key', $runKey)->where('status', 'COMPLETED')->first();

        if ($existing !== null) {
            return $existing;
        }

        $run = HrAutomationRun::query()->updateOrCreate(
            ['run_key' => $runKey],
            [
                'code' => sprintf('RUN-%s-%s', strtoupper($automation), now()->format('YmdHis')),
                'automation' => strtoupper($automation),
                'status' => 'RUNNING',
                'started_at' => now(),
                'finished_at' => null,
                'last_error' => null,
            ],
        );

        try {
            $summary = $callback();

            $run->forceFill([
                'status' => 'COMPLETED',
                'finished_at' => now(),
                'processed' => (int) ($summary['processed'] ?? 0),
                'failed' => (int) ($summary['failed'] ?? 0),
                'summary' => $summary,
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'FAILED',
                'finished_at' => now(),
                'failed' => (int) $run->failed + 1,
                'last_error' => $exception->getMessage(),
            ])->save();

            report($exception);
        }

        return $run->refresh();
    }
}
