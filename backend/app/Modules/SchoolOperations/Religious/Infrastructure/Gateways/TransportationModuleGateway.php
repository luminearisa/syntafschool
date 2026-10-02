<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\TransportationGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Transportation (§47, §81). Modul Transportation tetap sumber
 * kebenarannya; modul ini hanya mengajukan permintaan angkutan.
 */
class TransportationModuleGateway implements TransportationGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        // Kontrak nyata yang diimplementasikan modul Transportation (Phase 2).
        return $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\TransportationProviderInterface')
            ?? $this->delegate('App\\Modules\\SchoolOperations\\Transportation\\Domain\\Contracts\\TransportationProviderInterface');
    }

    public function requestTransport(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'isAvailable') && ! $provider->isAvailable()) {
                    return ['requested' => false, 'reason' => 'Modul transportasi menyatakan tidak tersedia.'];
                }

                if (method_exists($provider, 'request')) {
                    $result = (array) $provider->request($payload);

                    return array_merge(['requested' => true], $result);
                }
            } catch (\Throwable $e) {
                return ['requested' => false, 'error' => $e->getMessage()];
            }
        }

        return ['requested' => false, 'reason' => $this->unavailableReason()];
    }

    public function status(string $reference): ?array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'status')) {
                    return (array) $provider->status($reference);
                }
            } catch (\Throwable) {
            }
        }

        $table = $this->firstTable(['transportation_requests', 'trip_assignments']);
        if ($table === null) {
            return null;
        }

        $columns = $this->columns($table);
        $column = $this->pick($columns, ['code', 'reference', 'request_code']);
        if ($column === null) {
            return null;
        }

        $row = DB::table($table)->where($column, $reference)->first();

        return $row ? (array) $row : null;
    }

    public function available(): bool
    {
        return $this->provider() !== null
            || $this->firstTable(['transportation_requests', 'transportation_services']) !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul Transportation tidak tersedia pada instalasi ini.';
    }
}
