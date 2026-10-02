<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Domain\Contracts\AssetProviderInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Aset belum terpasang (§66).
 *
 * Mengembalikan "belum tersedia" alih-alih melempar pengecualian: HRD tetap
 * berjalan, dan niat penetapan/pengembalian perangkat menunggu di
 * `hr_integration_outbox` sampai modul Aset dipasang. Jadi tidak ada onboarding
 * yang gagal hanya karena sekolah belum memasang modul Sarpras.
 */
class UnavailableAssetProvider implements AssetProviderInterface
{
    public function requestAssignment(int $employeeId, array $payload): ?array
    {
        return null;
    }

    public function assetsHeldBy(int $employeeId): array
    {
        return [];
    }

    public function requestReturn(int $employeeId, array $payload): ?array
    {
        return null;
    }

    public function hasOutstandingAssets(int $employeeId): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('assets');
    }
}
