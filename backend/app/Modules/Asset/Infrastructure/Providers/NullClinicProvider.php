<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\ClinicProviderInterface;

/**
 * Penyedia bawaan (kosong) untuk modul yang belum menyediakan kontraknya.
 *
 * Dinyatakan apa adanya: selama modulnya belum ada, modul Asset tetap berjalan
 * penuh untuk siklus hidupnya sendiri, dan integrasi yang belum tersedia TIDAK
 * berpura-pura berhasil.
 */
class NullClinicProvider implements ClinicProviderInterface
{
    public function equipment(int $assetId): ?array
    {
        return null;
    }

    public function equipmentRequiringInspection(int $daysAhead): array
    {
        return [];
    }
}
