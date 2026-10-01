<?php

namespace App\Services\Mobile;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\PpdbParentAccount;
use App\Models\Student;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\ContextState;
use Illuminate\Support\Facades\DB;

/**
 * Menentukan "peran" (konteks) yang boleh dipakai sebuah akun di aplikasi
 * mobile: pegawai, wali murid, atau siswa.
 *
 * Satu akun dapat memegang beberapa identitas. Sumbernya — semuanya tautan
 * eksplisit, tidak pernah tebakan nama/telepon:
 *  1. `users.userable` (tautan lama, satu entitas)
 *  2. `user_identities` (tautan tambahan yang dibuat admin)
 *  3. `ppdb_parent_accounts.guardian_id` (akun orang tua dari alur PPDB)
 */
class ContextService
{
    public const EMPLOYEE = 'employee';

    public const GUARDIAN = 'guardian';

    public const STUDENT = 'student';

    public const TYPES = [self::EMPLOYEE, self::GUARDIAN, self::STUDENT];

    /** @return list<int> */
    public function identityIds(User $user, string $type): array
    {
        $ids = [];

        $class = $this->modelClass($type);

        // Dibaca langsung dari DB: objek User bisa saja sudah ditimpa peran aktif
        // (lihat ContextState), sedangkan daftar identitas harus utuh.
        $real = DB::table('users')->where('id', $user->getKey())->first(['userable_type', 'userable_id']);
        if ($real && $real->userable_type === (new $class)->getMorphClass() && $real->userable_id !== null) {
            $ids[] = (int) $real->userable_id;
        }

        $ids = array_merge($ids, UserIdentity::query()
            ->where('user_id', $user->id)
            ->where('identity_type', $type)
            ->pluck('identity_id')
            ->map(fn ($id) => (int) $id)
            ->all());

        if ($type === self::GUARDIAN) {
            $ids = array_merge($ids, PpdbParentAccount::query()
                ->where('user_id', $user->id)
                ->whereNotNull('guardian_id')
                ->pluck('guardian_id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        $ids = array_values(array_unique($ids));

        // Hanya identitas yang masih ada (belum dihapus) yang dihitung.
        return $ids === [] ? [] : $class::query()->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function employeeId(User $user): ?int
    {
        return $this->identityIds($user, self::EMPLOYEE)[0] ?? null;
    }

    public function studentId(User $user): ?int
    {
        return $this->identityIds($user, self::STUDENT)[0] ?? null;
    }

    /**
     * Anak-anak akun ini. Pada token berperan non-wali (siswa/pegawai) hasilnya
     * kosong — sumber tunggal untuk Keuangan, Asrama, dan portal lain.
     *
     * @return list<int>
     */
    public function childStudentIds(User $user): array
    {
        $active = ContextState::typeFor($user->getKey());
        if ($active !== null && $active !== self::GUARDIAN) {
            return [];
        }

        $guardianIds = $this->identityIds($user, self::GUARDIAN);

        if ($guardianIds === []) {
            return [];
        }

        return DB::table('student_guardians')
            ->whereIn('guardian_id', $guardianIds)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Daftar konteks yang tersedia untuk akun ini.
     *
     * @return list<array<string, mixed>>
     */
    public function contexts(User $user): array
    {
        $contexts = [];

        if ($employeeId = $this->employeeId($user)) {
            $employee = Employee::query()->find($employeeId);
            $contexts[] = [
                'type' => self::EMPLOYEE,
                'id' => $employeeId,
                'label' => 'Karyawan',
                'subtitle' => $employee?->full_name,
            ];
        }

        $children = $this->childStudentIds($user);
        if ($this->identityIds($user, self::GUARDIAN) !== []) {
            $contexts[] = [
                'type' => self::GUARDIAN,
                'id' => $this->identityIds($user, self::GUARDIAN)[0],
                'label' => 'Wali Murid',
                'subtitle' => count($children).' anak',
            ];
        }

        if ($studentId = $this->studentId($user)) {
            $student = Student::query()->find($studentId);
            $contexts[] = [
                'type' => self::STUDENT,
                'id' => $studentId,
                'label' => 'Siswa',
                'subtitle' => $student?->full_name,
            ];
        }

        return $contexts;
    }

    public function has(User $user, string $type): bool
    {
        return collect($this->contexts($user))->contains('type', $type);
    }

    /** Ability token untuk sebuah konteks. */
    public static function ability(string $type): string
    {
        return 'ctx:'.$type;
    }

    /** @return class-string */
    private function modelClass(string $type): string
    {
        return match ($type) {
            self::EMPLOYEE => Employee::class,
            self::GUARDIAN => Guardian::class,
            self::STUDENT => Student::class,
        };
    }
}
