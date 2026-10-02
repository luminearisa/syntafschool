<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Kegiatan sekolah (lomba, field trip) — event tetap milik modul Activity (§55). */
interface EventGatewayInterface
{
    public function find(int $eventId): ?array;

    public function available(): bool;
}
