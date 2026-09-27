<?php

namespace App\Jobs\Finance;

use App\Models\FinanceOperation;
use App\Services\Finance\FinanceCollectionService;
use App\Services\Finance\FinanceOperationService;
use Illuminate\Support\Carbon;

/**
 * Kirim tangga pengingat jatuh tempo lewat antrian (§28).
 *
 * Pengingat membaca seluruh tagihan terbuka lalu memfilter yang menyentuh anak
 * tangganya — pada sekolah besar itu sebuah tabel penuh, bukan satu baris.
 * Di-queue supaya pengiriman pesan tidak menunda transaksi yang sedang dikerjakan
 * orang, dan supaya satu permintaan HTTP tidak mati di tengah jalan meninggalkan
 * separuh wali terkabari.
 *
 * Deduplikasi notifikasi (per dokumen + anak tangga) sudah ada di engine
 * `database` bawaan, jadi job ini boleh dijalankan ulang di hari yang sama tanpa
 * mengirim pesan kedua (§7, §29).
 */
class SendInvoiceRemindersJob extends FinanceQueueJob
{
    public static function operationName(): string
    {
        return FinanceOperation::JOB_REMINDERS_SEND;
    }

    protected function process(FinanceOperation $operation, FinanceOperationService $operations): array
    {
        $date = filled($this->payload['date'] ?? null) ? Carbon::parse((string) $this->payload['date']) : null;

        $report = app(FinanceCollectionService::class)->sendReminders($date);

        $operations->progress($operation, (int) $report['checked'], (int) $report['checked']);

        return $report;
    }
}
