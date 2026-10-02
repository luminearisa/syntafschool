<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Vendor (kendaraan sewa, bengkel) — sumber: Vendor Management (§62). */
interface VendorGatewayInterface
{
    public function find(int $vendorId): ?array;

    public function available(): bool;
}
