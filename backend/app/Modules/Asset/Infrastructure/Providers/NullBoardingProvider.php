<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\BoardingProviderInterface;

/**
 * Penyedia bawaan (kosong) untuk modul yang belum menyediakan kontraknya.
 *
 * Dinyatakan apa adanya: selama modulnya belum ada, modul Asset tetap berjalan
 * penuh untuk siklus hidupnya sendiri, dan integrasi yang belum tersedia TIDAK
 * berpura-pura berhasil.
 */
class NullBoardingProvider implements BoardingProviderInterface
{
    public function dormitory(int $dormitoryId): ?array
    {
        return null;
    }

    public function rooms(int $dormitoryId): array
    {
        return [];
    }

    public function assignAssetToRoom(int $roomId, int $assetId, array $context = []): array
    {
        return ['status' => 'PENDING'];
    }
}
