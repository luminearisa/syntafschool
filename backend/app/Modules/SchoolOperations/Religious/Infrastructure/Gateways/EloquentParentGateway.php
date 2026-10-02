<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\ParentGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Wali murid (§14). Menjaga isolasi data: wali hanya melihat anaknya.
 */
class EloquentParentGateway implements ParentGatewayInterface
{
    use GatewaySupport;

    protected function pivot(): ?string
    {
        return $this->firstTable(['student_guardians', 'guardians', 'student_parents']);
    }

    public function childrenFor(int $parentUserId): array
    {
        $pivot = $this->pivot();
        if ($pivot === null) {
            return [];
        }

        $columns = $this->columns($pivot);
        $userColumn = $this->pick($columns, ['user_id', 'guardian_user_id', 'parent_user_id']);
        if ($userColumn === null || ! in_array('student_id', $columns, true)) {
            return [];
        }

        $ids = DB::table($pivot)->where($userColumn, $parentUserId)->pluck('student_id')->all();

        $gateway = $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\ParentProviderInterface');

        return array_values(array_filter(array_map(fn ($id): ?array => [
            'id' => (int) $id,
        ], $ids)));
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
        $pivot = $this->pivot();
        if ($pivot === null) {
            return false;
        }

        $columns = $this->columns($pivot);
        $userColumn = $this->pick($columns, ['user_id', 'guardian_user_id', 'parent_user_id']);
        if ($userColumn === null) {
            return false;
        }

        return DB::table($pivot)->where($userColumn, $parentUserId)
            ->where('student_id', $studentId)->exists();
    }

    public function available(): bool
    {
        return $this->pivot() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Tabel relasi wali-siswa tidak tersedia pada instalasi ini.';
    }
}
