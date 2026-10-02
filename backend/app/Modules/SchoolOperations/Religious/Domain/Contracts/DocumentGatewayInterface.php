<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Document Management (§30). Khutbah, sertifikat, dan berkas pendukung.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface DocumentGatewayInterface
{
    /** Simpan dokumen. */
    public function store(array $payload): array;

    /** Ambil metadata dokumen. */
    public function find(int $documentId): ?array;

    /** Riwayat versi dokumen. */
    public function versions(int $documentId): array;

    /** Ketersediaan modul dokumen. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
