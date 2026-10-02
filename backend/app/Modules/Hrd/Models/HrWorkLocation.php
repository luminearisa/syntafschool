<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lokasi kerja (§7): kampus, sekolah, kantor, cabang, remote, asrama lapangan.
 * Satu pegawai dapat punya lokasi utama, lokasi sementara, dan beberapa lokasi
 * penugasan sekaligus — karena itu lokasi tidak disimpan sebagai teks di pegawai.
 */
class HrWorkLocation extends HrdModel
{
    use HasFactory;
    use SoftDeletes;

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'allows_remote' => 'boolean',
        'is_primary' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(HrDepartment::class, 'location_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(HrPosition::class, 'location_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(HrEmployee::class, 'location_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Lokasi virtual (remote/hybrid) tidak punya alamat fisik. */
    public function isVirtual(): bool
    {
        return in_array($this->location_type, ['remote', 'hybrid'], true);
    }
}
