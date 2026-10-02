<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Certificate Provider (§57).
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface CertificateGatewayInterface
{
    /** Terbitkan sertifikat. */
    public function issue(array $payload): array;

    /** Status penerbitan sertifikat. */
    public function status(string $reference): ?array;

    /** Ketersediaan penyedia sertifikat. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
