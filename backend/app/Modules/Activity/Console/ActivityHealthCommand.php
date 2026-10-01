<?php

namespace App\Modules\Activity\Console;

use App\Modules\Activity\Application\Services\ActivityAnalyticsService;
use App\Modules\Activity\Application\Services\ActivityIntegrationService;
use Illuminate\Console\Command;

/**
 * Ringkasan kesehatan modul: jumlah data, sesi tanpa kehadiran, dan kesiapan
 * integrasi. Dipakai sebelum/sesudah deploy.
 */
class ActivityHealthCommand extends Command
{
    protected $signature = 'activity:health';

    protected $description = 'Tampilkan kesehatan modul Student Activity';

    public function handle(ActivityAnalyticsService $analytics, ActivityIntegrationService $integrations): int
    {
        $this->info('Kesehatan Modul Student Activity');
        $this->table(['Metrik', 'Nilai'], collect($analytics->health())
            ->map(fn ($value, $key) => [$key, is_scalar($value) ? $value : json_encode($value)])
            ->values()
            ->all());

        $this->newLine();
        $this->info('Kesiapan integrasi');
        $this->table(['Integrasi', 'Status'], collect($integrations->health())
            ->map(fn ($value, $key) => [$key, is_bool($value) ? ($value ? 'tersedia' : 'belum terpasang') : json_encode($value)])
            ->values()
            ->all());

        return self::SUCCESS;
    }
}
