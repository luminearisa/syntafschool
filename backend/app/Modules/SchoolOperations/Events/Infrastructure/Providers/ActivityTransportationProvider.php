<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Transportasi (modul Transportation).
 *
 * Meneruskan ke kontrak Transportation; modul Transportasi tetap sumber kebenarannya (§49).
 */
class ActivityTransportationProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Transportation; modul Transportasi tetap sumber kebenarannya (§49).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\TransportationProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function request(array $payload): array
    {
        $value = $this->call('request', [$payload]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function cancel(string $reference): bool
    {
        $value = $this->call('cancel', [$reference]);

        return $value === null ? false : (bool) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isAvailable(): bool
    {
        $value = $this->call('isAvailable', []);

        return $value === null ? false : (bool) $value;
    }
}
