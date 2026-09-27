<?php

namespace App\Jobs\Finance;

use App\Models\FinanceOperation;
use App\Services\Finance\FinanceOperationService;
use App\Services\Finance\FinancePaymentService;

/**
 * Kedaluwarsakan pembayaran kanal yang lewat batas waktunya lewat antrian (§28).
 *
 * Job ini pasangan `finance:expire-payments`: sama-sama memanggil
 * `FinancePaymentService::expireStale()`, yang hanya menyentuh baris `pending`
 * ber-`expires_at`. Dijalankan ulang tetap aman — baris yang sudah kedaluwarsa
 * ditolak mesin status, bukan ditutup dua kali.
 */
class ExpirePaymentsJob extends FinanceQueueJob
{
    public static function operationName(): string
    {
        return FinanceOperation::JOB_PAYMENTS_EXPIRE;
    }

    protected function process(FinanceOperation $operation, FinanceOperationService $operations): array
    {
        $report = app(FinancePaymentService::class)->expireStale();

        $operations->progress($operation, (int) $report['scanned'], (int) $report['scanned']);

        return $report;
    }
}
