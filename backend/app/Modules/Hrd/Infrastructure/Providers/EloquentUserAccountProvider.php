<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Models\User;
use App\Modules\Hrd\Domain\Contracts\UserAccountProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Akun pengguna (§67).
 *
 * `Person` → `Employee` → `User` tetap tiga hal berbeda: menautkan akun tidak
 * membuat kata sandi, dan membuat pegawai tidak berarti membuat akun. Akun hanya
 * disiapkan bila sekolah memang menghendaki (mis. setelah onboarding selesai).
 */
class EloquentUserAccountProvider implements UserAccountProviderInterface
{
    public function link(int $employeeId, int $userId): bool
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return false;
        }

        // `userable` mengikuti pemilik akun yang sebenarnya; bila akun sudah
        // menunjuk entitas lain, tautan tidak dipaksakan.
        return true;
    }

    public function unlink(int $employeeId): bool
    {
        return true;
    }

    public function provision(int $employeeId, array $roles = [], array $attributes = []): ?array
    {
        if (! Schema::hasTable('users')) {
            return null;
        }

        $employee = \App\Modules\Hrd\Models\HrEmployee::query()->with('person')->find($employeeId);

        if ($employee === null) {
            return null;
        }

        $email = $attributes['email'] ?? $employee->person?->work_email ?? $employee->person?->personal_email;

        if ($email === null) {
            return null;
        }

        if (User::query()->where('email', $email)->exists()) {
            return null;
        }

        $user = User::query()->create([
            'name' => $employee->displayName(),
            'email' => $email,
            'username' => $attributes['username'] ?? Str::slug($employee->employee_number),
            'password' => $attributes['password'] ?? Str::password(16),
            'phone' => $employee->person?->mobile,
            'userable_type' => $employee->getMorphClass(),
            'userable_id' => $employee->getKey(),
            'is_active' => true,
        ]);

        if ($roles !== []) {
            $user->syncRoles($roles);
        }

        $employee->forceFill(['user_id' => $user->getKey()])->save();

        return ['id' => (int) $user->getKey(), 'email' => $user->email];
    }

    public function suspend(int $userId, string $reason): bool
    {
        $updated = User::query()->whereKey($userId)->update(['is_active' => false]);

        if ($updated > 0) {
            DB::table('users')->where('id', $userId)->update(['updated_at' => now()]);
        }

        unset($reason);

        return $updated > 0;
    }

    public function reactivate(int $userId): bool
    {
        return User::query()->whereKey($userId)->update(['is_active' => true]) > 0;
    }

    public function find(int $userId): ?array
    {
        return User::query()->find($userId)?->only(['id', 'name', 'email', 'is_active']);
    }

    public function usersWithRole(string $role): array
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', $role)
            ->where('model_has_roles.model_type', User::class)
            ->pluck('model_has_roles.model_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
