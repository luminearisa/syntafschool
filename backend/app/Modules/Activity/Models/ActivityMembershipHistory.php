<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\MembershipAction;

/**
 * Jejak perubahan keanggotaan: penangguhan, pengaktifan, perubahan peran, pengunduran diri.
 *
 * Tabel: `activity_membership_histories`
 */
class ActivityMembershipHistory extends ActivityModel
{
    /**
     * Tabel riwayat append-only: hanya `created_at` yang dibutuhkan (diisi basis
     * data). Tanpa ini Eloquent akan menulis `updated_at` yang memang tidak ada
     * — dan baris riwayat tidak boleh punya arti "diperbarui".
     */
    public $timestamps = false;

    protected $table = 'activity_membership_histories';

    protected $casts = [
        'created_at' => 'datetime',
        'action' => MembershipAction::class,
        'metadata' => 'array',
    ];
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'membership_id');
    }
    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_user_id');
    }
}
