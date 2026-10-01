<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Outbox integrasi lintas modul — niat yang belum dapat dikerjakan.
 */
class AssetIntegrationOutbox extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_integration_outbox';

    protected $fillable = [
        'module',
        'action',
        'subject_type',
        'subject_id',
        'payload',
        'status',
        'attempts',
        'last_error',
        'available_at',
        'dispatched_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];
}
