<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/**
 * Asrama (§1, §5). Sumber kebenaran: modul Boarding — dipakai untuk scope
 * policy (dormitory/kategori santri boarding) dan pengiriman ke asrama.
 */
interface BoardingProviderInterface
{
    public function isResident(int $studentId, ?string $onDate = null): bool;

    /** @return array{dormitory_id:int, dormitory_name:?string, building_id:?int, room_id:?int, bed_id:?int}|null */
    public function placementFor(int $studentId, ?string $onDate = null): ?array;

    public function available(): bool;
}
