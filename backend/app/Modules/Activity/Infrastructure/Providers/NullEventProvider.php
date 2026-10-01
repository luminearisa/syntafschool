<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Modules\Activity\Domain\Contracts\EventProviderInterface;

/**
 * Event Management belum terpasang sebagai modul tersendiri di ERP ini.
 *
 * Penerapan ini **tidak berpura-pura** berhasil: ia menyatakan diri tidak
 * tersedia, sehingga permintaan event/pentas dicatat sebagai rencana di modul
 * Activity (dan tampil di log integrasi) sampai modul Event tersedia. Begitu
 * modul Event ada, cukup ganti binding-nya — tanpa mengubah layanan.
 */
class NullEventProvider implements EventProviderInterface
{
    public function create(array $payload): array
    {
        return ['status' => 'SKIPPED', 'reference' => null, 'reason' => 'Modul Event belum terpasang.'];
    }

    public function update(string $reference, array $payload): array
    {
        return ['status' => 'SKIPPED', 'reference' => $reference, 'reason' => 'Modul Event belum terpasang.'];
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
