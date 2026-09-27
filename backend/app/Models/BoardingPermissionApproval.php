<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu langkah persetujuan izin keluar (§27).
 *
 * `step` unik per izin di level database, jadi dua petugas tidak bisa menyetujui
 * langkah yang sama dua kali — alasan dua langkah berturut-turut oleh orang
 * yang sama pun ditolak di service.
 */
class BoardingPermissionApproval extends BaseModel
{
    use LogsBoardingActivity;

    public const ACTION_APPROVED = 'approved';

    public const ACTION_REJECTED = 'rejected';

    public const ACTIONS = [self::ACTION_APPROVED, self::ACTION_REJECTED];

    public const ACTION_LABELS = [
        self::ACTION_APPROVED => 'Disetujui',
        self::ACTION_REJECTED => 'Ditolak',
    ];

    protected $casts = [
        'step' => 'integer',
        'acted_at' => 'datetime',
    ];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(BoardingPermission::class, 'permission_id');
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function scopeForStep(Builder $query, ?int $step): Builder
    {
        return $step === null ? $query : $query->where('step', $step);
    }

    public function isApproved(): bool
    {
        return $this->action === self::ACTION_APPROVED;
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_PERMISSION_APPROVAL';
    }
}
