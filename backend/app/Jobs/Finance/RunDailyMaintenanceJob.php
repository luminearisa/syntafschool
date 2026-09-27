<?php

namespace App\Jobs\Finance;

use App\Models\FinanceOperation;
use App\Services\Finance\FinanceOperationService;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Pemeliharaan harian Keuangan lewat antrian (§28).
 *
 * Job ini sengaja memanggil perintah `finance:daily-maintenance`, tidak menyusun
 * ulang langkahnya. Perintah itu adalah satu-satunya tempat yang memutuskan
 * urutan (lewat jatuh tempo → tangga pengingat → daftar ulang kedaluwarsa); kalau
 * job dan command masing-masing punya daftar panggilan sendiri, yang berjalan di
 * antrian lambat laun bukan lagi pekerjaan yang sama dengan yang dijadwalkan.
 *
 * Keluaran perintah disimpan apa adanya di `result`, jadi "apa yang terjadi tadi
 * malam" terbaca dari register tanpa membuka log server (§29).
 */
class RunDailyMaintenanceJob extends FinanceQueueJob
{
    public static function operationName(): string
    {
        return FinanceOperation::JOB_DAILY_MAINTENANCE;
    }

    protected function process(FinanceOperation $operation, FinanceOperationService $operations): array
    {
        $exitCode = Artisan::call('finance:daily-maintenance');
        $output = trim(Artisan::output());

        $operations->progress($operation, 1, 1);

        if ($exitCode !== 0) {
            throw new RuntimeException("finance:daily-maintenance berhenti dengan kode {$exitCode}: {$output}");
        }

        return ['exit_code' => $exitCode, 'output' => $output];
    }
}
