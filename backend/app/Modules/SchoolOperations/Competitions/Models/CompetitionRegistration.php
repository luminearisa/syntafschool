<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionRegistration — tabel `competition_registrations`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionRegistration extends Model
{
    use SoftDeletes;

    protected $table = 'competition_registrations';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fee_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'submitted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionCategory(): BelongsTo
    {
        return $this->belongsTo(CompetitionCategory::class, 'category_id');
    }

    public function competitionEdition(): BelongsTo
    {
        return $this->belongsTo(CompetitionEdition::class, 'edition_id');
    }

    public function competitionRegistrationForm(): BelongsTo
    {
        return $this->belongsTo(CompetitionRegistrationForm::class, 'form_id');
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionRegistrationValues(): HasMany
    {
        return $this->hasMany(CompetitionRegistrationValue::class);
    }

    public function competitionRegistrationDocuments(): HasMany
    {
        return $this->hasMany(CompetitionRegistrationDocument::class, 'registration_id');
    }

    public function competitionRegistrationStatusHistorys(): HasMany
    {
        return $this->hasMany(CompetitionRegistrationStatusHistory::class, 'registration_id');
    }

    public function competitionConsents(): HasMany
    {
        return $this->hasMany(CompetitionConsent::class, 'registration_id');
    }

    public function competitionEligibilityEvaluations(): HasMany
    {
        return $this->hasMany(CompetitionEligibilityEvaluation::class, 'registration_id');
    }

    public function competitionParticipants(): HasMany
    {
        return $this->hasMany(CompetitionParticipant::class, 'registration_id');
    }

    public function competitionWaitlists(): HasMany
    {
        return $this->hasMany(CompetitionWaitlist::class, 'registration_id');
    }
}
