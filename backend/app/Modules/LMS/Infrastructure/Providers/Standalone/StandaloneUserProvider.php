<?php

namespace App\Modules\LMS\Infrastructure\Providers\Standalone;

use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Standalone Mode: identitas LMS mandiri (§2).
 *
 * Dipakai ketika `LMS_STANDALONE=true`. Kontraknya identik dengan
 * `ErpUserProvider`, sehingga seluruh Application/Http layer tidak berubah —
 * yang berganti hanya sumber datanya.
 */
class StandaloneUserProvider implements UserProviderInterface
{
    /**
     * Hak akses bawaan per peran pada mode mandiri. Integrated Mode memakai
     * permission Spatie; di sini peta kecil ini menjaga agar middleware
     * `lms.permission:*` tetap bekerja tanpa RBAC ERP.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_PERMISSIONS = [
        ActorRole::ADMIN->value => ['*'],
        ActorRole::TEACHER->value => [
            'lms.view', 'lms.manage',
            'lms.category.view',
            'lms.course.view', 'lms.course.create', 'lms.course.update', 'lms.course.delete', 'lms.course.publish',
            'lms.content.view', 'lms.content.create', 'lms.content.update', 'lms.content.delete',
            'lms.enrollment.view', 'lms.enrollment.create', 'lms.enrollment.update', 'lms.enrollment.delete',
            'lms.group.view', 'lms.group.manage',
            'lms.announcement.view', 'lms.announcement.manage',
            'lms.student.view', 'lms.report.view', 'lms.teacher.manage',
        ],
        ActorRole::STUDENT->value => [
            'lms.view', 'lms.course.view', 'lms.content.view', 'lms.bookmark.manage',
        ],
        ActorRole::PARENT->value => [
            'lms.view', 'lms.course.view', 'lms.parent.view',
        ],
        ActorRole::GUEST->value => ['lms.view'],
    ];

    public function mode(): string
    {
        return 'standalone';
    }

    public function isStandalone(): bool
    {
        return true;
    }

    public function fromRequest(Request $request): ?LmsActor
    {
        $user = $request->user();

        return $user instanceof LmsUser ? $this->toActor($user) : null;
    }

    public function find(int $id): ?LmsActor
    {
        $user = LmsUser::query()->find($id);

        return $user ? $this->toActor($user) : null;
    }

    public function attempt(string $login, string $password): ?LmsActor
    {
        $user = LmsUser::query()
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
        $user = LmsUser::query()->findOrFail($actor->id);

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

        return LmsUser::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (LmsUser $user) => [$user->id => $this->toActor($user)])
            ->all();
    }

    public function toActor(LmsUser $user): LmsActor
    {
        $role = ActorRole::tryFrom((string) $user->role) ?? ActorRole::GUEST;

        return new LmsActor(
            id: (int) $user->id,
            name: (string) $user->name,
            email: $user->email,
            avatarPath: $user->avatar_path,
            primaryRole: $role,
            roles: [$role->value],
            permissions: self::ROLE_PERMISSIONS[$role->value] ?? [],
            superAdmin: $role === ActorRole::ADMIN,
            standalone: true,
            employeeId: null,
            studentId: null,
        );
    }
}
