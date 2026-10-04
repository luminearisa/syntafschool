<?php

namespace App\Modules\Procurement\Domain\Events;

/**
 * Peristiwa domain pengadaan (§50).
 *
 * Satu kelas generik dengan `name` memudahkan penambahan peristiwa baru tanpa
 * berkas baru; listener mendaftar berdasarkan `name` (mis.
 * `PurchaseRequestApproved`, `InvoiceMatched`, `AccountingHandoffFailed`).
 */
class ProcurementDomainEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $name,
        public readonly ?string $entityType = null,
        public readonly int|string|null $entityId = null,
        public readonly array $payload = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_name' => $this->name,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'payload' => $this->payload,
        ];
    }
}
