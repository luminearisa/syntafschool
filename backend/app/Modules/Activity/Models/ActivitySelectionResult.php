<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\SelectionDecision;
use App\Modules\Activity\Domain\Enums\SelectionStage;

/**
 * Hasil satu tahap seleksi (tes coba, audisi, wawancara) beserta skornya.
 *
 * Tabel: `activity_selection_results`
 */
class ActivitySelectionResult extends ActivityModel
{
    protected $table = 'activity_selection_results';

    protected $casts = [
        'stage' => SelectionStage::class,
        'decision' => SelectionDecision::class,
        'score' => 'decimal:2',
        'criteria' => 'array',
        'decided_at' => 'datetime',
    ];
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ActivityRegistration::class, 'registration_id');
    }
    public function decider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'decided_by');
    }
}
