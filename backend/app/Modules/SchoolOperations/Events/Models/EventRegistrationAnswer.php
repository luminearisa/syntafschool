<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventRegistrationAnswer — tabel `event_registration_answers`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventRegistrationAnswer extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_registration_answers';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'registration_id' => 'integer',
            'event_id' => 'integer',
            'field_id' => 'integer',
            'value_number' => 'decimal:2',
            'value_date' => 'date',
            'value_json' => 'array',
            'file_document_id' => 'integer',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(EventRegistrationField::class, 'field_id');
    }
}
