<?php

namespace App\Modules\Clinic\Domain\Contracts;

/**
 * Konteks asrama untuk pasien boarding.
 *
 * Modul Clinic memakai ini untuk menampilkan asrama/kamar pada header pasien
 * dan mengetahui apakah siswa tinggal di asrama. Perubahan status asrama
 * (kembali ke kamar, izin rawat) **tidak** dilakukan otomatis: harus lewat
 * aturan bisnis modul Boarding.
 */
interface BoardingProviderInterface
{
    /**
     * @return array{dormitory: ?string, room: ?string, bed: ?string, is_boarder: bool}|null
     */
    public function placementForStudent(int $studentId): ?array;

    /** Apakah siswa berstatus penghuni asrama aktif. */
    public function isBoarder(int $studentId): bool;

    /**
     * Saran izin rawat di asrama (bukan perubahan status): apakah siswa
     * sebaiknya diistirahatkan di kamar, dengan/tanpa pengawasan.
     *
     * @return array{allowed: bool, requires_supervision: bool, note: string}
     */
    public function recommendRoomRest(int $studentId, string $reason): array;
}
