<?php

namespace App\Modules\Hrd\Console;

use App\Modules\Hrd\Application\Services\DataQualityService;
use App\Modules\Hrd\Application\Services\HrdNotificationService;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Pemindaian kualitas data terjadwal (§56).
 *
 * Dijalankan mingguan supaya "skor kualitas data" memiliki tren — bukan hanya
 * angka sesaat ketika seseorang kebetulan menekan tombol. Temuan kritis juga
 * diberitahukan ke HR, karena temuan yang tidak pernah dibaca sama saja dengan
 * tidak ada.
 */
class HrdScanDataQualityCommand extends Command
{
    protected $signature = 'hrd:scan-data-quality {--actor= : ID pengguna yang dicatat sebagai pelaksana} {--notify : Kirim ringkasan temuan kritis ke HR}';

    protected $description = 'Pindai kualitas data kepegawaian (kelengkapan, duplikat, kedaluwarsa, hierarki).';

    public function handle(DataQualityService $quality, HrdNotificationService $notifications): int
    {
        $actorId = $this->option('actor');
        $actor = $actorId === null
            ? User::query()->orderBy('id')->first()
            : User::query()->find($actorId);

        if ($actor === null) {
            $this->error('Tidak ada pengguna yang dapat dicatat sebagai pelaksana pemindaian.');

            return self::FAILURE;
        }

        $result = $quality->scan($actor, scheduled: true);

        $this->info(sprintf('Skor kualitas data: %d/100. Kritis: %d, peringatan: %d, informasi: %d.',
            $result['score'],
            $result['totals']['critical'],
            $result['totals']['warning'],
            $result['totals']['info'],
        ));

        foreach ($result['totals']['rules'] as $rule => $count) {
            $this->line(sprintf('  %-45s %s', $rule, is_array($count) ? json_encode($count) : $count));
        }

        if ($this->option('notify') && $result['totals']['critical'] > 0) {
            $notifications->notifyHr(
                'data_quality.critical',
                'Temuan kualitas data kritis',
                $result['totals']['critical'].' temuan kritis menunggu tindak lanjut (skor '.$result['score'].'/100).',
                ['scan_id' => $result['scan_id'], 'score' => $result['score']],
            );
        }

        return self::SUCCESS;
    }
}
