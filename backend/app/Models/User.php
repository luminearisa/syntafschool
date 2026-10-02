<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Support\ContextPermissions;
use App\Support\ContextState;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use HasRoles {
        getRoleNames as protected baseGetRoleNames;
        hasRole as protected baseHasRole;
        getAllPermissions as protected baseGetAllPermissions;
        hasPermissionTo as protected baseHasPermissionTo;
    }

    /**
     * Guard yang dipakai untuk seluruh pemeriksaan role & permission.
     *
     * Aplikasi hanya memakai satu guard ('web') untuk RBAC, sementara
     * autentikasi API memakai guard 'sanctum'. Tanpa pemaksaan ini,
     * setelah middleware 'auth:sanctum' berjalan, Laravel menetapkan
     * guard default ke 'sanctum' sehingga pencarian permission gagal
     * (Spatie memakai guard default untuk resolve role/permission).
     *
     * Override ini dipakai oleh hasPermissionTo, hasRole, hasAnyPermission,
     * filterPermission, hasDirectPermission, dan Gate::can secara otomatis.
     */
    protected function getDefaultGuardName(): string
    {
        return 'web';
    }

    protected static function booted(): void
    {
        // Peran aktif token mobile: samakan `userable` di memori (tak pernah disimpan).
        static::retrieved(fn (User $user) => ContextState::apply($user));
    }

    // ---- Role & permission per peran aktif (token mobile ctx:*) ------------

    public function getRoleNames(): Collection
    {
        $ctx = ContextState::typeFor($this->getKey());

        return $ctx === null ? $this->baseGetRoleNames() : ContextPermissions::roleNames($this, $ctx);
    }

    public function hasRole($roles, ?string $guard = null): bool
    {
        $ctx = ContextState::typeFor($this->getKey());

        if ($ctx === null) {
            return $this->baseHasRole($roles, $guard);
        }

        $wanted = collect(is_array($roles) || $roles instanceof Collection ? $roles : [$roles])
            ->map(fn ($r) => is_object($r) && isset($r->name) ? $r->name : (string) $r);

        return ContextPermissions::roleNames($this, $ctx)->intersect($wanted)->isNotEmpty();
    }

    public function getAllPermissions(): Collection
    {
        $ctx = ContextState::typeFor($this->getKey());

        return $ctx === null ? $this->baseGetAllPermissions() : ContextPermissions::permissionModels($this, $ctx);
    }

    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $ctx = ContextState::typeFor($this->getKey());

        if ($ctx === null) {
            return $this->baseHasPermissionTo($permission, $guardName);
        }

        $name = is_object($permission) && isset($permission->name) ? $permission->name : $permission;

        if (is_int($name)) {
            $name = \Spatie\Permission\Models\Permission::query()->whereKey($name)->value('name');
        }

        return is_string($name) && ContextPermissions::permissionNames($this, $ctx)->contains($name);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone',
        'avatar_path',
        'userable_type',
        'userable_id',
        'is_active',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Relasi polymorphic ke Student / Employee.
     */
    public function userable(): MorphTo
    {
        return $this->morphTo();
    }
}
