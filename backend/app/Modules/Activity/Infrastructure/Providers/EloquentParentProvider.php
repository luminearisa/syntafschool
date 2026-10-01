<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\Guardian;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Modules\Activity\Domain\Contracts\ParentProviderInterface;

/**
 * Wali siswa dari master ERP.
 *
 * Tautan wali ke akun pengguna tidak selalu ada di basis data (sebagian sekolah
 * memasukkan wali tanpa akun). Karena itu tautan dicari lewat `user_id` pada
 * tabel wali bila kolomnya tersedia, lalu jatuh ke pencocokan email — dan bila
 * tidak ditemukan, wali tetap dicatat sebagai penerima persetujuan non-akun.
 */
class EloquentParentProvider implements ParentProviderInterface
{
    public function guardiansOf(int $studentId): array
    {
        return StudentGuardian::query()
            ->with(['guardian:id,full_name,email,phone', 'relation:id,name'])
            ->where('student_id', $studentId)
            ->get()
            ->map(fn (StudentGuardian $link) => [
                'guardian_id' => (int) $link->guardian_id,
                'name' => (string) ($link->guardian?->full_name ?? '-'),
                'email' => $link->guardian?->email,
                'phone' => $link->guardian?->phone,
                'relation' => $link->relation?->name,
                'is_primary' => (bool) $link->is_primary,
                'user_id' => $this->userIdOf($link->guardian_id),
            ])
            ->all();
    }

    public function userIdsFor(int $studentId): array
    {
        return array_values(array_filter(array_map(
            fn (array $guardian) => $guardian['user_id'],
            $this->guardiansOf($studentId),
        )));
    }

    public function childrenOfUser(int $userId): array
    {
        $guardianIds = $this->guardianIdsOfUser($userId);

        if ($guardianIds === []) {
            return [];
        }

        return StudentGuardian::query()
            ->whereIn('guardian_id', $guardianIds)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function canActFor(int $userId, int $studentId): bool
    {
        return in_array($studentId, $this->childrenOfUser($userId), true);
    }

    /** @return list<int> */
    protected function guardianIdsOfUser(int $userId): array
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return [];
        }

        // Tautan utama di ERP ini adalah kolom polimorfik `users.userable`:
        // akun wali menunjuk langsung ke baris `guardians`.
        if ($user->userable_type === (new Guardian)->getMorphClass() && $user->userable_id !== null) {
            return [(int) $user->userable_id];
        }

        $ids = Guardian::query()
            ->when($this->guardiansHaveUserId(), fn ($query) => $query->where('user_id', $userId))
            ->when(! $this->guardiansHaveUserId() && $user->email !== null, fn ($query) => $query->where('email', $user->email))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $ids;
    }

    protected function userIdOf(?int $guardianId): ?int
    {
        if ($guardianId === null) {
            return null;
        }

        $guardian = Guardian::query()->find($guardianId);

        if ($guardian === null) {
            return null;
        }

        // Akun wali yang tertaut lewat `users.userable` lebih dipercaya daripada
        // pencocokan email (email dapat sama-sama kosong atau berubah).
        $linkedUserId = User::query()
            ->where('userable_type', (new Guardian)->getMorphClass())
            ->where('userable_id', $guardianId)
            ->value('id');

        if ($linkedUserId !== null) {
            return (int) $linkedUserId;
        }

        if ($this->guardiansHaveUserId() && $guardian->user_id !== null) {
            return (int) $guardian->user_id;
        }

        if ($guardian->email === null) {
            return null;
        }

        $userId = User::query()->where('email', $guardian->email)->value('id');

        return $userId === null ? null : (int) $userId;
    }

    protected function guardiansHaveUserId(): bool
    {
        static $has = null;

        if ($has === null) {
            $has = \Illuminate\Support\Facades\Schema::hasColumn('guardians', 'user_id');
        }

        return $has;
    }
}
