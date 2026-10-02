<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventClosing — tabel `event_closings`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventClosing extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_closings';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'attendance_finalized' => 'boolean',
            'participants_finalized' => 'boolean',
            'finance_reconciled' => 'boolean',
            'assets_returned' => 'boolean',
            'inventory_reconciled' => 'boolean',
            'catering_reconciled' => 'boolean',
            'transportation_closed' => 'boolean',
            'incidents_reviewed' => 'boolean',
            'documents_archived' => 'boolean',
            'media_uploaded' => 'boolean',
            'certificates_processed' => 'boolean',
            'survey_completed' => 'boolean',
            'report_generated' => 'boolean',
            'checklist_completed' => 'integer',
            'checklist_total' => 'integer',
            'open_incident_count' => 'integer',
            'critical_incident_count' => 'integer',
            'override_used' => 'boolean',
            'override_by' => 'integer',
            'finalized_attendance' => 'integer',
            'final_participant_count' => 'integer',
            'budget_planned' => 'decimal:2',
            'budget_actual' => 'decimal:2',
            'variance' => 'decimal:2',
            'closed_by' => 'integer',
            'closed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function closings(): HasMany
    {
        return $this->hasMany(EventClosingItem::class, 'closing_id');
    }
}
