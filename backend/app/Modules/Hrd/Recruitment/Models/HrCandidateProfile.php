<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrPerson;
use App\Modules\Hrd\Recruitment\Models\HrCandidateApplication;
use App\Modules\Hrd\Recruitment\Models\HrCandidateDocument;

/**
 * Phase 2 — tabel `hr_candidate_profiles` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrCandidateProfile extends HrdModel
{
    protected $table = 'hr_candidate_profiles';

    protected $guarded = [];
    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'total_experience_years' => 'decimal:2',
        'expected_salary' => 'decimal:2',
        'skills' => 'array',
        'certifications' => 'array',
        'languages' => 'array',
        'is_talent_pool' => 'boolean',
        'parsed_resume' => 'array',
        'parsed_reviewed' => 'boolean',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(HrPerson::class, 'person_id');
    }
    public function applications(): HasMany
    {
        return $this->hasMany(HrCandidateApplication::class, 'candidate_id');
    }
    public function documents(): HasMany
    {
        return $this->hasMany(HrCandidateDocument::class, 'candidate_id');
    }
}
