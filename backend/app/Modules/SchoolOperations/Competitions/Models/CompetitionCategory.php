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
        return $this->belongsTo(CompetitionDivision::class, 'competition_division_id');
    }

    public function competitionEdition(): BelongsTo
    {
        return $this->belongsTo(CompetitionEdition::class, 'edition_id');
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionCategoryEligibilityRules(): HasMany
    {
        return $this->hasMany(CompetitionCategoryEligibilityRule::class, 'category_id');
    }

    public function competitionRules(): HasMany
    {
        return $this->hasMany(CompetitionRule::class, 'category_id');
    }

    public function competitionRubrics(): HasMany
    {
        return $this->hasMany(CompetitionRubric::class, 'category_id');
    }

    public function competitionRegistrationForms(): HasMany
    {
        return $this->hasMany(CompetitionRegistrationForm::class, 'category_id');
    }

    public function competitionDocumentRequirements(): HasMany
    {
        return $this->hasMany(CompetitionDocumentRequirement::class, 'category_id');
    }

    public function competitionRegistrations(): HasMany
    {
        return $this->hasMany(CompetitionRegistration::class, 'category_id');
    }

    public function competitionTeams(): HasMany
    {
        return $this->hasMany(CompetitionTeam::class, 'category_id');
    }

    public function competitionParticipants(): HasMany
    {
        return $this->hasMany(CompetitionParticipant::class, 'category_id');
    }

    public function competitionSelections(): HasMany
    {
        return $this->hasMany(CompetitionSelection::class, 'category_id');
    }

    public function competitionWaitlists(): HasMany
    {
        return $this->hasMany(CompetitionWaitlist::class, 'category_id');
    }

    public function competitionRounds(): HasMany
    {
        return $this->hasMany(CompetitionRound::class, 'category_id');
    }

    public function competitionSessions(): HasMany
    {
        return $this->hasMany(CompetitionSession::class, 'category_id');
    }

    public function competitionMatchs(): HasMany
    {
        return $this->hasMany(CompetitionMatch::class, 'category_id');
    }

    public function competitionCheckins(): HasMany
    {
        return $this->hasMany(CompetitionCheckin::class, 'category_id');
    }

    public function technicalMeetings(): HasMany
    {
        return $this->hasMany(TechnicalMeeting::class, 'category_id');
    }

    public function competitionJudgeAssignments(): HasMany
    {
        return $this->hasMany(CompetitionJudgeAssignment::class, 'category_id');
    }

    public function competitionPenaltys(): HasMany
    {
        return $this->hasMany(CompetitionPenalty::class, 'category_id');
    }

    public function competitionTieBreaks(): HasMany
    {
        return $this->hasMany(CompetitionTieBreak::class, 'category_id');
    }

    public function competitionSubmissions(): HasMany
    {
        return $this->hasMany(CompetitionSubmission::class, 'category_id');
    }

    public function competitionResults(): HasMany
    {
        return $this->hasMany(CompetitionResult::class, 'category_id');
    }

    public function competitionRankings(): HasMany
    {
        return $this->hasMany(CompetitionRanking::class, 'category_id');
    }

    public function competitionDisqualifications(): HasMany
    {
        return $this->hasMany(CompetitionDisqualification::class, 'category_id');
    }

    public function competitionAwards(): HasMany
    {
        return $this->hasMany(CompetitionAward::class, 'category_id');
    }

    public function competitionCertificates(): HasMany
    {
        return $this->hasMany(CompetitionCertificate::class, 'category_id');
    }

    public function competitionProtests(): HasMany
    {
        return $this->hasMany(CompetitionProtest::class, 'category_id');
    }

    public function competitionIncidents(): HasMany
    {
        return $this->hasMany(CompetitionIncident::class, 'category_id');
    }

    public function competitionBudgetItems(): HasMany
    {
        return $this->hasMany(CompetitionBudgetItem::class, 'category_id');
    }

    public function competitionResourceRequests(): HasMany
    {
        return $this->hasMany(CompetitionResourceRequest::class, 'category_id');
    }

    public function competitionKpis(): HasMany
    {
        return $this->hasMany(CompetitionKpi::class, 'category_id');
    }
}
