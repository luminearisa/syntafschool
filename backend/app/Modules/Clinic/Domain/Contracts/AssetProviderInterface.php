<?php

namespace App\Modules\Clinic\Domain\Contracts;

/**
 * Alat kesehatan (termometer, tensimeter, oksimeter) — memakai master aset
 * ERP bila tersedia, bukan tabel alat terpisah.
 */
interface AssetProviderInterface
{
    /**
     * @return list<array{id: int, sku: ?string, name: string, serial_number: ?string, condition: ?string, location: ?string}>
     */
    public function equipmentForClinic(?int $clinicId = null, int $limit = 50): array;

    /** Apakah alat ini ada dan masih layak dipakai. */
    public function isValid(string $assetRef): bool;
}
