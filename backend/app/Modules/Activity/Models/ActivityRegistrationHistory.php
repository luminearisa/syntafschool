<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Jejak perubahan status pendaftaran (append-only).
 *
 * Tabel: `activity_registration_histories`
 */
class ActivityRegistrationHistory extends ActivityModel
{
    /**
     * Tabel riwayat append-only: hanya `created_at` yang dibutuhkan (diisi basis
     * data). Tanpa ini Eloquent akan menulis `updated_at` yang memang tidak ada
     * — dan baris riwayat tidak boleh punya arti "diperbarui".
     */
    public $timestamps = false;

    protected $table = 'activity_registration_histories';

    protected $casts = [
        'created_at' => 'datetime',
    ];
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ActivityRegistration::class, 'registration_id');
    }
    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_user_id');
    }
}
