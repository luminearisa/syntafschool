<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionEdition — tabel `competition_editions`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionEdition extends Model
{
    use SoftDeletes;

    protected $table = 'competition_editions';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'registration_opens' => 'datetime',
            'registration_closes' => 'datetime',
            'technical_meeting_at' => 'datetime',
            'allows_external' => 'boolean',
            'allows_internal' => 'boolean',
            'requires_parent_consent' => 'boolean',
            'requires_payment' => 'boolean',
            'requires_eligibility' => 'boolean',
            'requires_selection' => 'boolean',
            'requires_documents' => 'boolean',
            'published_at' => 'datetime',
            'locked_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function competitionLevel(): BelongsTo
    {
        return $this->belongsTo(CompetitionLevel::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionEditionDivisions(): HasMany
    {
        return $this->hasMany(CompetitionEditionDivision::class);
    }

    public function competitionCategorys(): HasMany
    {
        return $this->hasMany(CompetitionCategory::class);
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

    public function competitionRounds(): HasMany
    {
        return $this->hasMany(CompetitionRound::class);
    }

    public function competitionSessions(): HasMany
    {
        return $this->hasMany(CompetitionSession::class);
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

    public function competitionAppealCommittees(): HasMany
    {
        return $this->hasMany(CompetitionAppealCommittee::class);
    }

    public function competitionAppeals(): HasMany
    {
        return $this->hasMany(CompetitionAppeal::class);
    }

    public function competitionIncidents(): HasMany
    {
        return $this->hasMany(CompetitionIncident::class);
    }

    public function competitionOperationsChecklists(): HasMany
    {
        return $this->hasMany(CompetitionOperationsChecklist::class);
    }

    public function competitionBudgetItems(): HasMany
    {
        return $this->hasMany(CompetitionBudgetItem::class);
    }

    public function competitionSponsors(): HasMany
    {
        return $this->hasMany(CompetitionSponsor::class);
    }

    public function competitionResourceRequests(): HasMany
    {
        return $this->hasMany(CompetitionResourceRequest::class);
    }

    public function competitionReports(): HasMany
    {
        return $this->hasMany(CompetitionReport::class);
    }

    public function competitionKpis(): HasMany
    {
        return $this->hasMany(CompetitionKpi::class);
    }

    public function competitionPublicPages(): HasMany
    {
        return $this->hasMany(CompetitionPublicPage::class);
    }
}
