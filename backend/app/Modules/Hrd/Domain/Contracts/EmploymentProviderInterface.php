<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak kepegawaian & kontrak kerja (§2, §65).
 *
 * Payroll fase berikutnya membaca masa kerja, tipe pekerja, dan kontrak aktif dari
 * sini; Keuangan memakainya untuk menentukan pegawai mana yang masih berhak atas
 * tunjangan; Aset memakainya ketika mencatat pengembalian barang saat pegawai keluar.
 */
interface EmploymentProviderInterface
{
    /**
     * Masa kerja yang sedang berjalan untuk pegawai ini.
     *
     * @return array<string, mixed>|null
     */
    public function currentEmployment(int $employeeId): ?array;

    /**
     * Seluruh riwayat masa kerja (termasuk yang sudah berakhir).
     *
     * @return list<array<string, mixed>>
     */
    public function employmentHistory(int $employeeId): array;

    /**
     * Kontrak yang berlaku pada satu tanggal.
     *
     * @return array<string, mixed>|null
     */
    public function activeContract(int $employeeId, ?string $onDate = null): ?array;

    /**
     * Kontrak yang akan berakhir dalam sekian hari — dasar pengingat & laporan.
     *
     * @return list<array<string, mixed>>
     */
    public function expiringContracts(int $days, ?int $organizationId = null): array;

    /**
     * Penugasan pegawai pada satu tanggal (pertanyaan §19).
     *
     * @return list<array<string, mixed>>
     */
    public function assignmentsOn(int $employeeId, ?string $onDate = null): array;

    /**
     * Total masa kerja dalam bulan, dihitung dari seluruh periode (bukan dari
     * satu tanggal masuk).
     */
    public function totalServiceMonths(int $employeeId, ?string $asOf = null): int;

    /** Apakah pegawai masih dalam masa percobaan? */
    public function onProbation(int $employeeId, ?string $onDate = null): bool;
}
