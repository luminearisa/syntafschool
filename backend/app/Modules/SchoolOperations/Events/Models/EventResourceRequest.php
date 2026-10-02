<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventResourceRequest — tabel `event_resource_requests`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventResourceRequest extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_resource_requests';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'resource_id' => 'integer',
            'quantity' => 'integer',
            'required_from' => 'datetime',
            'required_until' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'returned_at' => 'datetime',
            'cost' => 'decimal:2',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
