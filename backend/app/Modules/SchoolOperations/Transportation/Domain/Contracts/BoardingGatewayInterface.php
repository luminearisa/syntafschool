<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Asrama: status mondok, izin pulang, dan kewajiban kegiatan (§56). */
interface BoardingGatewayInterface
{
    public function isResident(int $studentId, ?string $onDate = null): bool;

    public function obligationsFor(int $studentId, string $date): array;

    public function homeLeaveFor(int $studentId, string $date): ?array;

    public function available(): bool;
}
