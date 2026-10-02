<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\AssetGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Asset Management (§7, §62). Peralatan masjid berpijak pada master aset.
 * Modul ini tidak membuat master aset kedua.
 */
class AssetModuleGateway implements AssetGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Asset\\Domain\\Contracts\\AssetProviderInterface')
            ?? $this->delegate('App\\Modules\\Hrd\\Domain\\Contracts\\AssetProviderInterface')
            ?? $this->delegate('App\\Modules\\Clinic\\Domain\\Contracts\\AssetProviderInterface');
    }

    public function find(int $assetId): ?array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'info')) {
                    $info = $provider->info($assetId);
                    if (is_array($info)) {
                        return $info;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $this->has('assets') ? $this->row('assets', $assetId) : null;
    }

    public function search(array $filters): array
    {
        if (! $this->has('assets')) {
            return [];
        }

        $query = DB::table('assets');
        $columns = $this->columns('assets');
        $nameColumn = $this->pick($columns, ['name', 'asset_name']);

        if ($nameColumn && ! empty($filters['term'])) {
            $query->where($nameColumn, 'like', '%'.$filters['term'].'%');
        }
        if (! empty($filters['campus_id']) && in_array('campus_id', $columns, true)) {
            $query->where('campus_id', $filters['campus_id']);
        }

        return array_map(fn ($row): array => (array) $row,
            $query->limit((int) ($filters['limit'] ?? 100))->get()->all());
    }

    public function logUsage(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'lend')) {
                    return (array) $provider->lend($payload);
                }
            } catch (\Throwable $e) {
                return ['logged' => false, 'error' => $e->getMessage()];
            }
        }

        return ['logged' => false, 'reason' => $this->unavailableReason()];
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->has('assets');
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul Asset Management tidak tersedia pada instalasi ini.';
    }
}
