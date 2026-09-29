<?php

namespace App\Modules\LMS\Console\Commands;

use App\Modules\LMS\Application\Services\GradeSyncQueueService;
use App\Modules\LMS\Domain\Support\LmsSystemActor;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsGradeSync;
use Illuminate\Console\Command;

/**
 * `php artisan lms:grade-sync` — memproses antrean nilai (§6, §11).
 *
 * Dipakai sebagai cadangan bila worker antrean sedang tidak berjalan:
 * perintah ini memproses baris yang menunggu **tanpa** menggandakan kiriman
 * (status & kunci idempotensi diperiksa service).
 */
class LmsGradeSyncCommand extends Command
{
    protected $signature = 'lms:grade-sync
        {--course= : Batasi pada satu course}
        {--retry-failed : Antrekan ulang nilai yang gagal lalu proses}
        {--limit=50 : Maksimum baris yang diproses}';

    protected $description = 'Memproses antrean sinkronisasi nilai LMS ke SIAKAD';

    public function handle(GradeSyncQueueService $queue): int
    {
        $actor = LmsSystemActor::make();
        $courseId = $this->option('course');
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('retry-failed')) {
            $gradebooks = LmsGradebook::query()
                ->when($courseId !== null, fn ($query) => $query->where('course_id', (int) $courseId))
                ->get();

            foreach ($gradebooks as $gradebook) {
                $result = $queue->retryFailed($gradebook, $actor, dispatch: false);
                $this->line('  course #'.$gradebook->course_id.': '.$result['retried'].' nilai diantrekan ulang.');
            }
        }

        $rows = LmsGradeSync::query()
            ->when($courseId !== null, fn ($query) => $query->whereIn('gradebook_id', LmsGradebook::query()->where('course_id', (int) $courseId)->pluck('id')))
            ->whereIn('status', ['PENDING', 'RETRYING', 'PROCESSING'])
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada sinkronisasi yang menunggu.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        foreach ($rows as $sync) {
            $result = $queue->process($sync, $actor);

            if (($result['status'] ?? null) === 'SYNCED') {
                $synced++;
            } else {
                $failed++;
            }
        }

        $this->info('Selesai: '.$synced.' tersinkron, '.$failed.' belum/ditunda.');

        return self::SUCCESS;
    }
}
