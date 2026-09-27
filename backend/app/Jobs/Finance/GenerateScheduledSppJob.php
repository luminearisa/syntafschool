<?php

namespace App\Jobs\Finance;

use App\Models\FinanceOperation;
use App\Services\Finance\FinanceBillingService;
use App\Services\Finance\FinanceOperationService;
use Illuminate\Support\Carbon;

/**
 * Generate SPP terjadwal lewat antrian (§28 dari §7).
 *
 * Isi pekerjaannya persis satu baris: `FinanceBillingService::generateScheduled()`,
 * jalur yang sama dengan perintah `finance:generate-spp`. Yang ditambahkan job ini
 * hanyalah bahwa penerbitan massal tidak lagi memakan waktu request seseorang, dan
 * hasilnya (berapa billing/invoice terbit, berapa gagal, alasannya) tersimpan di
 * register supaya bisa dibaca setelahnya — bukan hanya lewat di terminal.
 */
class GenerateScheduledSppJob extends FinanceQueueJob
{
    public static function operationName(): string
    {
        return FinanceOperation::JOB_SPP_GENERATE;
    }

    protected function process(FinanceOperation $operation, FinanceOperationService $operations): array
    {
        $date = filled($this->payload['date'] ?? null) ? Carbon::parse((string) $this->payload['date']) : null;

        $report = app(FinanceBillingService::class)->generateScheduled($date, $this->actor());

        $operations->progress($operation, 1, 1);

        return $report;
    }
}
