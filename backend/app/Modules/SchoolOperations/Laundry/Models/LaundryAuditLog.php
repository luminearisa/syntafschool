<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;

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
}
