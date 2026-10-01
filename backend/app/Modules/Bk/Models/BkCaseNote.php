<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\NoteType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan kasus, termasuk catatan sensitif konselor.

Catatan dengan level COUNSELOR_ONLY ke atas hanya dapat dibaca konselor
penanggung jawab, supervisor, dan administrator BK — bukan guru, siswa, maupun
orang tua.
 *
 * Tabel: `bk_case_notes`
 */
class BkCaseNote extends BkModel
{
    protected $table = 'bk_case_notes';

    use SoftDeletes;

    protected $casts = [
        'occurred_at' => 'datetime',
        'visible_to' => 'array',
        'is_sensitive' => 'boolean',
        'note_type' => \App\Modules\Bk\Domain\Enums\NoteType::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BkCounselingSession::class, 'session_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function amendedFrom(): BelongsTo
    {
        return $this->belongsTo(BkCaseNote::class, 'amended_from_id');
    }
}
