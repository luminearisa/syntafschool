<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/**
 * Data santri (§1). Sumber kebenaran: modul Student/Academic — laundry hanya
 * membaca snapshot dan tidak pernah menulis ke master siswa.
 */
interface StudentProviderInterface
{
    /** @return array{id:int, full_name:?string, nis:?string, nisn:?string, school_id:?int, education_level_id:?int, level_name:?string, class_id:?int, class_name:?string, photo_path:?string, active:bool}|null */
    public function snapshot(int $studentId): ?array;

    /** @param list<int> $studentIds  @return array<int, array> */
    public function snapshots(array $studentIds): array;

    /** @return list<array> */
    public function search(string $term, int $limit = 20): array;

    /** Rule 2: hanya santri aktif/eligible yang boleh order. */
    public function isActive(int $studentId): bool;

    public function available(): bool;
}
