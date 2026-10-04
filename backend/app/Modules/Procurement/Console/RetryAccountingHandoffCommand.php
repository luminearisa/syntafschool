<?php

namespace App\Modules\Procurement\Console;

use App\Modules\Procurement\Application\Services\AccountingHandoffService;
use App\Modules\Procurement\Domain\Enums\InvoiceStatus;
use App\Modules\Procurement\Models\VendorInvoice;
use Illuminate\Console\Command;

/**
 * Kirim ulang handoff invoice yang belum diterima Accounting (§33, §63).
 *
 * Aman diulang: idempotency key `PROC-INV-{id}` menjamin tidak ada payable ganda.
 */
class RetryAccountingHandoffCommand extends Command
{
    protected $signature = 'procurement:retry-handoff {--limit=50}';

    protected $description = 'Coba ulang penyerahan invoice ke Accounting yang masih PENDING/FAILED.';

    public function handle(AccountingHandoffService $handoff): int
    {
        $limit = (int) $this->option('limit');

        $invoices = VendorInvoice::query()
            ->whereIn('status', [InvoiceStatus::APPROVED->value, InvoiceStatus::SENT_TO_ACCOUNTING->value])
            ->limit($limit)
            ->get();

        $retried = 0;

        foreach ($invoices as $invoice) {
            $integration = $handoff->retry($invoice);

            $status = $integration->status instanceof \BackedEnum ? $integration->status->value : $integration->status;

            if ($status !== 'ACCEPTED') {
                $retried++;
            }
        }

        $this->info("Percobaan ulang handoff: {$retried} dari {$invoices->count()} invoice.");

        return self::SUCCESS;
    }
}
