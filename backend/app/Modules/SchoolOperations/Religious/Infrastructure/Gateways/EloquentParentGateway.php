<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Models\User;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\ParentGatewayInterface;
use App\Services\Mobile\ContextService;
use Illuminate\Support\Facades\DB;

/**
 * Wali murid (§14). Menjaga isolasi data: wali hanya melihat anaknya.
 */
class EloquentParentGateway implements ParentGatewayInterface
{
    use GatewaySupport;

    protected function pivot(): ?string
    {
        return $this->firstTable(['student_guardians', 'student_parents']);
    }

    public function childrenFor(int $parentUserId): array
    {
        $studentIds = $this->studentIdsForParent($parentUserId);

        return array_map(fn (int $id): array => ['id' => $id], $studentIds);
    }

    public function guardiansFor(int $studentId): array
    {
        $pivot = $this->pivot();
        if ($pivot === null || ! in_array('student_id', $this->columns($pivot), true)) {
            return [];
        }

        return array_map(fn ($row): array => (array) $row,
            DB::table($pivot)->where('student_id', $studentId)->get()->all());
    }

    public function canAccess(int $parentUserId, int $studentId): bool
    {
        return in_array($studentId, $this->studentIdsForParent($parentUserId), true);
    }

    public function available(): bool
    {
        return $this->pivot() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Tabel relasi wali-siswa tidak tersedia pada instalasi ini.';
    }

    /**
     * Relasi ERP memakai student_guardians.guardian_id, bukan user_id.
     * Identitas Guardian dicari lewat ContextService agar mendukung tautan
     * users.userable, user_identities, dan ppdb_parent_accounts dengan konsisten.
     * Skema alternatif yang memang menyimpan user_id tetap didukung.
     *
     * @return list<int>
     */
    protected function studentIdsForParent(int $parentUserId): array
    {
        $pivot = $this->pivot();
        if ($pivot === null) {
            return [];
        }

        $columns = $this->columns($pivot);
        if (! in_array('student_id', $columns, true)) {
            return [];
        }

        $userColumn = $this->pick($columns, ['user_id', 'guardian_user_id', 'parent_user_id']);
        if ($userColumn !== null) {
            return DB::table($pivot)->where($userColumn, $parentUserId)
                ->pluck('student_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        }

        if (! in_array('guardian_id', $columns, true) || ! $this->has('users')) {
            return [];
        }

        $user = User::query()->find($parentUserId);
        if (! $user) {
            return [];
        }

        $guardianIds = app(ContextService::class)->identityIds($user, ContextService::GUARDIAN);
        if ($guardianIds === []) {
            return [];
        }

        return DB::table($pivot)->whereIn('guardian_id', $guardianIds)
            ->pluck('student_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }
}
