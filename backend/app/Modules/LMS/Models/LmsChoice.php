<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ChoiceResultsVisibility;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Polling sederhana (§38). */
class LmsChoice extends BaseModel
{
    protected $table = 'lms_choices';

    protected $casts = [
        'allow_multiple' => 'boolean',
        'limit_responses' => 'integer',
        'results_visibility' => ChoiceResultsVisibility::class,
        'anonymous' => 'boolean',
        'show_available_choices' => 'boolean',
        'settings' => 'array',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(LmsChoiceOption::class, 'choice_id')->orderBy('sort_order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LmsChoiceResponse::class, 'choice_id');
    }

    /** 0 berarti tanpa batas pengisian. */
    public function responseLimit(): int
    {
        return max(0, (int) $this->limit_responses);
    }

    /** Hasil boleh dilihat siswa sekarang? (§38) */
    public function resultsVisible(?bool $hasResponded): bool
    {
        return match ($this->results_visibility) {
            ChoiceResultsVisibility::ALWAYS => true,
            ChoiceResultsVisibility::NEVER => false,
            ChoiceResultsVisibility::AFTER_RESPONSE => (bool) $hasResponded,
            ChoiceResultsVisibility::AFTER_CLOSE => $this->activity?->close_at?->isPast() ?? false,
        };
    }
}
