<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Application\Services\OrganizationService;
use App\Modules\Hrd\Domain\Contracts\OrganizationProviderInterface;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOrganization;

/**
 * Organisasi & departemen untuk modul lain (§2, §65).
 *
 * Satu kelas untuk dua kontrak karena keduanya membaca pohon yang sama: unit dan
 * departemen di bawahnya. Memisahkannya hanya akan menggandakan kueri pohon yang
 * identik.
 */
class OrganizationModuleProvider implements OrganizationProviderInterface
{
    public function __construct(private readonly OrganizationService $organizations) {}

    /* ── Organisasi ─────────────────────────────────────────────────────── */

    public function findById(int $organizationId): ?array
    {
        return HrOrganization::query()->find($organizationId)?->toArray();
    }

    public function findByCode(string $code): ?array
    {
        return HrOrganization::query()->where('code', $code)->first()?->toArray();
    }

    public function all(bool $activeOnly = true): array
    {
        return HrOrganization::query()
            ->when($activeOnly, fn ($q) => $q->active())
            ->orderBy('depth')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type_code', 'parent_id', 'path', 'depth', 'status', 'manager_employee_id'])
            ->toArray();
    }

    public function treeFrom(int $organizationId): ?array
    {
        $tree = $this->organizations->tree([$organizationId], true);

        return $tree[0] ?? null;
    }

    public function descendantIds(int $organizationId, bool $includeSelf = true): array
    {
        $organization = HrOrganization::query()->find($organizationId);

        if ($organization === null) {
            return [];
        }

        $ids = HrOrganization::query()
            ->where('path', 'like', ($organization->path ?? '/'.$organizationId.'/').'%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! $includeSelf) {
            $ids = array_values(array_filter($ids, fn (int $id) => $id !== $organizationId));
        }

        return $ids;
    }

    public function headOf(int $organizationId): ?array
    {
        $organization = HrOrganization::query()->with('manager.person:id,full_name')->find($organizationId);

        if ($organization?->manager !== null) {
            return [
                'employee_id' => (int) $organization->manager->getKey(),
                'name' => $organization->manager->displayName(),
                'user_id' => $organization->manager->user_id,
            ];
        }

        // Pimpinan posisi di unit tersebut (mis. "Kepala SMP"), bila unit tidak
        // menunjuk pejabat secara langsung.
        $head = HrEmployee::query()
            ->with('person:id,full_name')
            ->where('organization_id', $organizationId)
            ->active()
            ->whereHas('position', fn ($q) => $q->where('title', 'like', '%kepala%'))
            ->first();

        return $head === null ? null : [
            'employee_id' => (int) $head->getKey(),
            'name' => $head->displayName(),
            'user_id' => $head->user_id,
        ];
    }

    public function isWithin(int $organizationId, int $ancestorId): bool
    {
        if ($organizationId === $ancestorId) {
            return true;
        }

        return in_array($organizationId, $this->descendantIds($ancestorId, false), true);
    }
}
