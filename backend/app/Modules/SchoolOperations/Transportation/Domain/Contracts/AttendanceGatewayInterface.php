<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Presensi: transportasi menitipkan bukti naik/turun, bukan menjadi master presensi (§36). */
interface AttendanceGatewayInterface
{
    public function mirror(array $payload): ?string;

    public function revoke(string $reference): bool;

    public function available(): bool;
}
