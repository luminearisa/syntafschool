<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen persyaratan pendaftaran (scoped: periode / program / gelombang).
 */
class PpdbDocumentRequirement extends BaseModel
{
    use LogsPpdbActivity;

    protected $casts = [
        'required' => 'boolean',
        'active' => 'boolean',
        'order' => 'integer',
        'max_size_kb' => 'integer',
        'min_size_kb' => 'integer',
    ];

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

    public function documents(): HasMany
    {
        return $this->hasMany(PpdbApplicantDocument::class, 'requirement_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Dokumen yang BERLAKU untuk kombinasi program & gelombang tertentu.
     *
     * Inklusif: dokumen tanpa scope (wajib semua program) selalu ikut.
     */
    public function scopeForScope(Builder $query, ?int $programId, ?int $waveId): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('program_id')->orWhere('program_id', $programId))
            ->where(fn (Builder $q) => $q->whereNull('wave_id')->orWhere('wave_id', $waveId));
    }

    /** Daftar ekstensi yang diizinkan. */
    public function allowedExtensions(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->file_types))));
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_DOCUMENT_REQUIREMENT';
    }
}