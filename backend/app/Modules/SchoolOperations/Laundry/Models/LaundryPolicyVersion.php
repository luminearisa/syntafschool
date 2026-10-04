<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versi policy (§5/§22): kuota, periode, carry-over, dan scope penerapan. Versi terbit tidak pernah diubah — perubahan melahirkan versi baru.
 */
class LaundryPolicyVersion extends Model
{
    protected $table = 'laundry_policy_versions';

    protected $guarded = ['id'];

    protected $casts = [
        'version' => 'integer',
        'quota_amount' => 'decimal:3',
        'carry_over_limit' => 'decimal:3',
        'max_free_weight_per_order' => 'decimal:3',
        'custom_period_start' => 'date',
        'custom_period_end' => 'date',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'priority' => 'integer',
        'published_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LaundryPolicy::class, 'laundry_policy_id');
    }
}
