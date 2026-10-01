<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `campuses` — modul Asset & Sarpras.
 */
class Campus extends Model
{
    use BelongsToSchool;

    protected $table = 'campuses';

    protected $fillable = [
        'code',
        'name',
        'address',
        'city',
        'province',
        'postal_code',
        'phone',
        'email',
        'head_name',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function buildings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function facilities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Facility::class);
    }

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\OperationalStatus::class,
    ];
}
