<?php

namespace App\Modules\UKS\Application\Support;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;

/**
 * Identitas pemakai modul UKS, diturunkan dari akun yang masuk.
 *
 * Satu akun terhubung ke tepat satu entitas lewat relasi polimorfik
 * `users.userable`: siswa, pegawai (guru/staf), atau wali murid. Petugas UKS
 * adalah pegawai yang terdaftar di `uks_officers`.
 */
final class UksContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?Student $student,
        public readonly ?Employee $employee,
        public readonly ?Guardian $guardian,
    ) {
    }

    public static function for(User $user): self
    {
        $user->loadMissing('userable');
        $entity = $user->userable;

        return new self(
            user: $user,
            student: $entity instanceof Student ? $entity : null,
            employee: $entity instanceof Employee ? $entity : null,
            guardian: $entity instanceof Guardian ? $entity : null,
        );
    }

    public function isStudent(): bool
    {
        return $this->student !== null;
    }

    public function isGuardian(): bool
    {
        return $this->guardian !== null;
    }

    public function isEmployee(): bool
    {
        return $this->employee !== null;
    }
}
