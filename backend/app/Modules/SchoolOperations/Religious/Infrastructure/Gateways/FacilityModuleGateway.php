<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\FacilityGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fasilitas (§59, §60). Pemesanan ruang masjid memakai modul fasilitas
 * institusi; modul ini hanya mengajukan permintaan.
 */
class FacilityModuleGateway implements FacilityGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\FacilityProviderInterface')
            ?? $this->delegate('App\\Modules\\Asset\\Domain\\Contracts\\FacilityProviderInterface');
    }

    protected function table(): ?string
    {
        return $this->firstTable(['facilities', 'facility_rooms']);
    }

    public function find(int $facilityId): ?array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'room')) {
                    $room = $provider->room($facilityId);
                    if (is_array($room)) {
                        return $room;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $this->table() ? $this->row((string) $this->table(), $facilityId) : null;
    }

    public function requestBooking(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'book')) {
                    return (array) $provider->book($payload);
                }
            } catch (\Throwable $e) {
                return ['booked' => false, 'error' => $e->getMessage()];
            }
        }

        return ['booked' => false, 'reason' => $this->unavailableReason()];
    }

    public function availability(array $filters): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'rooms')) {
                    return (array) $provider->rooms($filters);
                }
            } catch (\Throwable) {
            }
        }

        $table = $this->table();

        return $table ? array_map(fn ($row): array => (array) $row,
            DB::table($table)->limit(200)->get()->all()) : [];
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->table() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul fasilitas tidak tersedia pada instalasi ini.';
    }
}
