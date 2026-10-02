<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\EventGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Event Management (§53). Siklus hidup event keagamaan memakai modul Event
 * bila terpasang, sehingga tidak ada dua daftar event yang saling bertabrakan.
 */
class EventModuleGateway implements EventGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\EventProviderInterface')
            ?? $this->delegate('App\\Modules\\Asset\\Domain\\Contracts\\EventProviderInterface');
    }

    public function create(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'isAvailable') && ! $provider->isAvailable()) {
                    return ['created' => false, 'reason' => 'Penyedia event menyatakan tidak tersedia.'];
                }

                return (array) $provider->create($payload);
            } catch (\Throwable $e) {
                return ['created' => false, 'error' => $e->getMessage()];
            }
        }

        return ['created' => false, 'reason' => $this->unavailableReason()];
    }

    public function publish(int|string $eventId): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'publish')) {
                    return (array) $provider->publish($eventId);
                }
                if (method_exists($provider, 'update')) {
                    return (array) $provider->update((string) $eventId, ['status' => 'PUBLISHED']);
                }
            } catch (\Throwable $e) {
                return ['published' => false, 'error' => $e->getMessage()];
            }
        }

        return ['published' => false, 'reason' => $this->unavailableReason()];
    }

    public function status(int|string $eventId): ?string
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'status')) {
                    return $provider->status($eventId);
                }
            } catch (\Throwable) {
            }
        }

        $table = $this->firstTable(['events', 'activity_events']);
        if ($table === null) {
            return null;
        }

        $row = DB::table($table)->where('id', $eventId)->first();

        return $row?->status ?? null;
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->firstTable(['events', 'activity_events']) !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul Event Management tidak tersedia pada instalasi ini.';
    }
}
