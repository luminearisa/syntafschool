<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak sertifikasi pegawai (§29, §65).
 *
 * Dipakai untuk kepatuhan: "apakah semua guru bersertifikat pendidik?", "siapa
 * yang sertifikat keselamatannya berakhir bulan ini?". Keuangan memakainya untuk
 * tunjangan sertifikasi, Supervisi untuk kelayakan penilaian.
 */
interface EmployeeCertificationProviderInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function certificationsFor(int $employeeId): array;

    /**
     * Sertifikat yang masih berlaku pada satu tanggal.
     *
     * @return list<array<string, mixed>>
     */
    public function validFor(int $employeeId, ?string $onDate = null): array;

    /**
     * Sertifikat yang akan berakhir dalam sekian hari.
     *
     * @return list<array<string, mixed>>
     */
    public function expiring(int $days, ?int $organizationId = null): array;

    /** Apakah pegawai memiliki sertifikat ini (kode master) yang masih berlaku? */
    public function holds(string $certificationCode, int $employeeId, ?string $onDate = null): bool;

    /**
     * Pegawai yang wajib memiliki sertifikasi ini tetapi belum memilikinya —
     * dasar laporan kepatuhan.
     *
     * @return list<array<string, mixed>>
     */
    public function missingMandatory(string $certificationCode): array;
}
