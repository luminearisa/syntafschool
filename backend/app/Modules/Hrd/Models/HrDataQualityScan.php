<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali pemindaian kualitas data (§56).
 *
 * Skornya disimpan, bukan dihitung ulang setiap kali layar dibuka: yang penting
 * bagi manajemen adalah *tren* ("skor naik dari 72 ke 86 setelah pembersihan"),
 * dan tren menuntut riwayat.
 */
class HrDataQualityScan extends HrdModel
{
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'score' => 'integer',
        'totals' => 'array',
    ];

    public function issues(): HasMany
    {
        return $this->hasMany(HrDataQualityIssue::class, 'scan_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
