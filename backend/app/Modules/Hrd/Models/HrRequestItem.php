<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rincian per kolom pada permintaan HR (§46): nilai lama → diminta → disetujui. */
class HrRequestItem extends HrdModel
{
    protected $casts = [
        'current_value' => 'array',
        'requested_value' => 'array',
        'approved_value' => 'array',
        'applied_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(HrRequest::class, 'request_id');
    }

    public function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '—');
    }
}
