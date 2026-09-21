<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nomor klasifikasi di dalam sebuah sistem, dapat bertingkat (spesifikasi §13).
 *
 * Contoh: DDC → 200 Religion → 297 Islam.
 */
class LibraryClassification extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = [
        'level' => 'integer',
        'sort_order' => 'integer',
    ];

    public function system(): BelongsTo
    {
        return $this->belongsTo(LibraryClassificationSystem::class, 'system_id');
    }
}