<?php

namespace App\Modules\CBT\Console\Commands;

use App\Modules\CBT\Application\Services\CbtExamService;
use App\Modules\CBT\Domain\Enums\AttemptStatus;
use App\Modules\CBT\Models\CbtAttempt;
use Illuminate\Console\Command;

class CbtExpireAttemptsCommand extends Command
{
    protected $signature = 'cbt:expire-attempts';

    protected $description = 'Kumpulkan otomatis percobaan CBT yang melewati batas waktu server.';

    public function handle(CbtExamService $exams): int
    {
        $count = 0;
        CbtAttempt::query()
            ->where('status', AttemptStatus::IN_PROGRESS)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->orderBy('id')
            ->each(function (CbtAttempt $attempt) use ($exams, &$count) {
                $exams->submit($attempt, true);
                $count++;
            });

        $this->info("Percobaan kedaluwarsa diproses: {$count}");

        return self::SUCCESS;
    }
}
