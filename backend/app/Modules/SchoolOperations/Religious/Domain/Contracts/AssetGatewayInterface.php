<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Asset Management (§7, §62). Peralatan masjid berpijak pada master aset.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface AssetGatewayInterface
{
    /** Data aset. */
    public function find(int $assetId): ?array;

    /** Pencarian aset. */
    public function search(array $filters): array;

    /** Catat pemakaian aset. */
    public function logUsage(array $payload): array;

    /** Ketersediaan modul aset. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
