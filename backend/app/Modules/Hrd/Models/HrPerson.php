<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Orang (§13). Dipisah dari pegawai supaya identitas kependudukan tidak hilang
 * ketika hubungan kerja berakhir, dan supaya satu orang dapat muncul sebagai
 * pegawai, wali murid, atau pelatih tanpa digandakan.
 */
class HrPerson extends HrdModel
{
    use HasFactory;
    use SoftDeletes;

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'extra' => 'array',
        'version' => 'integer',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(HrEmployee::class, 'person_id');
    }

    public function mergedInto()
    {
        return $this->belongsTo(self::class, 'merged_into_person_id');
    }

    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    /** Kunci pencarian orang: nama, NIK, telepon, surel. */
    public function scopeSearch($query, ?string $term)
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn ($q) => $q
            ->where('full_name', 'like', $like)
            ->orWhere('preferred_name', 'like', $like)
            ->orWhere('nik', 'like', $like)
            ->orWhere('mobile', 'like', $like)
            ->orWhere('phone', 'like', $like)
            ->orWhere('personal_email', 'like', $like)
            ->orWhere('work_email', 'like', $like));
    }
}
