<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCustodian;
use App\Modules\Asset\Models\Location;
use Illuminate\Database\Eloquent\Builder;

/**
 * Siapa boleh melihat apa.
 *
 * Modul ini menyimpan dua jenis informasi dengan aturan berbeda: keadaan
 * operasional (boleh dilihat banyak peran) dan nilai keuangan (hanya pemegang
 * izin keuangan). Pemisahan itu dilakukan di sini supaya tidak bergantung pada
 * masing-masing controller yang dapat lupa.
 */
class AssetAccessService
{
    public function isAssetAdmin(User $user): bool
    {
        return $user->can('asset.admin') || $user->hasAnyRole(['ASSET_ADMIN', 'super-admin', 'admin-sekolah']);
    }

    public function isMaintenanceManager(User $user): bool
    {
        return $user->can('asset.maintenance.manage') || $user->hasAnyRole(['MAINTENANCE_MANAGER', 'ASSET_ADMIN']);
    }

    public function isTechnician(User $user): bool
    {
        return $user->can('asset.maintenance.execute') || $user->hasAnyRole(['TECHNICIAN']);
    }

    public function isFacilityManager(User $user): bool
    {
        return $user->can('facility.manage') || $user->hasAnyRole(['FACILITY_MANAGER', 'SARPRAS_MANAGER', 'ASSET_ADMIN']);
    }

    public function canViewFinancials(User $user): bool
    {
        return $user->can('asset.financial.view') || $user->can('finance.asset.view')
            || $user->hasAnyRole(['FINANCE', 'AUDITOR', 'ASSET_ADMIN', 'super-admin']);
    }

    /** Unit kerja pengguna (untuk pembatasan baris "aset unit saya"). */
    public function unitIdFor(User $user): ?int
    {
        return $user->unit_id !== null ? (int) $user->unit_id : null;
    }

    /** Id pemegang (custodian) yang melekat pada pengguna, bila ada. */
    public function custodianIdsFor(User $user): array
    {
        return AssetCustodian::query()
            ->where('user_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Batasi kueri aset menurut kewenangan pemanggil.
     *
     * Pemegang izin luas melihat semua; selain itu hanya aset pada unitnya,
     * yang dipegangnya, atau yang berada di lokasi yang dikelolanya.
     */
    public function scopeAssets(Builder $query, User $user): Builder
    {
        if ($user->can('asset.view.all') || $this->isAssetAdmin($user)) {
            return $query;
        }

        $unitId = $this->unitIdFor($user);
        $custodianIds = $this->custodianIdsFor($user);

        return $query->where(function (Builder $inner) use ($unitId, $custodianIds, $user): void {
            if ($unitId !== null) {
                $inner->orWhere('unit_id', $unitId);
            }

            if ($custodianIds !== []) {
                $inner->orWhereIn('custodian_id', [$user->id]);
            }

            $inner->orWhereHas('location', function (Builder $location) use ($user): void {
                $location->where('manager_user_id', $user->id);
            });

            if ($unitId === null && $custodianIds === [] && ! $user->can('asset.view')) {
                // Tidak ada dasar kewenangan: jangan berikan apa pun.
                $inner->whereRaw('1 = 0');
            }
        });
    }

    /** Lembar data aman untuk QR publik — TANPA angka keuangan. */
    public function publicPayload(Asset $asset): array
    {
        $fields = (array) config('asset.qr.public_fields', ['asset_code', 'name', 'status', 'condition']);

        $payload = [];

        foreach ($fields as $field) {
            $payload[$field] = match ($field) {
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'status' => $asset->status?->value ?? (string) $asset->status,
                'condition' => $asset->condition?->value ?? (string) $asset->condition,
                'location_name' => $asset->location?->name,
                default => null,
            };
        }

        // Penegasan terakhir: apa pun konfigurasinya, kolom bernuansa uang tidak
        // pernah keluar dari pintu ini.
        foreach (['acquisition_cost', 'current_book_value', 'residual_value', 'fair_value', 'accumulated_depreciation', 'serial_number'] as $forbidden) {
            unset($payload[$forbidden]);
        }

        return $payload;
    }

    /** Boleh membuka satu aset tertentu? */
    public function canViewAsset(User $user, Asset $asset): bool
    {
        if ($user->can('asset.view.all') || $this->isAssetAdmin($user)) {
            return true;
        }

        $unitId = $this->unitIdFor($user);

        if ($unitId !== null && (int) $asset->unit_id === $unitId) {
            return true;
        }

        if ((int) $asset->custodian_id === (int) $user->id) {
            return true;
        }

        // Pengelola lokasi tempat aset berada.
        return $asset->location !== null && (int) $asset->location->manager_user_id === (int) $user->id;
    }

    public function assertCanManage(User $user, ?string $message = null): void
    {
        if (! $user->can('asset.manage') && ! $this->isAssetAdmin($user)) {
            throw new AssetDomainException($message ?? 'Anda tidak berwenang mengubah data aset.', 403);
        }
    }

    public function assertCanViewFinancials(User $user): void
    {
        if (! $this->canViewFinancials($user)) {
            throw new AssetDomainException('Data keuangan aset hanya untuk pemegang izin keuangan.', 403);
        }
    }

    /** Lokasi yang boleh dilihat pengguna (dipakai pemilih lokasi di layar). */
    public function scopeLocations(Builder $query, User $user): Builder
    {
        if ($user->can('asset.view.all') || $this->isAssetAdmin($user) || $this->isFacilityManager($user)) {
            return $query;
        }

        $unitId = $this->unitIdFor($user);

        return $query->where(function (Builder $inner) use ($user, $unitId): void {
            $inner->where('manager_user_id', $user->id);

            if ($unitId !== null) {
                $inner->orWhere('responsible_unit_id', $unitId);
            }
        });
    }
}
