<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Impor massal pegawai (§63).
 *
 * Alur yang dipaksakan: unggah → petakan kolom → pratinjau → validasi → konfirmasi.
 * Berkas mentah tidak pernah langsung masuk basis data; setiap baris punya
 * statusnya sendiri dan berkas kesalahan dapat diunduh untuk diperbaiki.
 */
class HrEmployeeImport extends HrdModel
{
    protected $casts = [
        'mapping' => 'array',
        'summary' => 'array',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'invalid_rows' => 'integer',
        'imported_rows' => 'integer',
        'validated_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_MAPPED = 'mapped';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function rows(): HasMany
    {
        return $this->hasMany(HrEmployeeImportRow::class, 'import_id')->orderBy('row_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canConfirm(): bool
    {
        return $this->status === self::STATUS_VALIDATED && $this->valid_rows > 0;
    }
}
