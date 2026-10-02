<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * FundraisingProviderInterface
 *
 * Penggalangan dana & sponsor (§54).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface FundraisingProviderInterface
{
    /** Membuat kampanye penggalangan dana. */
    public function createCampaign(array $payload): array;

    /** Mencatat komitmen sponsor. */
    public function recordCommitment(array $payload): array;

    /** Apakah modul penggalangan dana tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
