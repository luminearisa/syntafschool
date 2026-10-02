<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionCategory — tabel `competition_categories`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionCategory extends Model
{
    use SoftDeletes;

    protected $table = 'competition_categories';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'registration_fee' => 'decimal:2',
            'tie_break_strategy' => 'array',
            'award_configuration' => 'array',
            'requires_submission' => 'boolean',
            'requires_judge' => 'boolean',
            'requires_documents' => 'boolean',
            'requires_parent_consent' => 'boolean',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionDivision(): BelongsTo
    {
        return $this->belongsTo(CompetitionDivision::class);
    }

    public function competitionEdition(): BelongsTo
    {
        return $this->belongsTo(CompetitionEdition::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionCategoryEligibilityRules(): HasMany
    {
        return $this->hasMany(CompetitionCategoryEligibilityRule::class);
    }

    public function competitionRules(): HasMany
    {
        return $this->hasMany(CompetitionRule::class);
    }

    public function competitionRubrics(): HasMany
    {
        return $this->hasMany(CompetitionRubric::class);
    }

    public function competitionRegistrationForms(): HasMany
    {
        return $this->hasMany(CompetitionRegistrationForm::class);
    }

    public function competitionDocumentRequirements(): HasMany
    {
        return $this->hasMany(CompetitionDocumentRequirement::class);
    }

    public function competitionRegistrations(): HasMany
    {
        return $this->hasMany(CompetitionRegistration::class);
    }

    public function competitionTeams(): HasMany
    {
        return $this->hasMany(CompetitionTeam::class);
    }

    public function competitionParticipants(): HasMany
    {
        return $this->hasMany(CompetitionParticipant::class);
    }

    public function competitionSelections(): HasMany
    {
        return $this->hasMany(CompetitionSelection::class);
    }

    public function competitionWaitlists(): HasMany
    {
        return $this->hasMany(CompetitionWaitlist::class);
    }

    public function competitionRounds(): HasMany
    {
        return $this->hasMany(CompetitionRound::class);
    }

    public function competitionSessions(): HasMany
    {
        return $this->hasMany(CompetitionSession::class);
    }

    public function competitionMatchs(): HasMany
    {
        return $this->hasMany(CompetitionMatch::class);
    }

    public function competitionCheckins(): HasMany
    {
        return $this->hasMany(CompetitionCheckin::class);
    }

    public function technicalMeetings(): HasMany
    {
        return $this->hasMany(TechnicalMeeting::class);
    }

    public function competitionJudgeAssignments(): HasMany
    {
        return $this->hasMany(CompetitionJudgeAssignment::class);
    }

    public function competitionPenaltys(): HasMany
    {
        return $this->hasMany(CompetitionPenalty::class);
    }

    public function competitionTieBreaks(): HasMany
    {
        return $this->hasMany(CompetitionTieBreak::class);
    }

    public function competitionSubmissions(): HasMany
    {
        return $this->hasMany(CompetitionSubmission::class);
    }

    public function competitionResults(): HasMany
    {
        return $this->hasMany(CompetitionResult::class);
    }

    public function competitionRankings(): HasMany
    {
        return $this->hasMany(CompetitionRanking::class);
    }

    public function competitionDisqualifications(): HasMany
    {
        return $this->hasMany(CompetitionDisqualification::class);
    }

    public function competitionAwards(): HasMany
    {
        return $this->hasMany(CompetitionAward::class);
    }

    public function competitionCertificates(): HasMany
    {
        return $this->hasMany(CompetitionCertificate::class);
    }

    public function competitionProtests(): HasMany
    {
        return $this->hasMany(CompetitionProtest::class);
    }

    public function competitionIncidents(): HasMany
    {
        return $this->hasMany(CompetitionIncident::class);
    }

    public function competitionBudgetItems(): HasMany
    {
        return $this->hasMany(CompetitionBudgetItem::class);
    }

    public function competitionResourceRequests(): HasMany
    {
        return $this->hasMany(CompetitionResourceRequest::class);
    }

    public function competitionKpis(): HasMany
    {
        return $this->hasMany(CompetitionKpi::class);
    }
}
