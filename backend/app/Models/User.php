<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

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
