<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventChecklistItem — tabel `event_checklist_items`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventChecklistItem extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_checklist_items';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checklist_id' => 'integer',
            'event_id' => 'integer',
            'is_mandatory' => 'boolean',
            'is_required_before_ready' => 'boolean',
            'responsible_id' => 'integer',
            'due_at' => 'datetime',
            'checked' => 'boolean',
            'checked_by' => 'integer',
            'checked_at' => 'datetime',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(EventChecklist::class, 'checklist_id');
    }
}
