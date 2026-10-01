<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `facility_attribute_values` — modul Asset & Sarpras.
 */
class FacilityAttributeValue extends Model
{
    protected $table = 'facility_attribute_values';

    protected $fillable = [
        'facility_id',
        'attribute_id',
        'value',
    ];
}
