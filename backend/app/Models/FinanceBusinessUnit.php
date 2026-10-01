<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unit bisnis (pusat laba).
 *
 * Unit dipakai untuk memisahkan laba rugi & arus kas per satuan usaha
 * (sekolah, asrama, koperasi, unit lain) tanpa memecah neraca: neraca hanya
 * bermakna pada tingkat yayasan, sehingga baris kas/bank & liabilitas selalu
 * memakai unit penampung.
 *
 * Tabel: `finance_business_units`
 */
class FinanceBusinessUnit extends BaseModel
{
    protected $table = 'finance_business_units';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'parent_code', 'level', 'is_active', 'description'];

    protected $casts = ['level' => 'integer', 'is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_code', 'code');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_code', 'code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
