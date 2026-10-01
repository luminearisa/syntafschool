<?php

namespace App\Modules\Asset\Console;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetReplacementService;
use Illuminate\Console\Command;

class AssetEvaluateReplacementsCommand extends Command
{
    protected $signature = 'asset:replacement-review {--limit=500}';

    protected $description = 'Nilai aset dan usulkan penggantian (rekomendasi operasional, bukan pembelian).';

    public function handle(AssetReplacementService $service): int
    {
        $actor = User::query()->orderBy('id')->first();

        if ($actor === null) {
            $this->error('Tidak ada pengguna untuk dicatat sebagai pelaksana.');

            return self::FAILURE;
        }

        $flagged = $service->evaluateAll($actor, (int) $this->option('limit'));

        $this->info('Aset yang diusulkan ditinjau ulang: '.count($flagged).'.');

        foreach ($flagged as $row) {
            $this->line('  • '.$row['asset_code'].' — '.implode('; ', $row['reasons']));
        }

        return self::SUCCESS;
    }
}
