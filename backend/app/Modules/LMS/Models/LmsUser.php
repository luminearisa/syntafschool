<?php

namespace App\Modules\LMS\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Identitas LMS untuk Standalone Mode (§2).
 *
 * Hanya dipakai ketika `LMS_STANDALONE=true`. Pada Integrated Mode seluruh
 * autentikasi memakai `App\Models\User` yang sudah ada, dan tabel ini kosong.
 *
 * Autentikasi API tetap Sanctum (`HasApiTokens`) supaya kontrak HTTP tidak
 * berubah saat mode berganti — yang berbeda hanya sumber identitasnya.
 */
class LmsUser extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $table = 'lms_users';

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'phone',
        'avatar_path',
        'role',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function learners(): HasMany
    {
        return $this->hasMany(LmsLearner::class, 'lms_user_id');
    }

    public function guardianLinks(): HasMany
    {
        return $this->hasMany(LmsGuardian::class, 'lms_user_id');
    }
}
