<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Jejak audit BK — append-only, tanpa isi catatan.

Yang dicatat adalah **metadata akses**: siapa, kapan, aksi apa, pada entitas mana,
dengan level kerahasiaan berapa, dan alasan bila diberikan.
 *
 * Tabel: `bk_audit_logs`
 */
class BkAuditLog extends BkModel
{
    protected $table = 'bk_audit_logs';

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
        'action' => \App\Modules\Bk\Domain\Enums\AuditAction::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
