<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventBudget — tabel `event_budgets`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventBudget extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_budgets';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'fiscal_year_id' => 'integer',
            'total_planned' => 'decimal:2',
            'total_committed' => 'decimal:2',
            'total_actual' => 'decimal:2',
            'total_remaining' => 'decimal:2',
            'variance_threshold' => 'decimal:2',
            'variance_percent' => 'decimal:2',
            'submitted_by' => 'integer',
            'submitted_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(EventBudgetItem::class, 'budget_id');
    }
}
