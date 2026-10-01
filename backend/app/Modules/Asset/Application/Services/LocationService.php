<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\LocationType;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lokasi berjenjang: kampus → gedung → lantai → ruang/area.
 *
 * Hierarki dijaga di satu tempat. Dua kesalahan yang paling sering terjadi pada
 * data lokasi — induk yang tidak mungkin (ruang menjadi induk kampus) dan
 * lingkaran (A induk B, B induk A) — ditolak di sini, bukan ditemukan belakangan
 * saat laporan tidak lagi dapat ditelusuri.
 */
class LocationService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
    ) {}

    public function create(User $actor, array $data): Location
    {
        $type = LocationType::from((string) $data['location_type']);
        $parent = $this->resolveParent($data['parent_location_id'] ?? null, $type);

        return DB::transaction(function () use ($actor, $data, $type, $parent): Location {
            $location = Location::query()->create([
                'campus_id' => $data['campus_id'] ?? $parent?->campus_id,
                'building_id' => $data['building_id'] ?? $parent?->building_id,
                'building_floor_id' => $data['building_floor_id'] ?? $parent?->building_floor_id,
                'parent_location_id' => $parent?->id,
                'code' => $data['code'] ?? $this->numbers->next('location'),
                'name' => $data['name'],
                'location_type' => $type->value,
                'level' => $type->level(),
                'capacity' => $data['capacity'] ?? null,
                'area_m2' => $data['area_m2'] ?? null,
                'floor_label' => $data['floor_label'] ?? null,
                'function' => $data['function'] ?? null,
                'status' => $data['status'] ?? 'ACTIVE',
                'responsible_unit_id' => $data['responsible_unit_id'] ?? $parent?->responsible_unit_id,
                'manager_user_id' => $data['manager_user_id'] ?? $parent?->manager_user_id,
                'contact' => $data['contact'] ?? null,
                'is_bookable' => (bool) ($data['is_bookable'] ?? $type->canHoldAssets()),
                'description' => $data['description'] ?? null,
            ]);

            $location->forceFill(['path' => $this->buildPath($location)])->save();

            $this->audit->record(AuditAction::ASSET_CREATED, $location, $actor, [
                'after' => $location->only(['code', 'name', 'location_type', 'path']),
                'status_after' => $location->status,
                'reason' => 'Pembuatan lokasi baru.',
            ]);

            return $location;
        });
    }

    public function update(User $actor, Location $location, array $data): Location
    {
        if (isset($data['location_type']) || array_key_exists('parent_location_id', $data)) {
            $type = LocationType::from((string) ($data['location_type'] ?? $location->location_type));
            $newParent = array_key_exists('parent_location_id', $data)
                ? Location::query()->find($data['parent_location_id'])
                : $location->parent;

            $this->assertNoCycle($location, $newParent);
            $this->assertHierarchy($type, $newParent);

            if ($location->children()->exists() && $type->level() >= (int) $location->children()->min('level')) {
                throw new AssetValidationException([
                    'location_type' => ['Lokasi ini masih memiliki turunan; jenisnya tidak dapat diturunkan tingkatnya.'],
                ]);
            }
        }

        $before = $location->only(['code', 'name', 'location_type', 'capacity', 'status', 'responsible_unit_id']);

        $location->fill($data)->save();

        if (isset($data['parent_location_id']) || isset($data['location_type'])) {
            $location->forceFill(['path' => $this->buildPath($location)])->save();
        }

        $this->audit->record(AuditAction::ASSET_UPDATED, $location, $actor, [
            'before' => $before,
            'after' => $location->only(array_keys($before)),
            'reason' => 'Perubahan data lokasi.',
        ]);

        return $location;
    }

    /**
     * Lokasi tidak dihapus bila masih menaungi sesuatu.
     *
     * "Menghapus" ruang yang masih berisi aset akan membuat aset itu kehilangan
     * tempat — bukan menghapus masalahnya. Untuk itu pesannya menyebut apa yang
     * harus dipindahkan lebih dulu.
     */
    public function archive(User $actor, Location $location, ?string $reason = null): Location
    {
        $assets = $location->assets()->count();
        $facilities = $location->facilities()->count();
        $children = $location->children()->count();

        if ($assets + $facilities + $children > 0) {
            throw new AssetDomainException(sprintf(
                'Lokasi %s masih menaungi %d aset, %d fasilitas, dan %d sub-lokasi. Pindahkan isinya terlebih dahulu.',
                $location->name, $assets, $facilities, $children,
            ), 409);
        }

        $before = $location->status;
        $location->forceFill(['status' => 'INACTIVE'])->save();

        $this->audit->record(AuditAction::ASSET_UPDATED, $location, $actor, [
            'status_before' => $before,
            'status_after' => 'INACTIVE',
            'reason' => $reason ?? 'Lokasi dinonaktifkan.',
        ]);

        return $location;
    }

    /** Seluruh turunan sebuah lokasi (termasuk dirinya). */
    public function descendantIds(Location $location): array
    {
        $ids = [$location->id];
        $frontier = [$location->id];

        while ($frontier !== []) {
            $children = Location::query()->whereIn('parent_location_id', $frontier)->pluck('id')->all();

            if ($children === []) {
                break;
            }

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    /** Pohon lokasi untuk tampilan dan laporan. */
    public function tree(?int $campusId = null): Collection
    {
        $query = Location::query()->orderBy('path');

        if ($campusId !== null) {
            $query->where('campus_id', $campusId);
        }

        $all = $query->get();

        return $all->groupBy('parent_location_id')->get(null, collect());
    }

    protected function resolveParent(mixed $parentId, LocationType $type): ?Location
    {
        $parent = $parentId === null ? null : Location::query()->find($parentId);

        if ($parentId !== null && $parent === null) {
            throw new AssetValidationException(['parent_location_id' => ['Induk lokasi tidak ditemukan.']]);
        }

        $this->assertHierarchy($type, $parent);

        return $parent;
    }

    protected function assertHierarchy(LocationType $type, ?Location $parent): void
    {
        if ($parent === null) {
            return;
        }

        $parentType = LocationType::from((string) $parent->location_type);

        if ($parentType->level() >= $type->level()) {
            throw new AssetValidationException([
                'parent_location_id' => [sprintf(
                    'Induk berjenis %s tidak dapat menaungi %s: hierarkinya harus lebih tinggi.',
                    $parentType->label(), $type->label(),
                )],
            ]);
        }

        if ($parentType->level() + 1 !== $type->level() && $type->level() < 4) {
            throw new AssetValidationException([
                'parent_location_id' => [sprintf(
                    '%s harus berada tepat di bawah %s.',
                    $type->label(), $this->parentTypeFor($type)->label(),
                )],
            ]);
        }
    }

    protected function parentTypeFor(LocationType $type): LocationType
    {
        return match ($type) {
            LocationType::BUILDING => LocationType::CAMPUS,
            LocationType::FLOOR => LocationType::BUILDING,
            default => LocationType::FLOOR,
        };
    }

    protected function assertNoCycle(Location $location, ?Location $parent): void
    {
        if ($parent === null) {
            return;
        }

        if ($parent->is($location) || in_array($parent->id, $this->descendantIds($location), true)) {
            throw new AssetValidationException([
                'parent_location_id' => ['Induk yang dipilih adalah turunan lokasi ini — akan membentuk lingkaran.'],
            ]);
        }
    }

    protected function buildPath(Location $location): string
    {
        $code = strtoupper((string) $location->code);
        $parent = $location->parent;

        if ($parent === null) {
            return $code;
        }

        return trim((string) $parent->path, '/').'/'.$code;
    }
}
