<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris berkas impor (§63).
 *
 * Kesalahan disimpan terstruktur — `field`, `value`, `error`, `suggestion` —
 * supaya laporan kesalahan dapat dibaca operator data, bukan hanya developer.
 */
class HrEmployeeImportRow extends HrdModel
{
    protected $casts = [
        'raw' => 'array',
        'normalized' => 'array',
        'errors' => 'array',
        'row_number' => 'integer',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeImport::class, 'import_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function hasErrors(): bool
    {
        return $this->status === 'invalid' || $this->status === 'failed';
    }
}
