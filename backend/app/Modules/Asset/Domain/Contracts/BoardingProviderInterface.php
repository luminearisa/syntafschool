<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Asrama — aset ditempatkan menurut kamar/gedung asrama, dan booking ruang
 * asrama mengikuti aturan yang sama dengan fasilitas lain.
 */
interface BoardingProviderInterface
{
    public function dormitory(int $dormitoryId): ?array;

    /** Kamar (lokasi) yang terdaftar pada asrama tersebut. */
    public function rooms(int $dormitoryId): array;

    public function assignAssetToRoom(int $roomId, int $assetId, array $context = []): array;
}
