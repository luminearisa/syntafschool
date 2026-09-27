<?php

namespace App\Jobs\Finance;

use App\Models\FinanceBilling;
use App\Models\FinanceInvoice;
use App\Models\FinanceOperation;
use App\Services\Finance\FinanceBillingService;
use App\Services\Finance\FinanceOperationService;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Terbitkan invoice massal dari daftar billing lewat antrian (§28).
 *
 * Pemisahan billing → invoice ini yang membuat layar "generate" tetap responsif
 * untuk seribu santri: tagihannya bisa dibuat sinkron, sedangkan penerbitan
 * invoice + jurnal pengakuan + notifikasi berjalan di belakang dengan kemajuan
 * per baris.
 *
 * Yang dikerjakan adalah `createInvoice()` per billing — jalur yang sama dengan
 * tombol terbitkan di layar. Karena `createInvoice()` mengembalikan invoice yang
 * sudah ada untuk billing tersebut, job yang diulang tidak pernah membuat
 * pengakuan pendapatan kedua (§16, §29).
 *
 * Sebagian baris gagal (mis. periodenya tertutup) membuat job berakhir Failed,
 * bukan Completed dengan catatan kaki: invoice yang sudah terbit tetap tersimpan
 * (transaksinya per baris), sisanya terkejar pada retry yang sama aman.
 */
class IssueInvoicesJob extends FinanceQueueJob
{
    public static function operationName(): string
    {
        return FinanceOperation::JOB_INVOICE_ISSUE;
    }

    protected function process(FinanceOperation $operation, FinanceOperationService $operations): array
    {
        $billingIds = array_values(array_unique(array_map(
            'intval',
            (array) ($this->payload['billing_ids'] ?? []),
        )));

        if ($billingIds === []) {
            throw new RuntimeException('Daftar billing kosong, tidak ada invoice yang bisa diterbitkan.');
        }

        $billing = app(FinanceBillingService::class);
        $actor = $this->actor();

        $billings = FinanceBilling::query()->whereIn('id', $billingIds)->orderBy('id')->get();

        if ($billings->isEmpty()) {
            throw new RuntimeException('Billing yang diminta tidak ditemukan.');
        }

        $operations->progress($operation, 0, $billings->count());

        $created = 0;
        $already = 0;
        $failed = 0;
        $reason = null;

        foreach ($billings as $index => $row) {
            $terbit = FinanceInvoice::query()->where('billing_id', $row->id)->exists();

            try {
                $billing->createInvoice($row, $actor);
                $terbit ? $already++ : $created++;
            } catch (ValidationException $e) {
                $failed++;
                $reason ??= collect($e->errors())->flatten()[0] ?? $e->getMessage();
            }

            $operations->progress($operation, (int) $index + 1, $billings->count());
        }

        if ($failed > 0) {
            throw new RuntimeException(sprintf(
                'Invoice gagal terbit %d dari %d baris (terbit: %d, sudah ada: %d): %s',
                $failed,
                $billings->count(),
                $created,
                $already,
                (string) $reason,
            ));
        }

        return ['created' => $created, 'already' => $already, 'rows' => $billings->count()];
    }
}
