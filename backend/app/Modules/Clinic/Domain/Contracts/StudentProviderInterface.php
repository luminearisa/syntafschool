<?php

namespace App\Modules\Clinic\Domain\Contracts;

use Illuminate\Support\Collection;

/**
 * Sumber data siswa untuk Modul Clinic.
 *
 * Modul Clinic **tidak** menyimpan nama, kelas, atau program siswa. Semuanya
 * diambil dari master ERP lewat kontrak ini sehingga tidak ada dua sumber
 * kebenaran yang bisa saling menyimpang.
 */
interface StudentProviderInterface
{
    /**
     * Ringkasan identitas siswa untuk header pasien & dokumen klinis.
     *
     * @return array{
     *     id: int, nis: ?string, nisn: ?string, name: string, nick_name: ?string,
     *     gender: ?string, birth_date: ?string, age_years: ?int, blood_type: ?string,
     *     class_id: ?int, class_name: ?string, program: ?string, is_active: bool
     * }|null
     */
    public function snapshot(int $studentId): ?array;

    /**
     * Pencarian siswa untuk layar pendaftaran klinik (nama/NIS/NISN).
     *
     * @return list<array{id: int, nis: ?string, name: string, class_name: ?string}>
     */
    public function search(string $term, int $limit = 20): array;

    /** @param list<int> $studentIds */
    public function snapshots(array $studentIds): Collection;

    /**
     * ID siswa pada satu kelas (dipakai skrining massal & enrollment layanan).
     *
     * @return list<int>
     */
    public function studentIdsInClass(int $schoolClassId): array;

    /** Siswa aktif pada tahun ajaran aktif, untuk program skrining. */
    public function activeStudentIds(?int $educationLevelId = null): array;
}
