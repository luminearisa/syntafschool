<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pertanyaan tambahan pada pendaftaran.
 *
 * Scope: global periode (program_id & wave_id kosong), per program, atau per
 * gelombang. Sistem hanya menampilkan pertanyaan yang relevan bagi pilihan
 * calon (program/gelombang).
 */
class PpdbQuestion extends BaseModel
{
    use LogsPpdbActivity;

    public const TYPES = [
        'text', 'textarea', 'number', 'date', 'yes_no',
        'single_choice', 'multiple_choice', 'dropdown', 'file',
    ];

    protected $casts = ['required' => 'boolean', 'active' => 'boolean', 'order' => 'integer'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PpdbPeriod::class, 'period_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(PpdbProgram::class, 'program_id');
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(PpdbWave::class, 'wave_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(PpdbQuestionOption::class, 'question_id')->orderBy('order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Pertanyaan yang BERLAKU untuk kombinasi program & gelombang tertentu.
     *
     * Bersifat inklusif: aturan tanpa scope (global periode) selalu ikut, dan
     * aturan yang menarget program/gelombang lain dikecualikan.
     */
    public function scopeForScope(Builder $query, ?int $programId, ?int $waveId): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('program_id')->orWhere('program_id', $programId))
            ->where(fn (Builder $q) => $q->whereNull('wave_id')->orWhere('wave_id', $waveId));
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_QUESTION';
    }
}