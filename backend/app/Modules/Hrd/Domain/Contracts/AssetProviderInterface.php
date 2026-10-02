<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak aset (§66).
 *
 * Dua arah integrasi yang penting: saat onboarding, HRD meminta perangkat
 * disiapkan; saat offboarding, HRD menunggu pengembaliannya sebagai syarat
 * clearance. Bila modul Aset belum terpasang, permintaan itu tetap tercatat di
 * outbox — bukan hilang.
 */
interface AssetProviderInterface
{
    /**
     * Minta penetapan aset untuk pegawai baru.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function requestAssignment(int $employeeId, array $payload): ?array;

    /**
     * Aset yang masih dipegang pegawai — dipakai pemeriksa clearance offboarding.
     *
     * @return list<array<string, mixed>>
     */
    public function assetsHeldBy(int $employeeId): array;

    /**
     * Mulai proses pengembalian aset saat pegawai keluar.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function requestReturn(int $employeeId, array $payload): ?array;

    /** Apakah pegawai masih memegang aset yang menahan exit clearance? */
    public function hasOutstandingAssets(int $employeeId): bool;

    public function isAvailable(): bool;
}
