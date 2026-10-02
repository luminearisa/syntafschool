<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;

class TransportationAuditLog extends Model
{

    protected $table = 'transportation_audit_logs';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    /** Tabel audit hanya memiliki created_at. */
    public $timestamps = false;

    protected $casts = [
        'auditable_id' => 'integer',
        'user_id' => 'integer',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

}
