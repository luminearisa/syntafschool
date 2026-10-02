<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Aset & peminjaman (modul Asset).
 *
 * Meneruskan ke kontrak Asset modul Asset; modul Asset tetap sumber kebenarannya (§46).
 */
class AssetAssetProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Asset modul Asset; modul Asset tetap sumber kebenarannya (§46).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Asset\\Domain\\Contracts\\AssetProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function info(int $assetId): ?array
    {
        $value = $this->call('info', [$assetId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function available(array $filters): array
    {
        $value = $this->call('available', [$filters]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function lend(array $payload): array
    {
        $value = $this->call('lend', [$payload]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function collect(array $payload): array
    {
        $value = $this->call('collect', [$payload]);

        return $this->wrap($value);
    }
}
