<?php

namespace App\Modules\Bk\Models;

use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;

/**
 * Jejak tindakan otomasi.
 *
 * Tabel ini adalah bukti bahwa otomasi berjalan — dan buktinya justru yang membuat
 * otomasi aman: setiap tindakan punya kunci unik, sehingga tindakan yang sama pada
 * periode yang sama tidak pernah dijalankan dua kali.
 *
 * Tabel: `bk_automation_runs`
 */
class BkAutomationRun extends BkModel
{
    use BelongsToSchool;

    protected $table = 'bk_automation_runs';

    protected $casts = [
        'ran_at' => 'datetime',
        'recipients' => 'integer',
        'payload' => 'array',
    ];

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'FAILED');
    }
}
