<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Modules\Activity\Domain\Contracts\TransportationProviderInterface;

/**
 * Transportasi untuk kegiatan luar: permintaan dicatat sebagai rencana, bukan
 * dipalsukan menjadi pemesanan kendaraan. Modul Transportasi (bila tersedia)
 * tinggal diikatkan lewat kontrak ini.
 */
class NullTransportationProvider implements TransportationProviderInterface
{
    public function request(array $payload): array
    {
        return ['status' => 'SKIPPED', 'reference' => null, 'reason' => 'Modul Transportasi belum terpasang.'];
    }

    public function cancel(string $reference): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
