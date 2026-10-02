<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrDepartment;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeAssignment;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrOrganizationHistory;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Models\HrWorkLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Organisasi berjenjang, departemen, dan lokasi kerja (§3–§7).
 *
 * Tiga keputusan yang membedakan ini dari CRUD organisasi biasa:
 *
 * 1. **Materialized path** (`1/4/9/`). Memindahkan unit berarti memperbarui path
 *    seluruh keturunannya — dilakukan di satu transaksi, sehingga sub-pohon tidak
 *    pernah setengah pindah. Ini juga membuat bagan organisasi tidak perlu kueri
 *    rekursif.
 * 2. **Riwayat struktur.** Mengubah induk unit bukan sekadar UPDATE: siapa, kapan,
 *    dari mana ke mana, atas alasan apa dicatat di `hr_organization_histories`.
 * 3. **Jumlah pegawai tidak diketik.** Dihitung dari penugasan aktif pada tanggal
 *    yang ditanyakan, sehingga bagan organisasi tidak pernah bertentangan dengan
 *    daftar pegawai.
 */
class OrganizationService
{
    public function __construct(
        private readonly ReferenceService $references,
        private readonly HrdAuditService $audit,
    ) {}

    /* ── Organisasi ─────────────────────────────────────────────────────── */

    public function createOrganization(array $attributes, User $actor): HrOrganization
    {
        $this->references->assertValid('organization_type', $attributes['type_code'] ?? null, 'type_code');

        if (HrOrganization::query()->where('code', $attributes['code'])->exists()) {
            throw HrdValidationException::forField('code', "Kode unit \"{$attributes['code']}\" sudah dipakai.", 'hr.organization.code_taken');
        }

        return DB::transaction(function () use ($attributes, $actor): HrOrganization {
            $parent = $this->resolveParent($attributes['parent_id'] ?? null);

            $organization = HrOrganization::query()->create([
                ...$attributes,
                'parent_id' => $parent?->getKey(),
                'depth' => $parent === null ? 0 : (int) $parent->depth + 1,
                'status' => $attributes['status'] ?? 'active',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $organization->forceFill(['path' => $this->buildPath($organization)])->save();

            $this->recordHistory($organization, 'created', null, $this->snapshot($organization), $attributes['reason'] ?? null, $actor);

            $this->audit->log($actor, 'organization.created', $organization, null, $this->snapshot($organization));

            return $organization;
        });
    }

    public function updateOrganization(HrOrganization $organization, array $attributes, User $actor, ?string $reason = null): HrOrganization
    {
        $this->references->assertValid('organization_type', $attributes['type_code'] ?? null, 'type_code');

        return DB::transaction(function () use ($organization, $attributes, $actor, $reason): HrOrganization {
            $organization = HrOrganization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($organization);

            if (array_key_exists('code', $attributes) && $attributes['code'] !== $organization->code) {
                $taken = HrOrganization::query()->where('code', $attributes['code'])->whereKeyNot($organization->getKey())->exists();

                if ($taken) {
                    throw HrdValidationException::forField('code', "Kode unit \"{$attributes['code']}\" sudah dipakai.", 'hr.organization.code_taken');
                }
            }

            $reparent = array_key_exists('parent_id', $attributes) && (int) ($attributes['parent_id'] ?? 0) !== (int) ($organization->parent_id ?? 0);
            $newParent = $reparent ? $this->resolveParent($attributes['parent_id']) : $organization->parent;

            if ($reparent) {
                $this->assertNoCycle($organization, $newParent);
            }

            $organization->fill($attributes);

            if ($reparent) {
                $organization->parent_id = $newParent?->getKey();
                $organization->depth = $newParent === null ? 0 : (int) $newParent->depth + 1;
            }

            $organization->updated_by = $actor->getKey();
            $organization->version = (int) $organization->version + 1;
            $organization->save();

            if ($reparent) {
                $organization->forceFill(['path' => $this->buildPath($organization)])->save();
                $this->repathDescendants($organization);
                $this->recordHistory($organization, 'reparented', $before, $this->snapshot($organization), $reason, $actor);
            } elseif ($before['name'] !== $organization->name) {
                $this->recordHistory($organization, 'renamed', $before, $this->snapshot($organization), $reason, $actor);
            } elseif ($before['type_code'] !== $organization->type_code) {
                $this->recordHistory($organization, 'typed', $before, $this->snapshot($organization), $reason, $actor);
            } else {
                $this->recordHistory($organization, 'updated', $before, $this->snapshot($organization), $reason, $actor);
            }

            $this->audit->log($actor, 'organization.updated', $organization, $before, $this->snapshot($organization), $reason);

            return $organization->refresh();
        });
    }

    /** Aktif/nonaktif unit; menonaktifkan unit tidak menonaktifkan pegawainya. */
    public function setOrganizationStatus(HrOrganization $organization, string $status, User $actor, ?string $reason = null): HrOrganization
    {
        if (! in_array($status, ['active', 'inactive', 'archived'], true)) {
            throw HrdValidationException::forField('status', "Status unit \"{$status}\" tidak dikenal.", 'hr.organization.status_invalid');
        }

        return DB::transaction(function () use ($organization, $status, $actor, $reason): HrOrganization {
            $before = $this->snapshot($organization);

            if ($status !== 'active' && $this->hasActiveChildren($organization)) {
                throw HrdValidationException::forField(
                    'status',
                    'Unit ini masih memiliki sub-unit aktif. Pindahkan atau nonaktifkan sub-unitnya lebih dulu.',
                    'hr.organization.has_active_children',
                );
            }

            $organization->forceFill([
                'status' => $status,
                'updated_by' => $actor->getKey(),
                'version' => (int) $organization->version + 1,
            ])->save();

            if ($status === 'active') {
                $this->fillAncestorGaps($organization, $actor);
            }

            $this->recordHistory($organization, $status === 'active' ? 'activated' : 'deactivated', $before, $this->snapshot($organization), $reason, $actor);
            $this->audit->log($actor, "organization.{$status}", $organization, $before, $this->snapshot($organization), $reason);

            return $organization->refresh();
        });
    }

    /**
     * Bagan organisasi lengkap dengan jumlah pegawai dan pejabatnya (§5, §22).
     *
     * @param  list<int>|null  $visibleOrganizationIds  batas cakupan pembaca
     * @return list<array<string, mixed>>
     */
    public function tree(?array $visibleOrganizationIds = null, bool $includeInactive = false, ?string $onDate = null): array
    {
        $organizations = HrOrganization::query()
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->when($visibleOrganizationIds !== null, fn ($q) => $q->whereIn('id', $visibleOrganizationIds))
            ->orderBy('depth')
            ->orderBy('name')
            ->get();

        if ($organizations->isEmpty()) {
            return [];
        }

        $allowedIds = $organizations->pluck('id')->map(fn ($id) => (int) $id)->all();
        $counts = $this->employeeCountsByOrganization($allowedIds, $onDate);
        $positions = $this->positionCountsByOrganization($allowedIds);
        $managers = $this->managerNames($organizations);

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];

        foreach ($organizations as $organization) {
            $id = (int) $organization->getKey();

            $nodes[$id] = [
                'id' => $id,
                'code' => $organization->code,
                'name' => $organization->name,
                'type_code' => $organization->type_code,
                'type_label' => $this->references->label('organization_type', $organization->type_code),
                'status' => $organization->status,
                'parent_id' => $organization->parent_id ? (int) $organization->parent_id : null,
                'depth' => (int) $organization->depth,
                'manager_employee_id' => $organization->manager_employee_id ? (int) $organization->manager_employee_id : null,
                'manager_name' => $managers[(int) ($organization->manager_employee_id ?? 0)] ?? null,
                'cost_center_code' => $organization->cost_center_code,
                'authorized_headcount' => $organization->authorized_headcount,
                'employee_count' => $counts[$id]['direct'] ?? 0,
                'employee_count_with_descendants' => $counts[$id]['total'] ?? 0,
                'position_count' => $positions[$id]['positions'] ?? 0,
                'vacant_count' => $positions[$id]['vacant'] ?? 0,
                'children' => [],
            ];
        }

        $roots = [];

        foreach ($nodes as $id => $node) {
            $parentId = $node['parent_id'];

            if ($parentId !== null && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$nodes[$id];

                continue;
            }

            $roots[] = &$nodes[$id];
        }

        return array_values($roots);
    }

    /** @return array<string, mixed>|null */
    public function show(int $organizationId): ?array
    {
        $organization = HrOrganization::query()
            ->with(['parent:id,name,code', 'manager:id,employee_number,person_id', 'manager.person:id,full_name'])
            ->withCount(['employees', 'departments', 'positions'])
            ->find($organizationId);

        if ($organization === null) {
            return null;
        }

        return [
            'organization' => $organization,
            'descendants' => $organization->descendants()->count(),
            'history' => HrOrganizationHistory::query()
                ->where('organization_id', $organizationId)
                ->latest('id')
                ->limit(30)
                ->get(),
        ];
    }

    /* ── Departemen ─────────────────────────────────────────────────────── */

    public function createDepartment(array $attributes, User $actor): HrDepartment
    {
        $organization = HrOrganization::query()->findOrFail($attributes['organization_id']);

        if (HrDepartment::query()->where('code', $attributes['code'])->exists()) {
            throw HrdValidationException::forField('code', "Kode departemen \"{$attributes['code']}\" sudah dipakai.", 'hr.department.code_taken');
        }

        $parent = null;

        if (! empty($attributes['parent_id'])) {
            $parent = HrDepartment::query()->findOrFail($attributes['parent_id']);

            if ((int) $parent->organization_id !== (int) $organization->getKey()) {
                throw HrdValidationException::forField(
                    'parent_id',
                    'Departemen induk harus berada pada unit organisasi yang sama.',
                    'hr.department.cross_unit_parent',
                );
            }
        }

        return DB::transaction(function () use ($attributes, $actor, $organization): HrDepartment {
            $department = HrDepartment::query()->create([
                ...$attributes,
                'status' => $attributes['status'] ?? 'active',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit->log($actor, 'department.created', $department, null, $department->toArray());

            return $department;
        });
    }

    public function updateDepartment(HrDepartment $department, array $attributes, User $actor, ?string $reason = null): HrDepartment
    {
        return DB::transaction(function () use ($department, $attributes, $actor, $reason): HrDepartment {
            $department = HrDepartment::query()->whereKey($department->getKey())->lockForUpdate()->firstOrFail();
            $before = $department->toArray();

            if (! empty($attributes['parent_id'])) {
                $parent = HrDepartment::query()->findOrFail($attributes['parent_id']);

                if ((int) $parent->organization_id !== (int) $department->organization_id) {
                    throw HrdValidationException::forField('parent_id', 'Departemen induk harus berada pada unit yang sama.', 'hr.department.cross_unit_parent');
                }

                if ((int) $parent->getKey() === (int) $department->getKey()) {
                    throw HrdValidationException::forField('parent_id', 'Departemen tidak dapat menjadi induk dirinya sendiri.', 'hr.department.self_parent');
                }
            }

            $department->fill($attributes);
            $department->updated_by = $actor->getKey();
            $department->version = (int) $department->version + 1;
            $department->save();

            $this->audit->log($actor, 'department.updated', $department, $before, $department->toArray(), $reason);

            return $department->refresh();
        });
    }

    /** @return Collection<int, HrDepartment> */
    public function departments(?int $organizationId = null, bool $activeOnly = true): Collection
    {
        return HrDepartment::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->when($activeOnly, fn ($q) => $q->active())
            ->with(['head:id,employee_number,person_id', 'head.person:id,full_name'])
            ->withCount('employees')
            ->orderBy('name')
            ->get();
    }

    /* ── Lokasi kerja ───────────────────────────────────────────────────── */

    public function createLocation(array $attributes, User $actor): HrWorkLocation
    {
        if (HrWorkLocation::query()->where('code', $attributes['code'])->exists()) {
            throw HrdValidationException::forField('code', "Kode lokasi \"{$attributes['code']}\" sudah dipakai.", 'hr.location.code_taken');
        }

        return DB::transaction(function () use ($attributes, $actor): HrWorkLocation {
            $location = HrWorkLocation::query()->create([
                ...$attributes,
                'status' => $attributes['status'] ?? 'active',
            ]);

            if (($attributes['is_primary'] ?? false) === true) {
                $this->clearPrimaryLocation($location);
            }

            $this->audit->log($actor, 'location.created', $location, null, $location->toArray());

            return $location;
        });
    }

    public function updateLocation(HrWorkLocation $location, array $attributes, User $actor): HrWorkLocation
    {
        return DB::transaction(function () use ($location, $attributes, $actor): HrWorkLocation {
            $before = $location->toArray();

            $location->fill($attributes)->save();

            if (($attributes['is_primary'] ?? false) === true) {
                $this->clearPrimaryLocation($location);
            }

            $this->audit->log($actor, 'location.updated', $location, $before, $location->toArray());

            return $location->refresh();
        });
    }

    private function clearPrimaryLocation(HrWorkLocation $location): void
    {
        HrWorkLocation::query()
            ->where('organization_id', $location->organization_id)
            ->whereKeyNot($location->getKey())
            ->update(['is_primary' => false]);
    }

    /* ── Perhitungan ────────────────────────────────────────────────────── */

    /**
     * Jumlah pegawai per unit: langsung dan total (termasuk sub-unit).
     *
     * @param  list<int>  $organizationIds
     * @return array<int, array{direct: int, total: int}>
     */
    public function employeeCountsByOrganization(array $organizationIds, ?string $onDate = null): array
    {
        $date = $onDate ?? now()->toDateString();

        $direct = HrEmployee::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereNotIn('lifecycle_state', ['inactive', 'archived'])
            ->selectRaw('organization_id, count(*) as aggregate')
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id')
            ->map(fn ($value) => (int) $value)
            ->all();

        // Pegawai yang penempatannya berbeda dari unit penugasannya tetap dihitung
        // pada unit penugasan — inilah yang membuat bagan dan daftar tidak berbeda.
        $assignmentDirect = HrEmployeeAssignment::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
            ->selectRaw('organization_id, count(distinct employee_id) as aggregate')
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id')
            ->map(fn ($value) => (int) $value)
            ->all();

        $result = [];

        foreach ($organizationIds as $id) {
            $result[(int) $id] = [
                'direct' => max($direct[$id] ?? 0, $assignmentDirect[$id] ?? 0),
                'total' => 0,
            ];
        }

        foreach ($result as $id => $row) {
            $total = $row['direct'];

            $prefix = HrOrganization::query()->whereKey($id)->value('path');

            if (is_string($prefix) && $prefix !== '') {
                $descendantIds = HrOrganization::query()
                    ->where('path', 'like', $prefix.'%')
                    ->whereKeyNot($id)
                    ->pluck('id');

                foreach ($descendantIds as $descendantId) {
                    $total += $result[(int) $descendantId]['direct'] ?? 0;
                }
            }

            $result[$id]['total'] = $total;
        }

        return $result;
    }

    /**
     * @param  list<int>  $organizationIds
     * @return array<int, array{positions: int, vacant: int}>
     */
    public function positionCountsByOrganization(array $organizationIds): array
    {
        $rows = HrPosition::query()
            ->whereIn('organization_id', $organizationIds)
            ->open()
            ->selectRaw('organization_id, count(*) as positions, sum(case when authorized_headcount > filled_headcount and vacancy_status != ? then 1 else 0 end) as vacant', ['frozen'])
            ->groupBy('organization_id')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->organization_id] = [
                'positions' => (int) $row->positions,
                'vacant' => (int) $row->vacant,
            ];
        }

        return $result;
    }

    /** @return array<int, string> nama pejabat per id pegawai */
    private function managerNames(Collection $organizations): array
    {
        $ids = $organizations->pluck('manager_employee_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return HrEmployee::query()
            ->whereIn('id', $ids)
            ->with('person:id,full_name')
            ->get()
            ->mapWithKeys(fn (HrEmployee $employee) => [(int) $employee->getKey() => $employee->displayName()])
            ->all();
    }

    /* ── Pohon & path ───────────────────────────────────────────────────── */

    private function resolveParent(int|string|null $parentId): ?HrOrganization
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = HrOrganization::query()->find($parentId);

        if ($parent === null) {
            throw HrdValidationException::forField('parent_id', 'Unit induk tidak ditemukan.', 'hr.organization.parent_missing');
        }

        if ((int) $parent->depth >= 8) {
            throw HrdValidationException::forField('parent_id', 'Kedalaman struktur organisasi melebihi batas delapan tingkat.', 'hr.organization.too_deep');
        }

        return $parent;
    }

    private function buildPath(HrOrganization $organization): string
    {
        if ($organization->parent_id === null) {
            return '/'.$organization->getKey().'/';
        }

        $parentPath = $organization->parent?->path ?? '/';

        return rtrim($parentPath, '/').'/'.$organization->getKey().'/';
    }

    private function repathDescendants(HrOrganization $organization): void
    {
        $oldPrefix = $organization->path;
        $descendants = HrOrganization::query()->where('path', 'like', $oldPrefix.'%')->whereKeyNot($organization->getKey())->get();

        foreach ($descendants as $descendant) {
            $newPath = str_replace($oldPrefix, $organization->path, (string) $descendant->path);
            $descendant->forceFill([
                'path' => $newPath,
                'depth' => substr_count(trim($newPath, '/'), '/'),
            ])->save();
        }
    }

    private function assertNoCycle(HrOrganization $organization, ?HrOrganization $newParent): void
    {
        if ($newParent === null) {
            return;
        }

        if ((int) $newParent->getKey() === (int) $organization->getKey()) {
            throw HrdValidationException::forField('parent_id', 'Unit tidak dapat menjadi induk dirinya sendiri.', 'hr.organization.self_parent');
        }

        if ($organization->isAncestorOf($newParent)) {
            throw HrdValidationException::forField(
                'parent_id',
                'Unit induk yang dipilih berada di dalam sub-pohon unit ini — struktur akan menjadi lingkaran.',
                'hr.organization.cycle',
            );
        }
    }

    private function hasActiveChildren(HrOrganization $organization): bool
    {
        return HrOrganization::query()->where('parent_id', $organization->getKey())->where('status', 'active')->exists();
    }

    /**
     * Mengaktifkan sub-unit otomatis mengaktifkan leluhurnya: unit yang masih
     * hidup tidak boleh menggantung di bawah unit yang sudah nonaktif.
     */
    private function fillAncestorGaps(HrOrganization $organization, User $actor): void
    {
        $current = $organization->parent;

        while ($current !== null) {
            if ($current->status !== 'active') {
                $current->forceFill(['status' => 'active', 'updated_by' => $actor->getKey()])->save();
                $this->recordHistory($current, 'activated', null, $this->snapshot($current), 'Diaktifkan otomatis karena sub-unitnya aktif', $actor);
            }

            $current = $current->parent;
        }
    }

    private function recordHistory(HrOrganization $organization, string $changeType, ?array $before, ?array $after, ?string $reason, User $actor): void
    {
        HrOrganizationHistory::query()->create([
            'organization_id' => $organization->getKey(),
            'change_type' => $changeType,
            'before' => $before,
            'after' => $after,
            'effective_from' => $organization->effective_from,
            'reason' => $reason,
            'actor_id' => $actor->getKey(),
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(HrOrganization $organization): array
    {
        return [
            'code' => $organization->code,
            'name' => $organization->name,
            'type_code' => $organization->type_code,
            'parent_id' => $organization->parent_id ? (int) $organization->parent_id : null,
            'status' => $organization->status,
            'path' => $organization->path,
            'manager_employee_id' => $organization->manager_employee_id ? (int) $organization->manager_employee_id : null,
            'cost_center_code' => $organization->cost_center_code,
        ];
    }
}
