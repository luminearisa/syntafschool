<?php

namespace App\Modules\LMS\Domain\Support;

use App\Modules\LMS\Domain\Enums\ActorRole;

/**
 * Identitas pelaku LMS yang sudah dinormalisasi.
 *
 * Integrated Mode mengisinya dari `App\Models\User` + Spatie role/permission;
 * Standalone Mode dari `LmsUser`. Application layer hanya mengenal objek ini,
 * sehingga tidak ada satu pun service yang menyentuh model ERP secara langsung.
 */
final readonly class LmsActor
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email,
        public ?string $avatarPath,
        public ActorRole $primaryRole,
        public array $roles = [],
        public array $permissions = [],
        public bool $superAdmin = false,
        public bool $standalone = false,
        /** Tautan ke entity ERP bila ada (mis. employee_id) — dipakai untuk kepemilikan. */
        public ?int $employeeId = null,
        public ?int $studentId = null,
    ) {}

    public function can(string $permission): bool
    {
        if ($this->superAdmin || in_array('*', $this->permissions, true)) {
            return true;
        }

        return in_array($permission, $this->permissions, true);
    }

    /** @param list<string> $permissions */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string ...$roles): bool
    {
        foreach ($roles as $role) {
            if (in_array($role, $this->roles, true)) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->primaryRole === ActorRole::ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->primaryRole === ActorRole::TEACHER;
    }

    public function isStudent(): bool
    {
        return $this->primaryRole === ActorRole::STUDENT;
    }

    public function isParent(): bool
    {
        return $this->primaryRole === ActorRole::PARENT;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_path' => $this->avatarPath,
            'role' => $this->primaryRole->value,
            'role_label' => $this->primaryRole->label(),
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'is_super_admin' => $this->superAdmin,
            'mode' => $this->standalone ? 'standalone' : 'integrated',
            'employee_id' => $this->employeeId,
            'student_id' => $this->studentId,
        ];
    }
}
