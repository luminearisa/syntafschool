<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan notifikasi wali (§45/§74) — dedupe per order+tipe.
 */
class LaundryNotification extends Model
{
    protected $table = 'laundry_notifications';

    protected $guarded = ['id'];

    protected $casts = [
        'recipients' => 'array',
        'payload' => 'array',
        'sent' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
