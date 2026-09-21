<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Calon peserta didik (anak) milik satu akun orang tua.
 *
 * Applicant BUKAN Student: record siswa baru dibuat setelah daftar ulang
 * selesai (lihat konversi applicant → student).
 */
class PpdbApplicant extends BaseModel
{
    use LogsPpdbActivity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $casts = ['birth_date' => 'date:Y-m-d'];

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(PpdbParentAccount::class, 'parent_account_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class);
    }

    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    public function targetProgram(): BelongsTo
    {
        return $this->belongsTo(PpdbProgram::class, 'target_program_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(PpdbRegistration::class, 'applicant_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PpdbApplicantDocument::class, 'applicant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_APPLICANT';
    }
}