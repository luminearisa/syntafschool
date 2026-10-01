<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `facility_attributes` — modul Asset & Sarpras.
 */
class FacilityAttribute extends Model
{
    protected $table = 'facility_attributes';

    protected $fillable = [
        'facility_type_id',
        'code',
        'label',
        'data_type',
        'unit',
        'options',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
    ];
}
