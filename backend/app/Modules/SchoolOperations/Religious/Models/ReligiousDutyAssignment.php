<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_duty_assignments`.
 */
class ReligiousDutyAssignment extends Model
{
    protected $table = 'religious_duty_assignments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'roster_id' => 'integer',
        'duty_date' => 'date',
        'personnel_id' => 'integer',
        'mosque_id' => 'integer',
        'area_id' => 'integer',
        'substitute_for_id' => 'integer',
        'is_substitute' => 'boolean',
        'created_by' => 'integer',
        'meta' => 'array',
    ];

    public function roster(): BelongsTo
    {
        return $this->belongsTo(ReligiousDutyRoster::class, 'roster_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'personnel_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(MosqueArea::class, 'area_id');
    }

    public function religiousSubstitutions(): HasMany
    {
        return $this->hasMany(ReligiousSubstitution::class, 'assignment_id');
    }
}
