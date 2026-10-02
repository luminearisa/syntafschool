<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak akun pengguna (§67).
 *
 * `Person` → `Employee` → `User` adalah tiga hal berbeda: pegawai tidak otomatis
 * berarti akun login. HRD memakai kontrak ini untuk menautkan, menangguhkan, atau
 * melepaskan akun — dan untuk mengetahui siapa yang boleh diberi akses saat
 * onboarding selesai.
 */
interface UserAccountProviderInterface
{
    /**
     * Tautkan akun yang sudah ada ke pegawai (tanpa membuat kata sandi baru).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function link(int $employeeId, int $userId): bool;

    /** Lepaskan tautan akun tanpa menghapus akunnya (§59). */
    public function unlink(int $employeeId): bool;

    /**
     * Siapkan akun login untuk pegawai (surel kerja, peran awal), bila modul
     * pengguna mengizinkan pembuatan otomatis.
     *
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function provision(int $employeeId, array $roles = [], array $attributes = []): ?array;

    /**
     * Tangguhkan akun (mis. pegawai diskors atau keluar) tanpa menghapus sejarah.
     */
    public function suspend(int $userId, string $reason): bool;

    public function reactivate(int $userId): bool;

    /** @return array<string, mixed>|null */
    public function find(int $userId): ?array;

    /**
     * Daftar id pengguna yang memegang peran tertentu — dipakai penerutan penerima
     * notifikasi (mis. seluruh `HR_MANAGER`).
     *
     * @return list<int>
     */
    public function usersWithRole(string $role): array;
}
