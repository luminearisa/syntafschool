<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\FacilityGatewayInterface;

class ConfigurableFacilityGateway implements FacilityGatewayInterface
{
    use GatewaySupport;

    public function find(int $facilityId): ?array
    {
        $provider = $this->delegate('App\Modules\Activity\Domain\Contracts\FacilityProviderInterface')
            ?? $this->delegate('App\Modules\Asset\Domain\Contracts\FacilityProviderInterface');
        if ($provider) {
            try {
                foreach (['room', 'find'] as $method) {
                    if (method_exists($provider, $method)) {
                        $result = $provider->{$method}($facilityId);
                        if (is_array($result) && $result !== []) {
                            return $result + ['source' => 'module'];
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        foreach (['facilities', 'rooms', 'asset_locations'] as $table) {
            if ($this->has($table)) {
                $row = $this->row($table, $facilityId);
                if ($row) {
                    return $row + ['source' => $table];
                }
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->delegate('App\Modules\Activity\Domain\Contracts\FacilityProviderInterface') !== null
            || $this->has('facilities');
    }
}
