<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventProposal — tabel `event_proposals`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventProposal extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_proposals';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'expected_participants' => 'integer',
            'proposed_date' => 'date',
            'proposed_end_date' => 'date',
            'estimated_budget' => 'decimal:2',
            'organizer_id' => 'integer',
            'proposal_document_id' => 'integer',
            'submitted_by' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'converted_event_id' => 'integer',
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

    public function proposals(): HasMany
    {
        return $this->hasMany(EventApproval::class, 'proposal_id');
    }
}
