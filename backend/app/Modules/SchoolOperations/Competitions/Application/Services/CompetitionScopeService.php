<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Models\Competition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/** Tenant/campus guard. Global access is explicit; scoped users fail closed. */
class CompetitionScopeService
{
    private const GLOBAL_ROLES = ['SUPER_ADMIN', 'ERP_ADMIN', 'super-admin', 'FOUNDATION_ADMIN'];

    public function isGlobal(?User $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        return $actor->can('competition.scope.all')
            || $actor->hasAnyRole(self::GLOBAL_ROLES);
    }

    public function visibleCompetitions(Builder $query, ?User $actor): Builder
    {
        if ($this->isGlobal($actor)) {
            return $query;
        }

        $campusId = $this->contextId($actor, ['campus_id', 'selected_campus_id']);
        $organizationId = $this->contextId($actor, ['organization_id', 'school_id']);

        if ($campusId !== null) {
            $query->where(function (Builder $scoped) use ($campusId): void {
                $scoped->whereNull('campus_id')->orWhere('campus_id', $campusId);
            });
        } else {
            // When no campus context exists, only unscoped, school-wide records
            // are visible. This avoids turning a missing claim into tenant-wide access.
            $query->whereNull('campus_id');
        }

        if (Schema::hasColumn('competitions', 'organization_id')) {
            if ($organizationId !== null) {
                $query->where(function (Builder $scoped) use ($organizationId): void {
                    $scoped->whereNull('organization_id')->orWhere('organization_id', $organizationId);
                });
            } else {
                $query->whereNull('organization_id');
            }
        }

        return $query;
    }

    public function assertCompetition(?User $actor, Competition $competition): void
    {
        if ($this->isGlobal($actor)) {
            return;
        }

        $campusId = $this->contextId($actor, ['campus_id', 'selected_campus_id']);
        $organizationId = $this->contextId($actor, ['organization_id', 'school_id']);

        if ($competition->campus_id !== null && (int) $competition->campus_id !== $campusId) {
            abort(404, 'Kompetisi tidak ditemukan dalam cakupan kampus Anda.');
        }
        if ($competition->organization_id !== null && ($organizationId === null || (int) $competition->organization_id !== $organizationId)) {
            abort(404, 'Kompetisi tidak ditemukan dalam cakupan organisasi Anda.');
        }
        if ($competition->campus_id !== null && $campusId === null) {
            abort(404, 'Konteks kampus diperlukan untuk mengakses kompetisi ini.');
        }
    }

    public function assertEdition(?User $actor, CompetitionEdition $edition): void
    {
        $competition = Competition::query()->findOrFail($edition->competition_id);
        $this->assertCompetition($actor, $competition);
        if ($this->isGlobal($actor)) {
            return;
        }
        $campusId = $this->contextId($actor, ['campus_id', 'selected_campus_id']);
        $organizationId = $this->contextId($actor, ['organization_id', 'school_id']);
        if ($edition->campus_id !== null && ($campusId === null || (int) $edition->campus_id !== $campusId)) {
            abort(404, 'Edition tidak ditemukan dalam cakupan kampus Anda.');
        }
        if ($edition->organization_id !== null && ($organizationId === null || (int) $edition->organization_id !== $organizationId)) {
            abort(404, 'Edition tidak ditemukan dalam cakupan organisasi Anda.');
        }
    }

    private function contextId(?User $actor, array $keys): ?int
    {
        if ($actor === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $actor->getAttribute($key);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        $userable = $actor->userable;
        foreach ($keys as $key) {
            $value = $userable?->getAttribute($key);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }
}
