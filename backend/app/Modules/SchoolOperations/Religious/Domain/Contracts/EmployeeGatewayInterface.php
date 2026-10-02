<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Pegawai/guru (HRD). Imam, muadzin, khatib, dan pengajar agama berasal dari sini (§16, §36).
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface EmployeeGatewayInterface
{
    /** Ringkasan pegawai: nama, NIP, jabatan, unit, kampus. */
    public function snapshot(int $employeeId): ?array;

    /** Ringkasan banyak pegawai. */
    public function snapshots(array $employeeIds): array;

    /** Pegawai dari akun pengguna (untuk portal mandiri). */
    public function byUser(int $userId): ?array;

    /** Pencarian pegawai untuk pemilihan personel. */
    public function search(string $term, int $limit = 20): array;

    /** Ketersediaan sumber pegawai. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
