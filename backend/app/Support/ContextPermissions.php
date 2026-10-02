<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role & permission efektif sebuah akun pada peran aktifnya.
 *
 * Satu akun dapat memegang role `guru` dan `wali-murid` sekaligus. Tanpa
 * pemisahan ini, mode wali lolos permission pegawai dan sebaliknya.
 *  - wali   → hanya permission role `wali-murid`
 *  - siswa  → hanya permission role `siswa`
 *  - pegawai→ semua role akun KECUALI `wali-murid` dan `siswa` (+ izin langsung)
 *
 * Role `wali-murid`/`siswa` dipakai walau belum menempel pada akun: yang
 * menentukan adalah peran aktif, bukan role yang kebetulan ada.
 */
final class ContextPermissions
{
    public const FAMILY_ROLES = ['guardian' => 'wali-murid', 'student' => 'siswa'];

    /** @var array<string, Collection<int, string>> */
    private static array $roleCache = [];

    /** @var array<string, Collection<int, string>> */
    private static array $permCache = [];

    public static function flush(): void
    {
        self::$roleCache = self::$permCache = [];
    }

    /** @return Collection<int, string> */
    public static function roleNames(User $user, string $context): Collection
    {
        $key = $user->getKey().':'.$context;

        return self::$roleCache[$key] ??= match ($context) {
            'guardian', 'student' => collect([self::FAMILY_ROLES[$context]]),
            default => $user->roles()->pluck('name')
                ->reject(fn ($n) => in_array($n, self::FAMILY_ROLES, true))->values(),
        };
    }

    /** @return Collection<int, string> */
    public static function permissionNames(User $user, string $context): Collection
    {
        $key = $user->getKey().':'.$context;

        if (isset(self::$permCache[$key])) {
            return self::$permCache[$key];
        }

        $names = self::roleNames($user, $context)
            ->flatMap(fn (string $role) => Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->permissions()->pluck('name') ?? collect())
            ->all();

        if ($context === 'employee') {
            $names = array_merge($names, $user->permissions()->pluck('name')->all());
        }

        return self::$permCache[$key] = collect($names)->unique()->values();
    }

    /** @return Collection<int, Permission> */
    public static function permissionModels(User $user, string $context): Collection
    {
        return Permission::query()->where('guard_name', 'web')
            ->whereIn('name', self::permissionNames($user, $context)->all())->get();
    }
}
