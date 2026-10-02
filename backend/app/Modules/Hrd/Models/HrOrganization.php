<?php

namespace App\Modules\Hrd\Models;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Unit organisasi berjenjang (§4, §5).
 *
 * Satu tabel untuk yayasan, sekolah, pesantren, divisi, dan departemen. `path`
 * menyimpan materialized path (`1/4/9/`) sehingga sub-pohon dapat dibaca dengan
 * satu kueri, dan `depth` memudahkan pembatasan kedalaman bagan organisasi.
 */
class HrOrganization extends HrdModel
{
    use HasFactory;
    use SoftDeletes;

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'version' => 'integer',
        'authorized_headcount' => 'integer',
        'depth' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(self::class, 'legal_entity_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'manager_employee_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(HrDepartment::class, 'organization_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(HrPosition::class, 'organization_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(HrWorkLocation::class, 'organization_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(HrEmployee::class, 'organization_id');
    }

    public function referenceItem(): BelongsTo
    {
        return $this->belongsTo(HrReferenceItem::class, 'type_code', 'code')
            ->where('group_code', 'organization_type');
    }

    /** Semua keturunan (sub-pohon) berdasarkan materialized path. */
    public function descendants(): Collection
    {
        if (blank($this->path)) {
            return collect();
        }

        return static::query()->where('path', 'like', $this->path.'%')->whereKeyNot($this->getKey())->get();
    }

    public function isAncestorOf(HrOrganization $other): bool
    {
        return filled($this->path) && str_starts_with((string) $other->path, (string) $this->path);
    }

    /** Organisasi aktif; penyaring default hampir setiap laporan. */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    public function headcountAuthorized(): int
    {
        return (int) ($this->authorized_headcount ?? 0);
    }
}
