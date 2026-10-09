<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit modul (§75): actor, action, before/after, reason, reference.
 */
class LaundryAuditLog extends Model
{
    protected $table = 'laundry_audit_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    /** Pelaku aksi — dimuat oleh LaundryAuditService::query() (`with('actor:id,name')`). */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
