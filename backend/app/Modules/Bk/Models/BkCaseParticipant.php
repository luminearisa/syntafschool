<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\CaseParticipantRole;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pihak yang terlibat pada kasus — termasuk guru/wali kelas yang
hanya diberi akses sebagian (`can_view_case` + `max_confidentiality`).
 *
 * Tabel: `bk_case_participants`
 */
class BkCaseParticipant extends BkModel
{
    protected $table = 'bk_case_participants';

    protected $casts = [
        'can_view_case' => 'boolean',
        'max_confidentiality' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
        'role' => \App\Modules\Bk\Domain\Enums\CaseParticipantRole::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
