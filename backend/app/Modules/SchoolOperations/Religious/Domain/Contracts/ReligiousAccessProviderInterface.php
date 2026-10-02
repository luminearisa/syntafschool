<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Otorisasi & cakupan (§94). Baris data dibatasi kampus/organisasi/masjid.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface ReligiousAccessProviderInterface
{
    /** Cek izin. */
    public function can(string $ability, ?int $userId = null): bool;

    /** Cakupan efektif pengguna: kampus, organisasi, masjid, asrama. */
    public function scopeFor(?int $userId = null): array;

    /** Daftar kampus yang boleh diakses. */
    public function campusIds(?int $userId = null): array;

    /** Daftar masjid yang boleh diakses. */
    public function mosqueIds(?int $userId = null): array;

    /** Ketersediaan sumber otorisasi. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
