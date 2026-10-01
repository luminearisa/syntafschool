<?php

namespace App\Modules\Activity\Console;

use App\Modules\Activity\Application\Services\ActivityConsentService;
use Illuminate\Console\Command;

/**
 * Persetujuan yang tidak diputuskan hingga batas waktu menjadi EXPIRED — bukan
 * dianggap setuju (§25).
 */
class ActivityExpireConsentsCommand extends Command
{
    protected $signature = 'activity:expire-consents';

    protected $description = 'Tandai persetujuan orang tua yang kedaluwarsa';

    public function handle(ActivityConsentService $consents): int
    {
        $count = $consents->expireOverdue();

        $this->info(sprintf('%d persetujuan ditandai kedaluwarsa.', $count));

        return self::SUCCESS;
    }
}
