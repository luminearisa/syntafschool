<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak data pegawai untuk modul lain (§2, §65).
 *
 * Inilah satu-satunya cara modul lain membaca pegawai. Tidak ada modul yang boleh
 * membuat tabel pegawai sendiri, dan tidak ada yang boleh membaca `hr_employees`
 * langsung — karena begitu itu terjadi, definisi "pegawai aktif" akan berbeda di
 * setiap modul, dan laporan lintas modul berhenti dapat dipercaya.
 *
 * Semua metode mengembalikan array sederhana (bukan model Eloquent) supaya modul
 * pemanggil tidak ikut terikat pada skema tabel HRD.
 */
interface EmployeeProviderInterface
{
    /**
     * Pegawai berdasarkan nomor pegawai (format bebas, dicocokkan persis).
     *
     * @return array<string, mixed>|null
     */
    public function findByNumber(string $employeeNumber): ?array;

    /** @return array<string, mixed>|null */
    public function findById(int $employeeId): ?array;

    /**
     * Pegawai di balik sebuah akun pengguna — dipakai modul yang menerima
     * `user_id` (presensi, LMS, aset) tetapi membutuhkan identitas pegawai.
     *
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array;

    /**
     * Daftar pegawai aktif dalam satu unit, termasuk sub-unit bila diminta.
     *
     * @return list<array<string, mixed>>
     */
    public function activeInOrganization(int $organizationId, bool $includeDescendants = false): array;

    /**
     * Pegawai yang menjadi bawahan langsung (langsung maupun fungsional).
     *
     * @return list<array<string, mixed>>
     */
    public function directReportsOf(int $employeeId): array;

    /** Apakah pegawai masih aktif pada tanggal tertentu? */
    public function isActive(int $employeeId, ?string $onDate = null): bool;

    /**
     * Ringkasan singkat untuk ditampilkan di modul lain: nomor, nama, jabatan,
     * unit. Sengaja bukan profil lengkap — data pribadi tidak ikut tersebar.
     *
     * @return array<string, mixed>|null
     */
    public function summary(int $employeeId): ?array;
}
