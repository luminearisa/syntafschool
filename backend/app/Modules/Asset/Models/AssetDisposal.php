<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_disposals` — modul Asset & Sarpras.
 */
class AssetDisposal extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_disposals';

    protected $fillable = [
        'disposal_request_id',
        'asset_id',
        'method',
        'proceeds',
        'recipient',
        'executed_by',
        'executed_at',
        'finance_reference',
        'document_reference',
        'notes',
    ];

    protected $casts = [
        'method' => \App\Modules\Asset\Domain\Enums\DisposalMethod::class,
        'proceeds' => 'decimal:2',
        'executed_at' => 'datetime',
    ];
}
