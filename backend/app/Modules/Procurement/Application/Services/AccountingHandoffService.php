<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Contracts\AccountingProviderInterface;
use App\Modules\Procurement\Domain\Enums\AccountingHandoffStatus;
use App\Modules\Procurement\Domain\Enums\InvoiceStatus;
use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\ProcurementIntegration;
use App\Modules\Procurement\Models\VendorInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Penyerahan invoice ke modul Accounting (§32, §33, §63).
 *
 * Idempotensi: kunci `PROC-INV-{invoice_id}` menjamin percobaan ulang **tidak**
 * pernah menghasilkan payable ganda. Bila Accounting belum terpasang, catatan
 * tetap PENDING dan dapat dikirim ulang tanpa kehilangan niat.
 */
class AccountingHandoffService
{
    public function __construct(
        private readonly AccountingProviderInterface $accounting,
        private readonly IntegrationService $integrations,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
    ) {
    }

    public function idempotencyKey(VendorInvoice $invoice): string
    {
        return 'PROC-INV-'.$invoice->id;
    }

    /** Kirim invoice ke Accounting. */
    public function handoff(VendorInvoice $invoice, bool $force = false): ProcurementIntegration
    {
        $status = $invoice->status instanceof InvoiceStatus
            ? $invoice->status
            : InvoiceStatus::tryFrom((string) $invoice->status);

        if ($status !== InvoiceStatus::APPROVED && ! $force) {
            throw ProcurementException::because('Hanya invoice yang disetujui dapat diserahkan ke Accounting.', 'INVOICE_NOT_APPROVED');
        }

        return DB::transaction(function () use ($invoice): ProcurementIntegration {
            $key = $this->idempotencyKey($invoice);
            $payload = $this->payload($invoice);

            $integration = $this->integrations->record($invoice, 'ACCOUNTING', $invoice->number, $key, $payload);

            // Sudah pernah diterima/terposting → jangan kirim ulang (idempotensi).
            if (in_array($integration->status, [AccountingHandoffStatus::ACCEPTED, AccountingHandoffStatus::POSTED], true)) {
                return $integration;
            }

            $this->integrations->markSent($integration);
            $result = $this->accounting->createPayable($payload);
            $integration = $this->integrations->markResult($integration, $result);

            if ($result->ok) {
                $invoice->forceFill(['status' => InvoiceStatus::SENT_TO_ACCOUNTING->value])->save();
                $this->events->dispatch('AccountingHandoffCompleted', $invoice, ['external_id' => $result->externalId]);
            } else {
                $this->events->dispatch('AccountingHandoffFailed', $invoice, ['error' => $result->message, 'code' => $result->errorCode]);
            }

            $this->audit->record(ProcurementAuditAction::HANDOFF, $invoice, null, $integration->toArray(), $result->message);

            return $integration;
        });
    }

    /** Coba ulang pengiriman (memakai kunci idempotensi yang sama). */
    public function retry(VendorInvoice $invoice): ProcurementIntegration
    {
        $integration = $this->integrations->findByIdempotencyKey($this->idempotencyKey($invoice));

        if ($integration !== null) {
            $this->integrations->markRetry($integration);
        }

        return $this->handoff($invoice, true);
    }

    /** Sinkronkan status dari Accounting (POSTED/REJECTED) — tidak menyimpan ledger sendiri. */
    public function syncStatus(VendorInvoice $invoice): array
    {
        $integration = $this->integrations->findByIdempotencyKey($this->idempotencyKey($invoice));
        $reference = $integration?->external_id ?? $integration?->reference;

        $remote = $reference !== null ? $this->accounting->getInvoiceStatus($reference) : null;

        if ($remote !== null && ($remote['status'] ?? null) === 'POSTED' && $invoice->status !== InvoiceStatus::POSTED) {
            $invoice->forceFill(['status' => InvoiceStatus::POSTED->value])->save();

            if ($integration !== null) {
                $integration->forceFill(['status' => AccountingHandoffStatus::POSTED->value])->save();
            }
        }

        return [
            'invoice_number' => $invoice->number,
            'handoff_status' => $integration?->status instanceof \BackedEnum ? $integration->status->value : $integration?->status,
            'remote' => $remote,
        ];
    }

    /** @return array<string, mixed> */
    public function payload(VendorInvoice $invoice): array
    {
        $invoice->loadMissing('items', 'purchaseOrder');

        return [
            'idempotency_key' => $this->idempotencyKey($invoice),
            'vendor_id' => $invoice->vendor_id,
            'vendor_name' => $invoice->vendor?->legal_name,
            'invoice_number' => $invoice->vendor_invoice_number,
            'internal_number' => $invoice->number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            'subtotal' => (float) $invoice->subtotal,
            'discount' => (float) $invoice->discount_amount,
            'tax' => (float) $invoice->tax_amount,
            'total' => (float) $invoice->total,
            'po_reference' => $invoice->purchaseOrder?->number,
            'receiving_reference' => $invoice->purchaseOrder?->goodsReceipts()->pluck('number')->all(),
            'cost_center' => $invoice->purchaseOrder?->cost_center_id,
            'department' => $invoice->purchaseOrder?->department_id,
            'project' => $invoice->purchaseOrder?->project_reference,
            'account_mapping' => null,
            'attachments' => array_filter([$invoice->attachment_path]),
            'lines' => $invoice->items->map(fn ($item): array => [
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->all(),
        ];
    }
}
