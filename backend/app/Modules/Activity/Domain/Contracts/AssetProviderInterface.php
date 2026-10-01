<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Aset — peralatan bernilai (sound system, kamera, alat band) yang dipinjamkan
 * dengan pengembalian dan tanggung jawab kerusakan.
 */
interface AssetProviderInterface
{
    public function info(int $assetId): ?array;

    public function lend(array $payload): array;

    public function collect(array $payload): array;
}
