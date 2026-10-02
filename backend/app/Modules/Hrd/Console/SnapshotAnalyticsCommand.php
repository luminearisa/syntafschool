<?php

namespace App\Modules\Hrd\Console;

use App\Modules\Hrd\Core\Services\WorkforceAnalyticsService;
use App\Modules\Hrd\Core\Support\HrdAutomationRunner;
use Illuminate\Console\Command;

/** Snapshot analitik harian untuk layar tren. */
class SnapshotAnalyticsCommand extends Command
{
    protected $signature = 'hrd:snapshot-analytics {--organization= : Batasi pada satu unit organisasi}';

    protected $description = 'Menulis snapshot analitik tenaga kerja harian.';

    public function handle(WorkforceAnalyticsService $analytics, HrdAutomationRunner $runner): int
    {
        $organizationId = $this->option('organization') !== null ? (int) $this->option('organization') : null;

        $run = $runner->run('ANALYTICS_SNAPSHOT', 'analytics-snapshot:'.now()->toDateString().':'.($organizationId ?? 'ALL'), function () use ($analytics, $organizationId) {
            $snapshots = $analytics->snapshot($organizationId);

            return ['processed' => count($snapshots), 'scopes' => array_map(fn ($snapshot) => $snapshot->scope, $snapshots)];
        });

        $this->info('Snapshot: '.json_encode($run->summary));

        return self::SUCCESS;
    }
}
