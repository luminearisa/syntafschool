<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Contracts\UserAccountProviderInterface;
use App\Modules\Hrd\Domain\Enums\DocumentVisibility;
use App\Modules\Hrd\Domain\Exceptions\HrdAccessDeniedException;
use App\Modules\Hrd\Models\HrDepartment;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeManager;
use App\Modules\Hrd\Models\HrOrganization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Penegakan cakupan data & kerahasiaan (§52, §79).
 *
 * Modul ini menyimpan data paling sensitif di sekolah: NIK, alamat rumah, data
 * keluarga, catatan kedisiplinan, berkas kontrak. Karena itu pertanyaan "boleh
 * membaca apa" dijawab di satu tempat — bukan disebar sebagai `if` di controller.
 *
 * Empat tingkat akses, dan pemisahannya disengaja:
 *
 * ```
 * GLOBAL        HR Admin   — seluruh yayasan, seluruh kolom
 * ORGANIZATION  HR unit    — hanya unit yang dikelolanya (termasuk sub-unit)
 * TEAM          atasan     — dirinya dan bawahannya (langsung & fungsional)
 * SELF          pegawai    — dirinya sendiri, kolom non-sensitif saja
 * ```
 *
 * Administrator teknis (yang menyentuh server) TIDAK otomatis mendapat GLOBAL:
 * akses bisnis diberikan lewat izin `hr.employee.view.all`, bukan lewat peran
 * sistem. Ini yang diminta §52: "technical administrator" ≠ "HR administrator".
 */
class HrdAccessService
{
    public const LEVEL_GLOBAL = 'global';
    public const LEVEL_ORGANIZATION = 'organization';
    public const LEVEL_TEAM = 'team';
    public const LEVEL_SELF = 'self';

    private const MAX_ORG_DEPTH = 8;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly UserAccountProviderInterface $users) {}

    /* ── Tingkat akses ──────────────────────────────────────────────────── */

    public function scopeLevel(User $user): string
    {
        $key = "level:{$user->getKey()}";

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $level = match (true) {
            $this->can($user, 'hr.employee.view.all') => self::LEVEL_GLOBAL,
            $this->can($user, 'hr.employee.view') => self::LEVEL_ORGANIZATION,
            $this->can($user, 'hr.team.view') => self::LEVEL_TEAM,
            default => self::LEVEL_SELF,
        };

        return $this->memo[$key] = $level;
    }

    public function isGlobal(User $user): bool
    {
        return $this->scopeLevel($user) === self::LEVEL_GLOBAL;
    }

    public function isHr(User $user): bool
    {
        return in_array($this->scopeLevel($user), [self::LEVEL_GLOBAL, self::LEVEL_ORGANIZATION], true);
    }

    public function isAuditor(User $user): bool
    {
        return $this->can($user, 'hr.audit.view');
    }

    /** Kolom sensitif (NIK, nomor rekening) hanya untuk pemegang izin khusus. */
    public function canViewSensitive(User $user): bool
    {
        return $this->can($user, 'hr.employee.sensitive.view') || $this->isGlobal($user);
    }

    public function canManageEmployees(User $user): bool
    {
        return $this->can($user, 'hr.employee.update') || $this->can($user, 'hr.employee.create');
    }

    public function can(User $user, string|array $permission): bool
    {
        if (is_array($permission)) {
            foreach ($permission as $candidate) {
                if ($this->can($user, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        return $user->can($permission);
    }

    /* ── Identitas pegawai dari akun ────────────────────────────────────── */

    public function employeeForUser(User $user): ?HrEmployee
    {
        $key = "employee:{$user->getKey()}";

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = HrEmployee::query()
            ->with('person')
            ->where('user_id', $user->getKey())
            ->first();
    }

    public function employeeIdForUser(User $user): ?int
    {
        return $this->employeeForUser($user)?->getKey();
    }

    /* ── Unit organisasi yang dikelola ──────────────────────────────────── */

    /**
     * Unit yang berada dalam kewenangan pemanggil, sudah termasuk sub-unit.
     * `null` berarti seluruh unit (akses global).
     *
     * @return list<int>|null
     */
    public function visibleOrganizationIds(User $user): ?array
    {
        if ($this->isGlobal($user)) {
            return null;
        }

        $employeeId = $this->employeeIdForUser($user);

        if ($employeeId === null) {
            return [];
        }

        $roots = HrOrganization::query()
            ->where('manager_employee_id', $employeeId)
            ->pluck('id')
            ->merge(HrDepartment::query()->where('head_employee_id', $employeeId)->pluck('organization_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $ids = [];

        foreach ($roots as $rootId) {
            array_push($ids, ...$this->organizationBranchIds($rootId));
        }

        return array_values(array_unique($ids));
    }

    /** Unit + seluruh keturunannya (dibatasi kedalaman agar data nyasar tidak melebar). */
    public function organizationBranchIds(int $organizationId): array
    {
        $ids = [$organizationId];
        $frontier = [$organizationId];
        $depth = 0;

        while ($frontier !== [] && $depth < self::MAX_ORG_DEPTH) {
            $frontier = HrOrganization::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = array_merge($ids, $frontier);
            $depth++;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Bawahan pemanggil: dirinya sendiri, bawahan langsung, dan turunannya
     * (garis langsung maupun fungsional) sampai kedalaman konfigurasi.
     *
     * @return list<int>
     */
    public function teamEmployeeIds(User $user): array
    {
        $key = "team:{$user->getKey()}";

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $employeeId = $this->employeeIdForUser($user);

        if ($employeeId === null) {
            return $this->memo[$key] = [];
        }

        $ids = [$employeeId];
        $frontier = [$employeeId];
        $maxDepth = (int) config('hrd.scopes.manager_scope_depth', 3);
        $depth = 0;

        while ($frontier !== [] && $depth < $maxDepth) {
            $next = HrEmployee::query()
                ->whereIn('manager_employee_id', $frontier)
                ->pluck('id')
                ->merge(HrEmployeeManager::query()
                    ->whereIn('manager_employee_id', $frontier)
                    ->where('status', 'active')
                    ->pluck('employee_id'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->filter(fn (int $id) => ! in_array($id, $ids, true))
                ->values()
                ->all();

            if ($next === []) {
                break;
            }

            $ids = array_merge($ids, $next);
            $frontier = $next;
            $depth++;
        }

        return $this->memo[$key] = array_values(array_unique($ids));
    }

    /* ── Pemeriksaan per baris ──────────────────────────────────────────── */

    public function canViewEmployee(User $user, HrEmployee $employee): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        $ownId = $this->employeeIdForUser($user);

        if ($ownId !== null && (int) $employee->getKey() === $ownId) {
            return true;
        }

        $organizationIds = $this->visibleOrganizationIds($user);

        if ($organizationIds !== null && $employee->organization_id !== null
            && in_array((int) $employee->organization_id, $organizationIds, true)) {
            return true;
        }

        if ($this->scopeLevel($user) === self::LEVEL_TEAM
            && in_array((int) $employee->getKey(), $this->teamEmployeeIds($user), true)) {
            return true;
        }

        // HR unit boleh melihat pegawai yang penugasannya berada di unitnya,
        // meskipun kolom penempatan pegawai menunjuk unit lain.
        if ($organizationIds !== null && $organizationIds !== []
            && $employee->assignments()->whereIn('organization_id', $organizationIds)->where('status', 'active')->exists()) {
            return true;
        }

        return false;
    }

    public function canManageEmployee(User $user, HrEmployee $employee): bool
    {
        if (! $this->canManageEmployees($user)) {
            return false;
        }

        return $this->canViewEmployee($user, $employee);
    }

    public function canViewOrganization(User $user, int $organizationId): bool
    {
        $ids = $this->visibleOrganizationIds($user);

        return $ids === null || in_array($organizationId, $ids, true);
    }

    public function canManageOrganization(User $user, int $organizationId): bool
    {
        return $this->can($user, 'hr.organization.manage') && $this->canViewOrganization($user, $organizationId);
    }

    /**
     * Saring daftar pegawai sesuai cakupan pemanggil.
     *
     * Wajib dipakai setiap kueri daftar: pertahanan terakhir agar satu kueri yang
     * lupa menyaring tidak membocorkan seluruh data yayasan.
     */
    public function applyEmployeeScope(Builder $query, User $user): Builder
    {
        if ($this->isGlobal($user)) {
            return $query;
        }

        $ownId = $this->employeeIdForUser($user);
        $organizationIds = $this->visibleOrganizationIds($user) ?? [];

        return $query->where(function (Builder $inner) use ($ownId, $organizationIds, $user): void {
            if ($ownId !== null) {
                $inner->orWhere('hr_employees.id', $ownId);
            }

            if ($organizationIds !== []) {
                $inner->orWhereIn('hr_employees.organization_id', $organizationIds);
            }

            if ($this->scopeLevel($user) === self::LEVEL_TEAM) {
                $team = $this->teamEmployeeIds($user);

                if ($team !== []) {
                    $inner->orWhereIn('hr_employees.id', $team);
                }
            }
        });
    }

    /* ── Dokumen & kolom sensitif ───────────────────────────────────────── */

    /**
     * Tingkat kerahasiaan yang boleh dibaca pemanggil untuk seorang pegawai (§52).
     *
     * @return list<string>
     */
    public function readableVisibilityLevels(User $user, ?HrEmployee $target = null): array
    {
        $level = match (true) {
            $this->isGlobal($user) => DocumentVisibility::PRIVATE->value,
            $this->isHr($user) => DocumentVisibility::HR->value,
            default => $this->canViewTeamDocuments($user, $target)
                ? DocumentVisibility::MANAGER->value
                : DocumentVisibility::EMPLOYEE->value,
        };

        return DocumentVisibility::readableAt($level);
    }

    private function canViewTeamDocuments(User $user, ?HrEmployee $target): bool
    {
        if ($target === null) {
            return false;
        }

        return $this->can($user, 'hr.team.view')
            && in_array((int) $target->getKey(), $this->teamEmployeeIds($user), true);
    }

    /* ── Penegakan ──────────────────────────────────────────────────────── */

    public function assertCanView(User $user, HrEmployee $employee): void
    {
        if (! $this->canViewEmployee($user, $employee)) {
            throw HrdAccessDeniedException::forScope($this->scopeLevel($user), 'pegawai ini');
        }
    }

    public function assertCanManage(User $user, HrEmployee $employee): void
    {
        if (! $this->canManageEmployee($user, $employee)) {
            throw HrdAccessDeniedException::forScope($this->scopeLevel($user), 'perubahan data pegawai ini');
        }
    }

    public function assertCanViewOrganization(User $user, int $organizationId): void
    {
        if (! $this->canViewOrganization($user, $organizationId)) {
            throw HrdAccessDeniedException::forScope($this->scopeLevel($user), 'unit organisasi ini');
        }
    }

    public function assertSensitive(User $user): void
    {
        if (! $this->canViewSensitive($user)) {
            throw HrdAccessDeniedException::forField('data identitas sensitif');
        }
    }

    /** Dipakai pengujian & perintah artisan yang bekerja atas nama sistem. */
    public function flushMemo(): void
    {
        $this->memo = [];
    }
}
