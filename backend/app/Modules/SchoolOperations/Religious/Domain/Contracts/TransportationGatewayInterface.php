<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Transportation (§47, §81). Modul ini hanya mengajukan permintaan angkutan.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface TransportationGatewayInterface
{
    /** Ajukan permintaan transportasi kegiatan. */
    public function requestTransport(array $payload): array;

    /** Status permintaan transportasi. */
    public function status(string $reference): ?array;

    /** Ketersediaan modul transportasi. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
