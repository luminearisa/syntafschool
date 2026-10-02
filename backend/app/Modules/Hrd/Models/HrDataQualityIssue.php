<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Temuan kualitas data (§56).
 *
 * `fingerprint` mencegah temuan yang sama menumpuk setiap pemindaian: satu
 * masalah = satu baris yang dapat ditindaklanjuti, dengan riwayat kapan
 * ditemukan dan kapan selesai — bukan daftar yang tumbuh setiap malam.
 */
class HrDataQualityIssue extends HrdModel
{
    protected $casts = [
        'details' => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    public function scan(): BelongsTo
    {
        return $this->belongsTo(HrDataQualityScan::class, 'scan_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(HrValidationRule::class, 'rule_code', 'code');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED]);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', 'critical');
    }
}
