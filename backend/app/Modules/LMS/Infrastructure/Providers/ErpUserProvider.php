<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Support\LmsActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Integrated Mode: identitas LMS dibaca dari `users` ERP (§6).
 *
 * Peran ERP (guru, siswa, wali-murid, …) dipetakan ke `ActorRole` di sini —
 * satu-satunya tempat yang tahu penamaan role milik ERP, sehingga service LMS
 * tidak perlu tahu bahwa "guru" bernama `guru` di database.
 */
class ErpUserProvider implements UserProviderInterface
{
    /** Peran ERP yang diperlakukan sebagai administrator LMS. */
    public const ADMIN_ROLES = [
        'super-admin', 'admin-sekolah', 'kepala-sekolah', 'admin-boarding', 'kepala-asrama',
        'finance-admin', 'library-manager', 'panitia-ppdb', 'bendahara', 'petugas-wallet',
    ];

    public const TEACHER_ROLES = ['guru', 'wali-kelas', 'musyrif', 'guru-tahfiz'];

    public const STUDENT_ROLES = ['siswa'];

    public const PARENT_ROLES = ['wali-murid'];

    public function mode(): string
    {
        return 'integrated';
    }

    public function isStandalone(): bool
    {
        return false;
    }

    public function fromRequest(Request $request): ?LmsActor
    {
        $user = $request->user();

        return $user instanceof User ? $this->toActor($user) : null;
    }

    public function find(int $id): ?LmsActor
    {
        $user = User::query()->find($id);

        return $user ? $this->toActor($user) : null;
    }

    public function attempt(string $login, string $password): ?LmsActor
    {
        $user = User::query()
            ->where(fn ($q) => $q->where('email', $login)->orWhere('username', $login))
            ->where('is_active', true)
            ->first();

        if ($user === null || ! Hash::check($password, (string) $user->password)) {
            return null;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->toActor($user);
    }

    public function issueToken(LmsActor $actor, string $deviceName): string
    {
        $user = User::query()->findOrFail($actor->id);

        return $user->createToken($deviceName)->plainTextToken;
    }

    public function revokeCurrentToken(Request $request): void
    {
        $request->user()?->currentAccessToken()?->delete();
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (User $user) => [$user->id => $this->toActor($user)])
            ->all();
    }

    public function toActor(User $user): LmsActor
    {
        $roles = $user->getRoleNames()->all();
        $permissions = $user->getAllPermissions()->pluck('name')->all();
        $userable = $user->userable;

        return new LmsActor(
            id: (int) $user->id,
            name: (string) $user->name,
            email: $user->email,
            avatarPath: $user->avatar_path,
            primaryRole: $this->resolveRole($roles, $userable),
            roles: $roles,
            permissions: $permissions,
            superAdmin: in_array('super-admin', $roles, true),
            standalone: false,
            employeeId: $userable instanceof Employee ? (int) $userable->id : null,
            studentId: $userable instanceof Student ? (int) $userable->id : null,
        );
    }

    /**
     * Urutan penentuan peran utama: role ERP lebih dulu, lalu jenis profil
     * (`userable`) sebagai cadangan — akun guru yang belum diberi role `guru`
     * tetap dikenali sebagai guru, bukan tamu.
     *
     * @param  list<string>  $roles
     */
    protected function resolveRole(array $roles, mixed $userable): ActorRole
    {
        if (array_intersect($roles, self::ADMIN_ROLES) !== []) {
            return ActorRole::ADMIN;
        }

        if (array_intersect($roles, self::TEACHER_ROLES) !== []) {
            return ActorRole::TEACHER;
        }

        if (array_intersect($roles, self::STUDENT_ROLES) !== []) {
            return ActorRole::STUDENT;
        }

        if (array_intersect($roles, self::PARENT_ROLES) !== []) {
            return ActorRole::PARENT;
        }

        return match (true) {
            $userable instanceof Employee => ActorRole::TEACHER,
            $userable instanceof Student => ActorRole::STUDENT,
            $userable instanceof Guardian => ActorRole::PARENT,
            default => ActorRole::GUEST,
        };
    }
}
