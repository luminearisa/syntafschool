<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `khutbah_categories`.
 */
class KhutbahCategory extends Model
{
    protected $table = 'khutbah_categories';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'sort_order' => 'integer',
        'active' => 'boolean',
    ];

    public function khutbahs(): HasMany
    {
        return $this->hasMany(Khutbah::class, 'category_id');
    }
}
