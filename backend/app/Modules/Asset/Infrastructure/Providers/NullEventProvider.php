<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\EventProviderInterface;

/**
 * Penyedia bawaan (kosong) untuk modul yang belum menyediakan kontraknya.
 *
 * Dinyatakan apa adanya: selama modulnya belum ada, modul Asset tetap berjalan
 * penuh untuk siklus hidupnya sendiri, dan integrasi yang belum tersedia TIDAK
 * berpura-pura berhasil.
 */
class NullEventProvider implements EventProviderInterface
{
    public function event(int $eventId): ?array
    {
        return null;
    }

    public function commitments(int $eventId): array
    {
        return [];
    }
}
