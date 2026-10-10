<?php

namespace App\Services\Academic;

use App\Models\Guardian;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Mencari akun pengguna (User) milik wali/wali murid dari master ERP.
 *
 * Sebagian wali terdaftar tanpa akun. Tautan akun dicari lewat kolom
 * polimorfik `users.userable` (paling dipercaya), lalu kolom `guardians.user_id`
 * bila tersedia, terakhir pencocokan email. Logika ini mencerminkan
 * `EloquentParentProvider` modul Activity, tetapi dimiliki modul akademik agar
 * tidak bergantung lintas modul.
 */
class GuardianUserResolver
{
    /** @return list<int> */
    public function userIdsFor(int $studentId): array
    {
        $guardianIds = StudentGuardian::query()
            ->where('student_id', $studentId)
            ->pluck('guardian_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $userIds = [];
        foreach ($guardianIds as $guardianId) {
            $userId = $this->userIdOf((int) $guardianId);
            if ($userId !== null) {
                $userIds[] = $userId;
            }
        }

        return array_values(array_unique($userIds));
    }

    protected function userIdOf(int $guardianId): ?int
    {
        // Akun wali yang tertaut lewat `users.userable` lebih dipercaya daripada
        // pencocokan email (email dapat sama-sama kosong atau berubah).
        $linkedUserId = User::query()
            ->where('userable_type', (new Guardian)->getMorphClass())
            ->where('userable_id', $guardianId)
            ->value('id');

        if ($linkedUserId !== null) {
            return (int) $linkedUserId;
        }

        $guardian = Guardian::query()->find($guardianId);

        if ($guardian === null) {
            return null;
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
            $has = Schema::hasColumn('guardians', 'user_id');
        }

        return $has;
    }
}
