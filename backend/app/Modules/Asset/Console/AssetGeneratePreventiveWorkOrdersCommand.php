<?php

namespace App\Modules\Asset\Console;

use App\Models\User;
use App\Modules\Asset\Application\Services\PreventiveMaintenanceService;
use Illuminate\Console\Command;

class AssetGeneratePreventiveWorkOrdersCommand extends Command
{
    protected $signature = 'asset:preventive {--actor= : Id pengguna yang dicatat sebagai pelaksana}';

    protected $description = 'Buat work order dari jadwal pemeliharaan preventif yang jatuh tempo (idempoten).';

    public function handle(PreventiveMaintenanceService $service): int
    {
        $actor = $this->option('actor') !== null
            ? User::query()->find((int) $this->option('actor'))
            : User::query()->orderBy('id')->first();

        if ($actor === null) {
            $this->error('Tidak ada pengguna yang dapat dicatat sebagai pelaksana. Jalankan seeder pengguna terlebih dahulu.');

            return self::FAILURE;
        }

        $result = $service->generateDue($actor);

        $this->info(sprintf('Work order preventif dibuat: %d. Dilewati: %d.', count($result['generated']), count($result['skipped'])));

        foreach ($result['generated'] as $number) {
            $this->line('  + '.$number);
        }

        return self::SUCCESS;
    }
}
