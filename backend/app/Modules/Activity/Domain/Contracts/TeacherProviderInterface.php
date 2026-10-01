<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Pegawai/guru — dipakai untuk pembina, pelatih, dan rekomendasi.
 */
interface TeacherProviderInterface
{
    public function profile(int $employeeId): ?array;

    /** @return list<int> id pengguna yang melekat pada pegawai ini */
    public function userIdsForEmployee(int $employeeId): array;

    /** @return list<array> pegawai untuk pemilihan pembina di layar admin */
    public function search(string $term, int $limit = 20): array;
}
