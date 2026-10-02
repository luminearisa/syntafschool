<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dokumen pegawai (§31, §32).
 *
 * Berkas fisiknya ada di `hr_employee_document_versions`; baris ini menyimpan
 * metadata, masa berlaku, verifikasi, dan tingkat kerahasiaan. Versi lama tidak
 * pernah ditimpa.
 */
class HrEmployeeDocument extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'issue_date' => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
        'verified' => 'boolean',
        'verified_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'current_version' => 'integer',
    ];

    /** Kerahasiaan: makin kecil cakupannya, makin ketat pembacaannya (§52). */
    public const VISIBILITY_RANK = [
        'private' => 0,
        'hr' => 1,
        'manager' => 2,
        'employee' => 3,
        'public' => 4,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(HrDocumentType::class, 'document_type_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(HrEmployeeDocumentVersion::class, 'document_id')->orderByDesc('version');
    }

    public function currentVersionRecord(): HasMany
    {
        return $this->versions()->where('is_current', true);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')->where('expiry_date', '<', now()->toDateString());
    }
}
