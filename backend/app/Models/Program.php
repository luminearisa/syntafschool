<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Program / jurusan / peminatan (IPA, IPS, Bahasa).
 */
class Program extends BaseModel
{
    use SoftDeletes;

    protected $casts = ['is_active' => 'boolean'];

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
