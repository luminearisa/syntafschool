<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Models\Guardian;
use App\Models\User;
use App\Modules\Clinic\Domain\Contracts\ParentProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Relasi keluarga dari master ERP (`student_guardians` + tabel `users`).
 *
 * Dipakai dua arah: mencari akun wali untuk notifikasi, dan memverifikasi bahwa
 * sebuah akun memang wali sah dari seorang siswa (dasar portal orang tua).
 * Pemeriksaan selalu ke database — bukan dari klaim klien.
 */
class EloquentParentProvider implements ParentProviderInterface
{
    public function guardianUserIdsForStudent(int $studentId): array
    {
        $guardianIds = DB::table('student_guardians')
            ->where('student_id', $studentId)
            ->pluck('guardian_id');

        if ($guardianIds->isEmpty()) {
            return [];
        }

        $accountType = (new Guardian)->getMorphClass();

        return User::query()
            ->where('userable_type', $accountType)
            ->whereIn('userable_id', $guardianIds)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function guardiansForStudent(int $studentId): array
    {
        $rows = DB::table('student_guardians')
            ->join('guardians', 'guardians.id', '=', 'student_guardians.guardian_id')
            ->leftJoin('guardian_relations', 'guardian_relations.id', '=', 'student_guardians.guardian_relation_id')
            ->where('student_guardians.student_id', $studentId)
            ->orderByDesc('student_guardians.is_primary')
            ->get([
                'guardians.id',
                'guardians.full_name',
                'guardians.phone',
                'guardians.email',
                'student_guardians.is_primary',
                'guardian_relations.name as relation_name',
            ]);

        $accountType = (new Guardian)->getMorphClass();

        $accounts = User::query()
            ->where('userable_type', $accountType)
            ->whereIn('userable_id', $rows->pluck('id'))
            ->where('is_active', true)
            ->pluck('userable_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $rows->map(fn ($row): array => [
            'guardian_id' => (int) $row->id,
            'name' => (string) $row->full_name,
            'phone' => $row->phone,
            'email' => $row->email,
            'relation' => $row->relation_name ?? null,
            'is_primary' => (bool) $row->is_primary,
            'has_account' => in_array((int) $row->id, $accounts, true),
        ])->all();
    }

    public function studentIdsForGuardianUser(int $userId): array
    {
        $guardianId = $this->guardianIdForUser($userId);

        if ($guardianId === null) {
            return [];
        }

        return DB::table('student_guardians')
            ->where('guardian_id', $guardianId)
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function isGuardianOf(int $userId, int $studentId): bool
    {
        $guardianId = $this->guardianIdForUser($userId);

        if ($guardianId === null) {
            return false;
        }

        return DB::table('student_guardians')
            ->where('guardian_id', $guardianId)
            ->where('student_id', $studentId)
            ->exists();
    }

    private function guardianIdForUser(int $userId): ?int
    {
        $user = User::query()->find($userId);

        if ($user === null || $user->userable_type !== (new Guardian)->getMorphClass()) {
            return null;
        }

        return $user->userable_id === null ? null : (int) $user->userable_id;
    }
}
